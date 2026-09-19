<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Enums\SessionMode;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ClinicianProfile;
use App\Models\TherapySession;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Appointment::with(['clinician:id,name', 'patient:id,name', 'session:id,uuid,appointment_id,status'])
            ->when($user->isPatient(), fn ($b) => $b->where('patient_id', $user->id))
            ->when($user->isClinician(), fn ($b) => $b->where('clinician_id', $user->id))
            ->orderByDesc('starts_at');

        return response()->json($q->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clinician_profile_id' => ['required', 'exists:clinician_profiles,id'],
            'starts_at' => ['required', 'date', 'after:now'],
            'mode' => ['required', Rule::enum(SessionMode::class)],
            'patient_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $profile = ClinicianProfile::findOrFail($data['clinician_profile_id']);
        abort_unless($profile->isVerified() && $profile->accepts_new_patients, 422, 'clinician not available');
        abort_unless(in_array($data['mode'], $profile->session_modes ?? ['video'], true), 422, 'mode not offered');

        $appointment = Appointment::create([
            'patient_id' => $request->user()->id,
            'clinician_id' => $profile->user_id,
            'starts_at' => $data['starts_at'],
            'ends_at' => now()->parse($data['starts_at'])->addMinutes($profile->session_length_min),
            'mode' => $data['mode'],
            'status' => AppointmentStatus::Pending,
            'fee' => $profile->session_fee,
            'currency' => $profile->currency,
            'patient_note' => $data['patient_note'] ?? null,
        ]);
        // The session shell is created immediately so both parties have a stable room id.
        TherapySession::create([
            'appointment_id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
            'clinician_id' => $appointment->clinician_id,
            'mode' => $appointment->mode,
            'language' => $request->user()->locale ?? 'fa',
        ]);
        $this->audit->log($request->user(), 'appointment.created', $appointment);

        return response()->json($appointment->load('session:id,uuid,appointment_id,status'), 201);
    }

    public function confirm(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($request->user()->id === $appointment->clinician_id, 403);
        $appointment->update(['status' => AppointmentStatus::Confirmed]);
        $this->audit->log($request->user(), 'appointment.confirmed', $appointment);

        return response()->json($appointment);
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->id, [$appointment->patient_id, $appointment->clinician_id], true), 403);
        $appointment->update([
            'status' => AppointmentStatus::Cancelled,
            'cancelled_by' => $user->id,
            'cancel_reason' => $request->string('reason')->limit(200)->toString(),
        ]);
        $appointment->session?->update(['status' => 'cancelled']);
        $this->audit->log($user, 'appointment.cancelled', $appointment);

        return response()->json($appointment);
    }
}
