<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;

/** Zarinpal (Iran) v4 REST API. Configure ZARINPAL_MERCHANT_ID; sandbox when ZARINPAL_SANDBOX=true. */
class ZarinpalGateway implements PaymentGateway
{
    protected function base(): string
    {
        return config('services.zarinpal.sandbox') ? 'https://sandbox.zarinpal.com' : 'https://api.zarinpal.com';
    }

    public function initiate(Payment $payment, string $callbackUrl): array
    {
        $res = Http::acceptJson()->post($this->base().'/pg/v4/payment/request.json', [
            'merchant_id' => config('services.zarinpal.merchant_id'),
            'amount' => $payment->amount,
            'currency' => $payment->currency === 'IRR' ? 'IRR' : 'IRT',
            'callback_url' => $callbackUrl,
            'description' => 'Ravan session #'.$payment->appointment_id,
        ])->throw()->json('data');
        $payment->update(['gateway_ref' => $res['authority'], 'status' => 'initiated']);

        return ['redirect_url' => $this->base().'/pg/StartPay/'.$res['authority'], 'ref' => $res['authority']];
    }

    public function verify(Payment $payment, array $callbackParams): bool
    {
        if (($callbackParams['Status'] ?? '') !== 'OK') {
            return false;
        }
        $res = Http::acceptJson()->post($this->base().'/pg/v4/payment/verify.json', [
            'merchant_id' => config('services.zarinpal.merchant_id'),
            'amount' => $payment->amount,
            'authority' => $callbackParams['Authority'] ?? $payment->gateway_ref,
        ])->json('data');
        $ok = in_array($res['code'] ?? 0, [100, 101], true);
        if ($ok) {
            $payment->update(['gateway_payload' => $res]);
        }

        return $ok;
    }
}
