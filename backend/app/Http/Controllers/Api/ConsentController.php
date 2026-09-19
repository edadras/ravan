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

        return response()->json(collect($versions)->map(fn ($v, $type) => [
            'type' => $type, 'version' => $v,
            'title' => $texts[$type]->title ?? $type,
            'body' => $texts[$type]->body ?? '',
            'bullet_points' => $texts[$type]->bullet_points ?? [],
        ])->values());
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
     * Withdrawal of the analysis consent stops processing immediately, keeps the call running,
     * and schedules deletion of the derived data for this session.
     */
    public function withdraw(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('manageConsent', $session);
        $data = $request->validate(['type' => ['required', Rule::enum(ConsentType::class)]]);
        $type = ConsentType::from($data['type']);
        $this->consent->withdraw($session, $request->user(), $type);

        if ($type === ConsentType::BehaviorAnalysis) {
            $session->update(['analysis_enabled' => false, 'analysis_paused_at' => now()]);
            if ($session->analysis_session_ref) {
                try {
                    $this->analysis->control($session, 'consent_withdraw');
                } catch (\Throwable) {
                }
            }
            DataDeletionRequest::create([
                'user_id' => $request->user()->id,
                'therapy_session_id' => $session->id,
                'scope' => 'session_derived',
                'scheduled_for' => now()->addHours((int) config('ravan.retention.withdrawn_consent_purge_hours', 24)),
            ]);
            SessionStatusChanged::dispatch($session, 'analysis_consent_withdrawn');
        }

        return response()->json(['ok' => true, 'analysis_allowed' => $this->consent->analysisAllowed($session)]);
    }
}
