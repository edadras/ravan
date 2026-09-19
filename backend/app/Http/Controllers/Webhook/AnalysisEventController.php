<?php

namespace App\Http\Controllers\Webhook;

use App\Events\BehaviorEventCreated;
use App\Http\Controllers\Controller;
use App\Models\BehaviorEvent;
use App\Models\TherapySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives signed behaviour events from the analysis service, stores them, links them to the
 * nearest transcript segment and pushes them to the clinician's live timeline.
 */
class AnalysisEventController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'session_id' => ['required', 'uuid'],
            'signal_id' => ['required', 'string', 'max:80'],
            'group' => ['required', 'string', 'max:48'],
            'tier' => ['required', 'string', 'max:24'],
            't_start_ms' => ['required', 'integer', 'min:0'],
            't_end_ms' => ['required', 'integer', 'min:0'],
            'observation' => ['required', 'array'],
            'observation.en' => ['required', 'string'],
            'observation.fa' => ['required', 'string'],
            'baseline_value' => ['nullable', 'numeric'],
            'observed_value' => ['nullable', 'numeric'],
            'delta' => ['nullable', 'numeric'],
            'delta_ratio' => ['nullable', 'numeric'],
            'z_score' => ['nullable', 'numeric'],
            'unit' => ['nullable', 'string', 'max:24'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'quality' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
            'possible_contexts' => ['required', 'array'],
            'clinical_rationale' => ['nullable', 'array'],
            'clinical_note' => ['nullable', 'array'],
            'member_events' => ['nullable', 'array'],
        ]);
        abort_if($request->input('diagnostic_claim') !== null, 422, 'diagnostic claims are not accepted');

        $session = TherapySession::where('uuid', $data['session_id'])->firstOrFail();
        if (! $session->analysis_enabled && $data['tier'] !== 'quality') {
            // Consent was paused/withdrawn between emission and delivery: drop silently.
            return response()->json(['stored' => false, 'reason' => 'analysis disabled'], 202);
        }

        $segment = $session->transcriptSegments()
            ->where('t_start_ms', '<=', $data['t_end_ms'])
            ->where('t_end_ms', '>=', $data['t_start_ms'] - 5000)
            ->reorder()->orderByDesc('t_start_ms')->first();

        $event = BehaviorEvent::updateOrCreate(['uuid' => $data['id']], [
            'therapy_session_id' => $session->id,
            'patient_id' => $session->patient_id,
            'signal_id' => $data['signal_id'],
            'group' => $data['group'],
            'tier' => $data['tier'],
            't_start_ms' => $data['t_start_ms'],
            't_end_ms' => $data['t_end_ms'],
            'observation_en' => $data['observation']['en'],
            'observation_fa' => $data['observation']['fa'],
            'baseline_value' => $data['baseline_value'] ?? null,
            'observed_value' => $data['observed_value'] ?? null,
            'delta' => $data['delta'] ?? null,
            'delta_ratio' => $data['delta_ratio'] ?? null,
            'z_score' => $data['z_score'] ?? null,
            'unit' => $data['unit'] ?? null,
            'confidence' => $data['confidence'],
            'quality' => $data['quality'] ?? [],
            'context' => $data['context'] ?? [],
            'possible_contexts' => $data['possible_contexts'],
            'clinical_rationale_en' => $data['clinical_rationale']['en'] ?? null,
            'clinical_rationale_fa' => $data['clinical_rationale']['fa'] ?? null,
            'clinical_note_en' => $data['clinical_note']['en'] ?? null,
            'clinical_note_fa' => $data['clinical_note']['fa'] ?? null,
            'member_event_uuids' => $data['member_events'] ?? [],
            'transcript_segment_id' => $segment?->id,
        ]);
        BehaviorEventCreated::dispatch($event);

        return response()->json(['stored' => true, 'uuid' => $event->uuid], 201);
    }
}
