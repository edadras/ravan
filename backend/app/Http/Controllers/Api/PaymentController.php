<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(protected PaymentGateway $gateway, protected AuditLogger $audit) {}

    public function initiate(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($request->user()->id === $appointment->patient_id, 403);
        abort_if($appointment->payments()->where('status', 'paid')->exists(), 409, __('messages.already_paid'));
        $payment = $appointment->payments()->create(['payer_id' => $request->user()->id, 'amount' => $appointment->fee, 'currency' => $appointment->currency, 'gateway' => config('ravan.payments.gateway')]);
        $res = $this->gateway->initiate($payment, route('payments.callback', $payment));
        $this->audit->log($request->user(), 'payment.initiated', $payment);

        return response()->json(['payment_id' => $payment->id] + $res, 201);
    }

    /** Gateway returns here (GET, unauthenticated). */
    public function callback(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->status === 'paid') {
            return response()->json(['status' => 'paid']);
        }
        if ($this->gateway->verify($payment, $request->query())) {
            $payment->update(['status' => 'paid', 'paid_at' => now()]);
            $fee = (int) round($payment->amount * (float) config('ravan.payments.platform_fee_ratio', 0.15));
            Invoice::create(['payment_id' => $payment->id, 'number' => 'RV-'.now()->format('Ymd').'-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT), 'amount' => $payment->amount, 'platform_fee' => $fee, 'clinician_share' => $payment->amount - $fee]);
            $payment->appointment->update(['status' => AppointmentStatus::Confirmed]);
            $this->audit->log(null, 'payment.paid', $payment);

            return response()->json(['status' => 'paid']);
        }
        $payment->update(['status' => 'failed']);

        return response()->json(['status' => 'failed'], 402);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(Payment::with('appointment:id,uuid,starts_at')->where('payer_id', $request->user()->id)->orderByDesc('id')->paginate(20));
    }
}
