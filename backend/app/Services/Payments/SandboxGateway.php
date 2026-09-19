<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Str;

/** Local/dev gateway: instantly "pays" when the callback carries status=OK. */
class SandboxGateway implements PaymentGateway
{
    public function initiate(Payment $payment, string $callbackUrl): array
    {
        $ref = 'sbx-'.Str::random(12);
        $payment->update(['gateway_ref' => $ref, 'status' => 'initiated']);

        return ['redirect_url' => $callbackUrl.'?ref='.$ref.'&status=OK', 'ref' => $ref];
    }

    public function verify(Payment $payment, array $callbackParams): bool
    {
        return ($callbackParams['status'] ?? '') === 'OK' && ($callbackParams['ref'] ?? '') === $payment->gateway_ref;
    }
}
