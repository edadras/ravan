<?php

namespace App\Services;

use App\Enums\ConsentType;
use App\Models\SessionConsent;
use App\Models\TherapySession;
use App\Models\User;
use Illuminate\Http\Request;

class ConsentService
{
    public function __construct(protected AuditLogger $audit) {}

    public function grant(TherapySession $session, User $user, ConsentType $type, Request $request): SessionConsent
    {
        $version = config('ravan.consent.current_versions')[$type->value];
        $consent = SessionConsent::create([
            'therapy_session_id' => $session->id,
            'user_id' => $user->id,
            'type' => $type,
            'version' => $version,
            'granted_at' => now(),
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
        ]);
        $this->audit->log($user, 'consent.granted', $consent, ['type' => $type->value, 'version' => $version]);

        return $consent;
    }

    public function withdraw(TherapySession $session, User $user, ConsentType $type): int
    {
        $n = SessionConsent::where('therapy_session_id', $session->id)
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => now()]);
        $this->audit->log($user, 'consent.withdrawn', $session, ['type' => $type->value]);

        return $n;
    }

    /** Behaviour analysis requires BOTH the video-call consent and the analysis consent. */
    public function analysisAllowed(TherapySession $session): bool
    {
        return $session->hasActiveConsent(ConsentType::VideoCall)
            && $session->hasActiveConsent(ConsentType::BehaviorAnalysis);
    }
}
