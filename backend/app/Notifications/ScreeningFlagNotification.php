<?php

namespace App\Notifications;

use App\Models\ScreeningResult;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A screening item flagged for clinician attention (e.g. PHQ-9 item 9). Points to the answers; no risk score. */
class ScreeningFlagNotification extends Notification
{
    use Queueable;

    public function __construct(public ScreeningResult $result) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return ['event' => 'screening_flag', 'instrument' => $this->result->instrument, 'patient_record_id' => $this->result->patient_record_id, 'screening_id' => $this->result->id];
    }

    public function toMail(object $notifiable): MailMessage
    {
        app()->setLocale($notifiable->locale ?? 'fa');

        return (new MailMessage)->subject(__('notifications.screening_flag_subject'))->line(__('notifications.screening_flag_line', ['instrument' => strtoupper($this->result->instrument)]));
    }
}
