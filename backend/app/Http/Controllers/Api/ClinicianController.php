<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClinicianProfile;
use App\Models\Specialty;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicianController extends Controller
{
    /** Public directory: only admin-verified, active clinicians are ever listed. */
    public function index(Request $request): JsonResponse
    {
        $q = ClinicianProfile::verified()
            ->with(['user:id,name', 'specialties'])
            ->when($request->string('specialty')->toString(), fn ($b, $s) => $b->whereHas('specialties', fn ($x) => $x->where('slug', $s)))
            ->when($request->string('language')->toString(), fn ($b, $l) => $b->whereJsonContains('languages', $l))
            ->when($request->string('mode')->toString(), fn ($b, $m) => $b->whereJsonContains('session_modes', $m))
            ->when($request->filled('max_fee'), fn ($b) => $b->where('session_fee', '<=', (int) $request->input('max_fee')))
            ->when($request->boolean('accepting', true), fn ($b) => $b->where('accepts_new_patients', true))
            ->orderByDesc('rating_avg')->orderBy('session_fee');

        return response()->json($q->paginate(20));
    }

    public function show(ClinicianProfile $clinician): JsonResponse
    {
        abort_unless($clinician->isVerified(), 404);

        return response()->json($clinician->load(['user:id,name', 'specialties', 'schedules' => fn ($q) => $q->where('is_active', true)]));
    }

    public function specialties(): JsonResponse
    {
        return response()->json(Specialty::orderBy('name_fa')->get());
    }

    /** Free slots for the next N days computed from weekly schedule minus booked appointments and time off. */
    public function availability(ClinicianProfile $clinician, Request $request): JsonResponse
    {
        abort_unless($clinician->isVerified(), 404);
        $days = min((int) $request->input('days', 14), 60);
        $from = CarbonImmutable::now()->startOfHour()->addHour();
        $to = $from->addDays($days);

        $booked = $clinician->user->sessionsAsClinician()->getQuery()->newQuery()
            ->from('appointments')->where('clinician_id', $clinician->user_id)
            ->whereBetween('starts_at', [$from, $to])->whereNotIn('status', ['cancelled'])
            ->pluck('starts_at')->map(fn ($d) => CarbonImmutable::parse($d)->toIso8601String())->flip();
        $timeOff = $clinician->timeOff()->where('ends_at', '>=', $from)->where('starts_at', '<=', $to)->get();
        $schedules = $clinician->schedules()->where('is_active', true)->get()->groupBy('weekday');

        $slots = [];
        for ($d = $from->startOfDay(); $d <= $to; $d = $d->addDay()) {
            // Iranian week: 0 = Saturday. Carbon: 6 = Saturday → (dayOfWeek + 1) % 7
            $weekday = ($d->dayOfWeek + 1) % 7;
            foreach ($schedules->get($weekday, collect()) as $sch) {
                $start = $d->setTimeFromTimeString($sch->start_time);
                $end = $d->setTimeFromTimeString($sch->end_time);
                for ($t = $start; $t->addMinutes($sch->slot_minutes) <= $end; $t = $t->addMinutes($sch->slot_minutes)) {
                    if ($t < $from || isset($booked[$t->toIso8601String()])) {
                        continue;
                    }
                    if ($timeOff->contains(fn ($o) => $t >= $o->starts_at && $t < $o->ends_at)) {
                        continue;
                    }
                    $slots[] = ['starts_at' => $t->toIso8601String(), 'minutes' => $sch->slot_minutes];
                }
            }
        }

        return response()->json(['slots' => $slots]);
    }
}
