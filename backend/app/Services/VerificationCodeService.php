<?php

namespace App\Services;

use App\Models\VerificationCode;
use App\Notifications\VerificationCodeNotification;
use Illuminate\Support\Facades\Notification;

/** Six-digit one-time codes for e-mail/phone verification, password reset and passwordless login. */
class VerificationCodeService
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public function issue(string $channel, string $target, string $purpose): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        VerificationCode::where('target', $target)->where('purpose', $purpose)->whereNull('consumed_at')->delete();
        VerificationCode::create([
            'channel' => $channel,
            'target' => $target,
            'purpose' => $purpose,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
        Notification::route($channel === 'sms' ? 'vonage' : 'mail', $target)
            ->notify(new VerificationCodeNotification($code, $purpose, $channel));

        return app()->environment('testing', 'local') ? $code : '';
    }

    public function verify(string $target, string $purpose, string $code): bool
    {
        $row = VerificationCode::where('target', $target)->where('purpose', $purpose)
            ->whereNull('consumed_at')->where('expires_at', '>', now())->latest('id')->first();
        if (! $row) {
            return false;
        }
        if ($row->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }
        if (! hash_equals($row->code_hash, hash('sha256', $code))) {
            $row->increment('attempts');

            return false;
        }
        $row->update(['consumed_at' => now()]);

        return true;
    }
}
