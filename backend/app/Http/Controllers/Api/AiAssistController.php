<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSuggestion;
use App\Models\PatientRecord;
use App\Models\TherapySession;
use App\Services\AiAssistClient;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Clinical decision support. Every output is stored as an AiSuggestion the clinician must
 * accept / edit / reject; nothing here writes to the record. Clinician-only.
 */
class AiAssistController extends Controller
{
    public function __construct(protected AiAssistClient $ai, protected AuditLogger $audit) {}

    protected function record(TherapySession $session): PatientRecord
    {
        return PatientRecord::firstOrCreate(['patient_id' => $session->patient_id]);
    }

    /** Case formulation draft + differential hypotheses with evidence for/against + suggested next steps. */
    public function formulation(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('viewAnalysis', $session);
        $record = $this->record($session);
        $this->authorize('useAi', $record);
        $session->load(['transcriptSegments', 'behaviorEvents', 'clinicalNotes', 'report', 'patient.patientProfile']);
        $record->load(['diagnoses.code', 'screenings', 'entries']);
        $out = $this->ai->formulation($session, $record, app()->getLocale());

        return response()->json($this->store($request, $session, $record, 'formulation', $out), 201);
    }

    /** Per question–answer pair: how the answer was given (latency, length, deflection, valence, co-occurring body/voice changes). */
    public function qaAnalysis(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('viewAnalysis', $session);
        $session->load(['transcriptSegments', 'behaviorEvents']);
        $out = $this->ai->qaAnalysis($session, app()->getLocale());

        return response()->json($this->store($request, $session, null, 'qa_analysis', $out), 201);
    }

    /** Free-form questions to the assistant about this session ("where did posture change most?"). */
    public function chat(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('viewAnalysis', $session);
        $data = $request->validate(['question' => ['required', 'string', 'max:2000'], 'history' => ['nullable', 'array', 'max:20']]);
        $record = $this->record($session);
        $session->load(['transcriptSegments', 'behaviorEvents', 'clinicalNotes', 'report', 'patient.patientProfile']);
        $record->load(['diagnoses.code', 'screenings', 'entries']);
        $out = $this->ai->chat($session, $record, $data['question'], $data['history'] ?? [], app()->getLocale());

        return response()->json($this->store($request, $session, $record, 'chat', $out, ['question' => $data['question']]), 201);
    }

    public function index(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('viewAnalysis', $session);

        return response()->json(AiSuggestion::where('therapy_session_id', $session->id)->orderByDesc('id')->paginate(50));
    }

    public function review(Request $request, AiSuggestion $suggestion): JsonResponse
    {
        $this->authorize('viewAnalysis', $suggestion->session);
        $data = $request->validate(['status' => ['required', Rule::in(['accepted', 'edited', 'rejected'])], 'clinician_response' => ['nullable', 'string', 'max:20000']]);
        $suggestion->update($data + ['reviewed_at' => now()]);
        $this->audit->log($request->user(), 'ai.suggestion_reviewed', $suggestion, ['kind' => $suggestion->kind, 'status' => $data['status']]);

        return response()->json($suggestion);
    }

    protected function store(Request $request, TherapySession $session, ?PatientRecord $record, string $kind, array $out, array $extraInput = []): AiSuggestion
    {
        $s = AiSuggestion::create([
            'therapy_session_id' => $session->id,
            'patient_record_id' => $record?->id,
            'requested_by' => $request->user()->id,
            'kind' => $kind,
            'input_summary' => ['transcript_segments' => $session->transcriptSegments->count(), 'events' => $session->behaviorEvents->count(), 'language' => app()->getLocale()] + $extraInput,
            'output' => $out['output'] ?? $out,
            'guardrail_removed' => $out['guardrail_removed'] ?? [],
            'provider' => $out['provider'] ?? 'unknown',
            'model' => $out['model'] ?? null,
            'status' => 'pending',
        ]);
        $this->audit->log($request->user(), 'ai.suggestion_created', $s, ['kind' => $kind, 'provider' => $s->provider]);

        return $s;
    }
}
