<?php

namespace App\Http\Controllers\Api;

use App\Enums\SessionStatus;
use App\Events\SessionStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\SessionParticipant;
use App\Models\SessionReport;
use App\Models\TherapySession;
use App\Services\AnalysisServiceClient;
use App\Services\AuditLogger;
use App\Services\ConsentService;
use App\Services\WebRtcTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SessionController extends Controller
{
    public function __construct(
        protected AnalysisServiceClient $analysis,
        protected ConsentService $consent,
        protected WebRtcTokenService $webrtc,
        protected AuditLogger $audit,
    ) {}

    public function show(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('view', $session);
        $data = $session->load(['patient:id,name', 'clinician:id,name', 'consents', 'participants'])->toArray();
        $data['consent_versions'] = config('ravan.consent.current_versions');
        $data['analysis_allowed'] = $this->consent->analysisAllowed($session);

        return response()->json($data);
    }

    /** Join: returns SFU credentials; marks participant; for the clinician also a live-feed channel name. */
    public function join(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('join', $session);
        $user = $request->user();
        abort_if(in_array($session->status, [SessionStatus::Ended, SessionStatus::Cancelled], true), 409, __('messages.session_closed'));

        SessionParticipant::updateOrCreate(
            ['therapy_session_id' => $session->id, 'user_id' => $user->id],
            ['role' => $user->id === $session->clinician_id ? 'clinician' : 'patient', 'joined_at' => now(), 'left_at' => null,
                'device_info' => $request->only(['browser', 'camera_resolution', 'fps'])],
        );
        if ($session->status === SessionStatus::Scheduled) {
            $session->update(['status' => SessionStatus::Waiting]);
        }
        if ($session->participants()->whereNotNull('joined_at')->whereNull('left_at')->count() >= 2 && $session->status !== SessionStatus::Live) {
            $session->update(['status' => SessionStatus::Live, 'started_at' => $session->started_at ?? now()]);
            SessionStatusChanged::dispatch($session, 'both_joined');
        }

        $payload = [
            'session' => $session->only(['uuid', 'mode', 'status', 'started_at', 'analysis_enabled', 'language']),
            'role' => $user->id === $session->clinician_id ? 'clinician' : 'patient',
            'channels' => [
                'shared' => "private-session.{$session->uuid}.".($user->id === $session->clinician_id ? 'clinician' : 'patient'),
            ],
            'analysis_allowed' => $this->consent->analysisAllowed($session),
        ];
        if ($session->mode->value !== 'text') {
            $payload['webrtc'] = $this->webrtc->issue($session, $user);
        }
        if ($user->id === $session->patient_id && $payload['analysis_allowed']) {
            // The patient client streams derived features straight to the analysis service (no raw media).
            $payload['analysis_ingest'] = [
                'ws_url' => rtrim(str_replace(['http://', 'https://'], ['ws://', 'wss://'], config('ravan.analysis.base_url')), '/')."/ws/sessions/{$session->uuid}",
                'token' => config('ravan.analysis.token'),
            ];
        }
        $this->audit->log($user, 'session.joined', $session);

        return response()->json($payload);
    }

    /** Start behavioural analysis for a live session. Requires both consents. */
    public function startAnalysis(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('join', $session);
        abort_unless($this->consent->analysisAllowed($session), 403, __('messages.analysis_consent_missing'));
        abort_unless($session->status === SessionStatus::Live, 409, __('messages.session_not_live'));
        if (! $session->analysis_session_ref) {
            try {
                $this->analysis->startSession($session);
            } catch (\Throwable $e) {
                Log::error('analysis start failed', ['session' => $session->uuid, 'err' => $e->getMessage()]);
                abort(502, __('messages.analysis_unavailable'));
            }
            $session->update(['analysis_session_ref' => $session->uuid, 'analysis_started_at' => now()]);
        } elseif ($session->analysis_paused_at) {
            $this->safeControl($session, 'analysis_resume');
        }
        $session->update(['analysis_enabled' => true, 'analysis_paused_at' => null]);
        SessionStatusChanged::dispatch($session, 'analysis_started');
        $this->audit->log($request->user(), 'analysis.started', $session);

        return response()->json(['analysis_enabled' => true]);
    }

    /** Patient-controlled pause: no features are computed or stored while paused; the call continues. */
    public function pauseAnalysis(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('join', $session);
        $this->safeControl($session, 'analysis_pause');
        $session->update(['analysis_enabled' => false, 'analysis_paused_at' => now()]);
        SessionStatusChanged::dispatch($session, 'analysis_paused');
        $this->audit->log($request->user(), 'analysis.paused', $session);

        return response()->json(['analysis_enabled' => false]);
    }

    public function clinicianMark(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('viewAnalysis', $session);
        $data = $request->validate(['t_ms' => ['required', 'integer', 'min:0'], 'note' => ['nullable', 'string', 'max:500']]);
        $this->safeControl($session, 'clinician_mark', $data);

        return response()->json(['ok' => true]);
    }

    public function topic(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('viewAnalysis', $session);
        $data = $request->validate(['topic' => ['required', 'string', 'max:64']]);
        $this->safeControl($session, 'topic_segment', $data);

        return response()->json(['ok' => true]);
    }

    public function end(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('end', $session);
        if ($session->status === SessionStatus::Ended) {
            return response()->json($session);
        }
        $session->participants()->whereNull('left_at')->update(['left_at' => now()]);
        $session->update([
            'status' => SessionStatus::Ended,
            'ended_at' => now(),
            'duration_s' => $session->started_at ? (int) $session->started_at->diffInSeconds(now()) : null,
            'analysis_enabled' => false,
        ]);
        if ($session->analysis_session_ref) {
            try {
                $report = $this->analysis->finish($session);
                SessionReport::updateOrCreate(['therapy_session_id' => $session->id], [
                    'structured' => $report,
                    'ai_draft_summary' => $report['ai_draft_summary']['text'] ?? null,
                    'ai_provider' => $report['ai_draft_summary']['provider'] ?? null,
                    'guardrail_removed' => $report['ai_draft_summary']['guardrail_removed'] ?? [],
                    'status' => 'draft',
                ]);
                $session->update(['baseline_summary' => $report['baseline'] ?? null, 'quality_summary' => $report['quality'] ?? null]);
            } catch (\Throwable $e) {
                Log::error('analysis finish failed', ['session' => $session->uuid, 'err' => $e->getMessage()]);
            }
        }
        $session->appointment?->update(['status' => 'completed']);
        SessionStatusChanged::dispatch($session, 'ended');
        $this->audit->log($request->user(), 'session.ended', $session);

        return response()->json($session->fresh());
    }

    protected function safeControl(TherapySession $session, string $action, array $payload = []): void
    {
        if (! $session->analysis_session_ref) {
            return;
        }
        try {
            $this->analysis->control($session, $action, $payload);
        } catch (\Throwable $e) {
            Log::warning('analysis control failed', ['action' => $action, 'session' => $session->uuid, 'err' => $e->getMessage()]);
        }
    }
}
