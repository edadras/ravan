<?php

namespace App\Services\Payments;

use App\Models\Payment;

interface PaymentGateway
{
    /** Start a payment; returns ['redirect_url' => ..., 'ref' => ...]. */
    public function initiate(Payment $payment, string $callbackUrl): array;

    /** Verify a returned payment; returns true when settled. */
    public function verify(Payment $payment, array $callbackParams): bool;
}
