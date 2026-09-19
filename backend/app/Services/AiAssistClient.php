<?php

namespace App\Services;

use App\Models\PatientRecord;
use App\Models\TherapySession;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Calls the analysis service's clinical-assistant endpoints (which talk to OpenAI / Anthropic).
 * Only de-identified data leaves this backend: pseudonym, ages in years, transcript text, events,
 * clinician notes and screening scores. Never names, contact details or identifiers.
 */
class AiAssistClient
{
    protected function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('ravan.analysis.base_url'), '/'))
            ->timeout(90)->withToken((string) config('ravan.analysis.token'))->acceptJson();
    }

    public function formulation(TherapySession $session, PatientRecord $record, string $language): array
    {
        return $this->http()->post('/assist/formulation', $this->payload($session, $record, $language))->throw()->json();
    }

    public function qaAnalysis(TherapySession $session, string $language): array
    {
        return $this->http()->post('/assist/qa', ['session_id' => $session->uuid, 'language' => $language,
            'transcript' => $this->transcript($session), 'events' => $this->events($session)])->throw()->json();
    }

    public function chat(TherapySession $session, ?PatientRecord $record, string $question, array $history, string $language): array
    {
        $p = $record ? $this->payload($session, $record, $language) : ['session_id' => $session->uuid, 'language' => $language,
            'transcript' => $this->transcript($session), 'events' => $this->events($session)];

        return $this->http()->post('/assist/chat', $p + ['question' => $question, 'history' => $history])->throw()->json();
    }

    protected function payload(TherapySession $session, PatientRecord $record, string $language): array
    {
        $profile = $session->patient->patientProfile;

        return [
            'session_id' => $session->uuid,
            'language' => $language,
            'patient' => [
                'pseudonym' => $profile?->pseudonym,
                'age_years' => $profile?->birth_date?->age,
                'gender' => $profile?->gender,
            ],
            'record' => [
                'chief_complaint' => $record->chief_complaint,
                'history_of_present_illness' => $record->history_of_present_illness,
                'psychiatric_history' => $record->psychiatric_history,
                'medical_history' => $record->medical_history,
                'family_history' => $record->family_history,
                'social_history' => $record->social_history,
                'substance_use' => $record->substance_use,
                'current_medications' => $record->current_medications,
                'risk_history' => $record->risk_history,
                'diagnoses' => $record->diagnoses->map(fn ($d) => ['label' => $d->label, 'status' => $d->status, 'code' => $d->code?->code])->values(),
                'screenings' => $record->screenings->take(10)->map(fn ($s) => ['instrument' => $s->instrument, 'total' => $s->total_score, 'band' => $s->severity_band, 'at' => $s->created_at?->toDateString()])->values(),
                'recent_entries' => $record->entries->take(5)->map(fn ($e) => ['type' => $e->type, 'subjective' => $e->subjective, 'objective' => $e->objective, 'assessment' => $e->assessment, 'plan' => $e->plan, 'at' => $e->created_at?->toDateString()])->values(),
            ],
            'transcript' => $this->transcript($session),
            'events' => $this->events($session),
            'clinician_notes' => $session->clinicalNotes->map(fn ($n) => ['t_ms' => $n->t_ms, 'body' => $n->body])->values(),
            'report' => $session->report?->structured,
        ];
    }

    protected function transcript(TherapySession $session): array
    {
        return $session->transcriptSegments->map(fn ($s) => ['t_start_ms' => $s->t_start_ms, 't_end_ms' => $s->t_end_ms, 'speaker' => $s->speaker, 'text' => $s->text, 'is_question' => $s->is_question])->values()->all();
    }

    protected function events(TherapySession $session): array
    {
        return $session->behaviorEvents->where('tier', '!=', 'quality')->map(fn ($e) => [
            'id' => $e->uuid, 'signal_id' => $e->signal_id, 'group' => $e->group, 'tier' => $e->tier, 't_start_ms' => $e->t_start_ms, 't_end_ms' => $e->t_end_ms,
            'observation' => ['en' => $e->observation_en, 'fa' => $e->observation_fa, 'tr' => $e->observation_tr],
            'baseline_value' => $e->baseline_value, 'observed_value' => $e->observed_value, 'z_score' => $e->z_score, 'confidence' => $e->confidence,
            'context' => $e->context, 'clinician_status' => $e->clinician_status?->value,
        ])->values()->all();
    }
}
