<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Appointment lifecycle: created, confirmed, cancelled, reminder. Stored in-app and mailed. */
class AppointmentNotification extends Notification
{
    use Queueable;

    public function __construct(public Appointment $appointment, public string $event) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'appointment_id' => $this->appointment->id,
            'starts_at' => $this->appointment->starts_at->toIso8601String(),
            'mode' => $this->appointment->mode->value,
            'session_uuid' => $this->appointment->session?->uuid,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        app()->setLocale($notifiable->locale ?? 'fa');

        return (new MailMessage)
            ->subject(__("notifications.appointment_{$this->event}_subject"))
            ->line(__("notifications.appointment_{$this->event}_line", ['time' => $this->appointment->starts_at->toDayDateTimeString()]));
    }
}
