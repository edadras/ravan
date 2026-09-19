<?php

namespace App\Services;

use App\Models\TherapySession;
use App\Models\User;

/**
 * Issues short-lived SFU join tokens. LiveKit-compatible JWT (HS256) by default.
 * Raw media flows patient ↔ SFU ↔ clinician; this backend never receives frames.
 */
class WebRtcTokenService
{
    public function issue(TherapySession $session, User $user): array
    {
        $cfg = config('ravan.webrtc');
        $role = $user->id === $session->clinician_id ? 'clinician' : 'patient';
        $now = time();
        $payload = [
            'iss' => $cfg['api_key'],
            'sub' => 'u-'.$user->id,
            'nbf' => $now - 10,
            'exp' => $now + (int) $cfg['token_ttl_s'],
            'name' => $role, // never the real name
            'video' => [
                'room' => $session->room_name,
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
            'metadata' => json_encode(['role' => $role, 'session' => $session->uuid]),
        ];

        return [
            'provider' => $cfg['provider'],
            'url' => $cfg['url'],
            'room' => $session->room_name,
            'token' => $this->jwt($payload, (string) $cfg['api_secret']),
            'expires_at' => $payload['exp'],
        ];
    }

    protected function jwt(array $payload, string $secret): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode($payload));
        $sig = $b64(hash_hmac('sha256', "$header.$body", $secret, true));

        return "$header.$body.$sig";
    }
}
