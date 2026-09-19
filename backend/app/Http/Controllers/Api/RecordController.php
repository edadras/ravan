<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSuggestion;
use App\Models\DiagnosisCode;
use App\Models\PatientRecord;
use App\Models\TherapySession;
use App\Models\User;
use App\Notifications\ScreeningFlagNotification;
use App\Services\AuditLogger;
use App\Services\ScreeningScorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The psychological / psychiatric record: intake, SOAP entries, diagnoses, screenings, medications.
 * Every read is access-logged; every write is audit-logged; AI never writes here directly.
 */
class RecordController extends Controller
{
    public function __construct(protected AuditLogger $audit, protected ScreeningScorer $scorer) {}

    protected function recordFor(User $patient): PatientRecord
    {
        return PatientRecord::firstOrCreate(['patient_id' => $patient->id]);
    }

    /** GET /records/{patient} — full record for clinicians, summary for the patient. */
    public function show(Request $request, User $patient): JsonResponse
    {
        $record = $this->recordFor($patient);
        $this->authorize('view', $record);
        $this->audit->log($request->user(), 'record.viewed', $record);
        $record->load(['diagnoses.code', 'entries' => fn ($q) => $q->limit(50), 'screenings' => fn ($q) => $q->limit(50), 'medications', 'primaryClinician:id,name']);
        if ($request->user()->id === $patient->id) {
            // Patient view: no clinician-private assessment text, no AI suggestions.
            $data = $record->only(['id', 'chief_complaint', 'history_of_present_illness', 'medical_history', 'family_history', 'social_history', 'substance_use', 'current_medications', 'allergies', 'goals', 'status', 'primary_clinician']);
            $data['diagnoses'] = $record->diagnoses->where('status', 'confirmed')->map(fn ($d) => ['label' => $d->label, 'code' => $d->code?->code, 'status' => $d->status, 'confirmed_at' => $d->confirmed_at])->values();
            $data['screenings'] = $record->screenings->map(fn ($s) => $s->only(['instrument', 'total_score', 'severity_band', 'created_at']))->values();
            $data['medications'] = $record->medications;

            return response()->json($data);
        }

        return response()->json($record->loadCount('aiSuggestions'));
    }

    /** Intake: the patient (or clinician) fills the history sections. */
    public function updateIntake(Request $request, User $patient): JsonResponse
    {
        $record = $this->recordFor($patient);
        $this->authorize('updateIntake', $record);
        $data = $request->validate([
            'chief_complaint' => ['nullable', 'string', 'max:5000'],
            'history_of_present_illness' => ['nullable', 'string', 'max:10000'],
            'psychiatric_history' => ['nullable', 'string', 'max:10000'],
            'medical_history' => ['nullable', 'string', 'max:10000'],
            'family_history' => ['nullable', 'string', 'max:10000'],
            'social_history' => ['nullable', 'string', 'max:10000'],
            'substance_use' => ['nullable', 'string', 'max:5000'],
            'current_medications' => ['nullable', 'array'],
            'allergies' => ['nullable', 'array'],
            'goals' => ['nullable', 'array'],
        ]);
        $record->update($data);
        $this->audit->log($request->user(), 'record.intake_updated', $record, ['fields' => array_keys($data)]);

        return response()->json($record);
    }

    /** Clinician-only sections. */
    public function updateClinical(Request $request, User $patient): JsonResponse
    {
        $record = $this->recordFor($patient);
        $this->authorize('update', $record);
        $data = $request->validate([
            'risk_history' => ['nullable', 'string', 'max:10000'],
            'formulation' => ['nullable', 'string', 'max:20000'],
            'treatment_plan' => ['nullable', 'string', 'max:20000'],
            'status' => ['nullable', Rule::in(['active', 'closed', 'transferred'])],
            'primary_clinician_id' => ['nullable', 'exists:users,id'],
        ]);
        if (! $record->primary_clinician_id) {
            $data['primary_clinician_id'] = $data['primary_clinician_id'] ?? $request->user()->id;
        }
        $record->update($data);
        $this->audit->log($request->user(), 'record.clinical_updated', $record, ['fields' => array_keys($data)]);

        return response()->json($record);
    }

    public function addEntry(Request $request, User $patient): JsonResponse
    {
        $record = $this->recordFor($patient);
        $this->authorize('update', $record);
        $data = $request->validate([
            'therapy_session_uuid' => ['nullable', 'exists:therapy_sessions,uuid'],
            'type' => ['required', Rule::in(['intake', 'progress', 'discharge', 'phone', 'other'])],
            'subjective' => ['nullable', 'string', 'max:20000'],
            'objective' => ['nullable', 'string', 'max:20000'],
            'assessment' => ['nullable', 'string', 'max:20000'],
            'plan' => ['nullable', 'string', 'max:20000'],
            'mental_status_exam' => ['nullable', 'array'],
            'lock' => ['nullable', 'boolean'],
        ]);
        $sessionId = isset($data['therapy_session_uuid']) ? TherapySession::where('uuid', $data['therapy_session_uuid'])->value('id') : null;
        $entry = $record->entries()->create(collect($data)->except(['therapy_session_uuid', 'lock'])->toArray() + [
            'therapy_session_id' => $sessionId, 'author_id' => $request->user()->id, 'is_locked' => (bool) ($data['lock'] ?? false),
        ]);
        $this->audit->log($request->user(), 'record.entry_added', $entry);

        return response()->json($entry, 201);
    }

    public function addDiagnosis(Request $request, User $patient): JsonResponse
    {
        $record = $this->recordFor($patient);
        $this->authorize('update', $record);
        $data = $request->validate([
            'diagnosis_code_id' => ['nullable', 'exists:diagnosis_codes,id'],
            'label' => ['required_without:diagnosis_code_id', 'nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['provisional', 'confirmed', 'ruled_out', 'resolved'])],
            'evidence' => ['nullable', 'string', 'max:10000'],
            'onset_date' => ['nullable', 'date'],
            'ai_suggestion_uuid' => ['nullable', 'exists:ai_suggestions,uuid'],
        ]);
        $code = isset($data['diagnosis_code_id']) ? DiagnosisCode::find($data['diagnosis_code_id']) : null;
        $ai = isset($data['ai_suggestion_uuid']) ? AiSuggestion::where('uuid', $data['ai_suggestion_uuid'])->first() : null;
        $dx = $record->diagnoses()->create([
            'clinician_id' => $request->user()->id,
            'diagnosis_code_id' => $code?->id,
            'label' => $data['label'] ?? $code->{'label_'.app()->getLocale()} ?? $code->label_en,
            'status' => $data['status'],
            'source' => $ai ? 'ai_suggested' : 'clinician',
            'ai_suggestion_id' => $ai?->id,
            'evidence' => $data['evidence'] ?? null,
            'onset_date' => $data['onset_date'] ?? null,
            'confirmed_at' => $data['status'] === 'confirmed' ? now() : null,
        ]);
        $this->audit->log($request->user(), 'record.diagnosis_added', $dx, ['status' => $data['status'], 'source' => $dx->source]);

        return response()->json($dx->load('code'), 201);
    }

    public function updateDiagnosis(Request $request, User $patient, int $diagnosis): JsonResponse
    {
        $record = $this->recordFor($patient);
        $this->authorize('update', $record);
        $dx = $record->diagnoses()->findOrFail($diagnosis);
        $data = $request->validate(['status' => ['required', Rule::in(['provisional', 'confirmed', 'ruled_out', 'resolved'])], 'evidence' => ['nullable', 'string', 'max:10000']]);
        $dx->update($data + ($data['status'] === 'confirmed' ? ['confirmed_at' => now()] : []));
        $this->audit->log($request->user(), 'record.diagnosis_updated', $dx, ['status' => $data['status']]);

        return response()->json($dx->load('code'));
    }

    /** Patient answers a screening instrument; scored server-side; item flags alert the clinician. */
    public function addScreening(Request $request, User $patient): JsonResponse
    {
        $record = $this->recordFor($patient);
        $this->authorize('updateIntake', $record);
        $data = $request->validate([
            'instrument' => ['required', Rule::in(array_keys(ScreeningScorer::INSTRUMENTS))],
            'answers' => ['required', 'array'], 'answers.*' => ['integer', 'min:0', 'max:4'],
            'therapy_session_uuid' => ['nullable', 'exists:therapy_sessions,uuid'],
        ]);
        $score = $this->scorer->score($data['instrument'], $data['answers']);
        $res = $record->screenings()->create([
            'instrument' => $data['instrument'], 'answers' => $data['answers'], 'total_score' => $score['total'],
            'severity_band' => $score['band'], 'item_flag' => $score['item_flag'], 'administered_by' => $request->user()->id,
            'therapy_session_id' => isset($data['therapy_session_uuid']) ? TherapySession::where('uuid', $data['therapy_session_uuid'])->value('id') : null,
        ]);
        if ($score['item_flag'] && $record->primary_clinician_id) {
            $record->primaryClinician?->notify(new ScreeningFlagNotification($res));
        }
        $this->audit->log($request->user(), 'record.screening_added', $res, ['instrument' => $data['instrument'], 'flag' => $score['item_flag']]);

        return response()->json($res, 201);
    }

    public function addMedication(Request $request, User $patient): JsonResponse
    {
        $record = $this->recordFor($patient);
        $this->authorize('update', $record);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'dose' => ['nullable', 'string', 'max:64'], 'frequency' => ['nullable', 'string', 'max:64'],
            'started_at' => ['nullable', 'date'], 'stopped_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $med = $record->medications()->create($data + ['prescriber_id' => $request->user()->id]);
        $this->audit->log($request->user(), 'record.medication_added', $med);

        return response()->json($med, 201);
    }

    public function grantAccess(Request $request, User $patient): JsonResponse
    {
        $record = $this->recordFor($patient);
        $this->authorize('update', $record);
        $data = $request->validate(['clinician_id' => ['required', 'exists:users,id'], 'expires_at' => ['nullable', 'date', 'after:now']]);
        $grant = $record->accessGrants()->updateOrCreate(['clinician_id' => $data['clinician_id']], ['granted_by' => $request->user()->id, 'expires_at' => $data['expires_at'] ?? null]);
        $this->audit->log($request->user(), 'record.access_granted', $grant);

        return response()->json($grant, 201);
    }

    public function diagnosisCodes(Request $request): JsonResponse
    {
        $q = $request->string('q')->toString();
        $col = 'label_'.app()->getLocale();

        return response()->json(DiagnosisCode::query()
            ->when($q, fn ($b) => $b->where(fn ($w) => $w->where($col, 'like', "%{$q}%")->orWhere('label_en', 'like', "%{$q}%")->orWhere('code', 'like', "{$q}%")))
            ->orderBy('system')->orderBy('code')->limit(100)->get());
    }

    public function instruments(): JsonResponse
    {
        return response()->json(collect(ScreeningScorer::INSTRUMENTS)->map(fn ($s, $k) => ['id' => $k, 'items' => $s['items'], 'max' => $s['max'], 'questions' => __("instruments.$k")])->values());
    }
}
