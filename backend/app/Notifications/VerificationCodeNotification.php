<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerificationCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public string $code, public string $purpose, public string $channel) {}

    public function via(object $notifiable): array
    {
        return $this->channel === 'sms' ? ['vonage'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.code_subject'))
            ->line(__('notifications.code_line', ['code' => $this->code, 'minutes' => 10]))
            ->line(__('notifications.code_ignore'));
    }

    public function toVonage(object $notifiable): mixed
    {
        // Provider-specific SMS message; replace with the local SMS gateway (e.g. Kavenegar) in production.
        return __('notifications.code_line', ['code' => $this->code, 'minutes' => 10]);
    }
}
