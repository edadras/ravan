<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Notifications\AppointmentNotification;
use Illuminate\Console\Command;

/** Sends a reminder to both parties ~24h and ~1h before a confirmed appointment (idempotent per window). */
class RemindAppointments extends Command
{
    protected $signature = 'ravan:remind-appointments';

    protected $description = 'Send appointment reminders';

    public function handle(): int
    {
        $n = 0;
        foreach ([['24h', 24 * 60, 30], ['1h', 60, 30]] as [$label, $minutesBefore, $window]) {
            $from = now()->addMinutes($minutesBefore);
            $appts = Appointment::with(['patient', 'clinician'])->where('status', 'confirmed')
                ->whereBetween('starts_at', [$from, $from->copy()->addMinutes($window)])->get();
            foreach ($appts as $a) {
                foreach ([$a->patient, $a->clinician] as $u) {
                    $already = $u->notifications()->where('data->event', "reminder_{$label}")->where('data->appointment_id', $a->id)->exists();
                    if (! $already) {
                        $u->notify(new AppointmentNotification($a, "reminder_{$label}"));
                        $n++;
                    }
                }
            }
        }
        $this->info("sent {$n} reminders");

        return self::SUCCESS;
    }
}
