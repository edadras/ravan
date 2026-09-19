<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConsentType;
use App\Events\SessionStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\ConsentVersion;
use App\Models\DataDeletionRequest;
use App\Models\TherapySession;
use App\Services\AnalysisServiceClient;
use App\Services\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConsentController extends Controller
{
    public function __construct(protected ConsentService $consent, protected AnalysisServiceClient $analysis) {}

    /** The current consent texts (shown before the camera is ever switched on). */
    public function texts(Request $request): JsonResponse
    {
        $locale = $request->string('locale', 'fa')->toString();
        $versions = config('ravan.consent.current_versions');
        $texts = ConsentVersion::where('locale', $locale)->where('is_current', true)->get()->keyBy('type');

        return response()->json(collect($versions)->map(function ($v, $type) use ($texts) {
            $body = $texts[$type]->body ?? '';
            $bullets = $texts[$type]->bullet_points ?? [];
            // The seeded text describes what transcription is for. Where the
            // audio actually goes depends on this server's configuration, so it
            // is added here rather than baked into the seed, and it can never
            // fall out of step with the engine that is really running.
            if ($type === ConsentType::Transcription->value && ($processor = $this->remoteProcessor()) !== null) {
                $body = trim($body."\n\n".__('messages.transcription_remote_processor', ['processor' => $processor]));
                $bullets = array_merge($bullets, [__('messages.transcription_remote_bullet', ['processor' => $processor])]);
            }

            return [
                'type' => $type, 'version' => $v,
                'title' => $texts[$type]->title ?? $type,
                'body' => $body,
                'bullet_points' => $bullets,
            ];
        })->values());
    }

    /** The third party that will hear the audio, or null when nothing leaves this server. */
    protected function remoteProcessor(): ?string
    {
        return config('ravan.asr.remote_backends')[config('ravan.asr.backend')] ?? null;
    }

    public function grant(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('manageConsent', $session);
        $data = $request->validate(['types' => ['required', 'array'], 'types.*' => [Rule::enum(ConsentType::class)]]);
        $granted = [];
        foreach ($data['types'] as $t) {
            $granted[] = $this->consent->grant($session, $request->user(), ConsentType::from($t), $request);
        }

        return response()->json(['granted' => $granted, 'analysis_allowed' => $this->consent->analysisAllowed($session)], 201);
    }

    /**
     * Withdraw one consent and apply its consequences immediately.
     *
     * Each of the three consents means something different, so each has its own
     * consequence. Previously only behaviour analysis was acted on: withdrawing
     * transcription left every transcript already recorded in place with nothing
     * scheduled to remove it, and withdrawing consent to the video call itself
     * did nothing at all.
     */
    public function withdraw(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('manageConsent', $session);
        $data = $request->validate(['type' => ['required', Rule::enum(ConsentType::class)]]);
        $type = ConsentType::from($data['type']);
        $this->consent->withdraw($session, $request->user(), $type);

        match ($type) {
            ConsentType::BehaviorAnalysis => $this->stopAnalysis($request, $session, 'analysis_consent_withdrawn'),
            ConsentType::Transcription => $this->stopTranscription($request, $session),
            // Behaviour analysis is only lawful while the call consent stands,
            // so withdrawing the call consent withdraws the analysis with it.
            ConsentType::VideoCall => $this->stopCall($request, $session),
        };

        return response()->json([
            'ok' => true,
            'analysis_allowed' => $this->consent->analysisAllowed($session),
            'transcription_allowed' => $session->hasActiveConsent(ConsentType::Transcription),
        ]);
    }

    /** Stop processing now; schedule the derived data for deletion. */
    protected function stopAnalysis(Request $request, TherapySession $session, string $event): void
    {
        $session->update(['analysis_enabled' => false, 'analysis_paused_at' => now()]);
        if ($session->analysis_session_ref) {
            try {
                $this->analysis->control($session, 'consent_withdraw');
            } catch (\Throwable) {
                // The analysis service holds nothing durable for this session;
                // the scheduled purge below is what guarantees removal.
            }
        }
        $this->scheduleDeletion($request, $session, 'session_derived');
        SessionStatusChanged::dispatch($session, $event);
    }

    /**
     * New segments are already refused by the consent gate on the transcript and
     * ASR endpoints. What was missing is the other half: removing what was
     * recorded before, and telling the clients to stop their recorders rather
     * than letting them upload chunks that will be rejected.
     */
    protected function stopTranscription(Request $request, TherapySession $session): void
    {
        $this->scheduleDeletion($request, $session, 'session_transcript');
        SessionStatusChanged::dispatch($session, 'transcription_consent_withdrawn');
    }

    protected function stopCall(Request $request, TherapySession $session): void
    {
        foreach ([ConsentType::BehaviorAnalysis, ConsentType::Transcription] as $dependent) {
            if ($session->hasActiveConsent($dependent)) {
                $this->consent->withdraw($session, $request->user(), $dependent);
            }
        }
        $this->stopAnalysis($request, $session, 'video_consent_withdrawn');
        $this->scheduleDeletion($request, $session, 'session_transcript');
    }

    protected function scheduleDeletion(Request $request, TherapySession $session, string $scope): void
    {
        DataDeletionRequest::firstOrCreate([
            'therapy_session_id' => $session->id,
            'scope' => $scope,
            'status' => 'pending',
        ], [
            'user_id' => $request->user()->id,
            'scheduled_for' => now()->addHours((int) config('ravan.retention.withdrawn_consent_purge_hours', 24)),
        ]);
    }
}
