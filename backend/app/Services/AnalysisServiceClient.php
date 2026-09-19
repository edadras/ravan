<?php

namespace App\Services;

use App\Models\TherapySession;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Python analysis service.
 *
 * Only pseudonymous identifiers are ever sent: the analysis service never learns
 * a patient's name, e-mail or phone number.
 */
class AnalysisServiceClient
{
    public function __construct(
        protected string $baseUrl = '',
        protected string $token = '',
    ) {
        $this->baseUrl = $baseUrl ?: rtrim((string) config('ravan.analysis.base_url'), '/');
        $this->token = $token ?: (string) config('ravan.analysis.token');
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout((int) config('ravan.analysis.timeout_s', 5))
            ->withToken($this->token)
            ->acceptJson();
    }

    public function startSession(TherapySession $session): array
    {
        $patientRef = $session->patient->patientProfile?->pseudonym ?? 'p-'.$session->patient_id;

        return $this->http()->post('/sessions', [
            'session_id' => $session->uuid,
            'patient_ref' => $patientRef,
            'clinician_ref' => 'c-'.$session->clinician_id,
            'language' => $session->language,
            'baseline_window_s' => (int) config('ravan.analysis.baseline_window_s'),
            'webhook_url' => config('ravan.analysis.webhook_url'),
        ])->throw()->json();
    }

    public function control(TherapySession $session, string $action, array $payload = []): array
    {
        return $this->http()->post("/sessions/{$session->uuid}/control", [
            't_ms' => $session->elapsedMs(),
            'action' => $action,
            'payload' => $payload,
        ])->throw()->json();
    }

    public function pushTranscript(TherapySession $session, array $segments): array
    {
        return $this->http()->post("/sessions/{$session->uuid}/transcript", $segments)->throw()->json();
    }

    public function question(TherapySession $session, array $question): void
    {
        $this->http()->post("/sessions/{$session->uuid}/question", $question)->throw();
    }

    public function finish(TherapySession $session): array
    {
        return $this->http()->post("/sessions/{$session->uuid}/finish?language={$session->language}")->throw()->json();
    }

    public function baseline(TherapySession $session): array
    {
        return $this->http()->get("/sessions/{$session->uuid}/baseline")->throw()->json();
    }

    /** Verify the HMAC-SHA256 signature the analysis service puts on webhook bodies. */
    public static function verifySignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('ravan.analysis.webhook_secret');
        if ($secret === '' || $signature === null) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }
}
