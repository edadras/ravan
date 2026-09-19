<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ClinicianProfile;
use App\Models\PatientProfile;
use App\Models\PatientRecord;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\VerificationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(protected VerificationCodeService $codes, protected AuditLogger $audit) {}

    /**
     * Self-registration. Patients are active immediately (e-mail verification requested);
     * clinicians are created with a pending profile and become visible only after admin approval.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:10'],
            'locale' => ['nullable', 'in:fa,en,tr'],
            'role' => ['nullable', Rule::in(['patient', 'clinician'])],
            'accept_terms' => ['accepted'],
            // clinician-only
            'title' => ['required_if:role,clinician', 'nullable', 'string', 'max:64'],
            'license_number' => ['required_if:role,clinician', 'nullable', 'string', 'max:64'],
            'license_authority' => ['required_if:role,clinician', 'nullable', 'string', 'max:128'],
        ]);
        $role = $data['role'] ?? 'patient';
        $user = User::create([
            'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'password' => $data['password'],
            'role' => $role, 'locale' => $data['locale'] ?? 'fa', 'terms_accepted_at' => now(), 'terms_version' => config('ravan.terms_version', '1.0'),
        ]);
        if ($role === Role::Clinician->value) {
            ClinicianProfile::create(['user_id' => $user->id, 'title' => $data['title'], 'license_number' => $data['license_number'],
                'license_authority' => $data['license_authority'], 'session_modes' => ['video'], 'languages' => [$data['locale'] ?? 'fa']]);
        } else {
            PatientProfile::create(['user_id' => $user->id, 'preferred_language' => $data['locale'] ?? 'fa']);
            PatientRecord::create(['patient_id' => $user->id]);
        }
        $this->codes->issue('email', $user->email, 'verify');
        $this->audit->log($user, 'auth.registered', $user, ['role' => $role]);

        return response()->json(['user' => $user, 'token' => $user->createToken('app')->plainTextToken,
            'clinician_pending_verification' => $role === 'clinician'], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required'], 'device' => ['nullable', 'string']]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log($user, 'auth.login', $user);

        return response()->json(['user' => $user->load('clinicianProfile', 'patientProfile'), 'token' => $user->createToken($data['device'] ?? 'app')->plainTextToken]);
    }

    /** Passwordless: request a code by e-mail or SMS, then exchange it for a token. */
    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate(['target' => ['required', 'string'], 'purpose' => ['required', Rule::in(['login', 'reset', 'verify'])]]);
        $channel = filter_var($data['target'], FILTER_VALIDATE_EMAIL) ? 'email' : 'sms';
        $exists = User::where($channel === 'email' ? 'email' : 'phone', $data['target'])->exists();
        // Always respond the same way to avoid account enumeration.
        $debug = $exists ? $this->codes->issue($channel, $data['target'], $data['purpose']) : '';

        return response()->json(['sent' => true] + (app()->environment('testing', 'local') && $debug ? ['debug_code' => $debug] : []));
    }

    public function loginWithCode(Request $request): JsonResponse
    {
        $data = $request->validate(['target' => ['required', 'string'], 'code' => ['required', 'digits:6']]);
        $channel = filter_var($data['target'], FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $user = User::where($channel, $data['target'])->first();
        if (! $user || ! $this->codes->verify($data['target'], 'login', $data['code'])) {
            throw ValidationException::withMessages(['code' => __('auth.failed')]);
        }
        $user->forceFill(['last_login_at' => now(), $channel.'_verified_at' => $user->{$channel.'_verified_at'} ?? now()])->save();

        return response()->json(['user' => $user, 'token' => $user->createToken('app')->plainTextToken]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:6'], 'password' => ['required', 'string', 'min:10']]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! $this->codes->verify($data['email'], 'reset', $data['code'])) {
            throw ValidationException::withMessages(['code' => __('auth.failed')]);
        }
        $user->forceFill(['password' => $data['password']])->save();
        $user->tokens()->delete();
        $this->audit->log($user, 'auth.password_reset', $user);

        return response()->json(['ok' => true]);
    }

    public function verifyContact(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6'], 'channel' => ['required', Rule::in(['email', 'phone'])]]);
        $user = $request->user();
        $target = $data['channel'] === 'email' ? $user->email : $user->phone;
        if (! $target || ! $this->codes->verify($target, 'verify', $data['code'])) {
            throw ValidationException::withMessages(['code' => __('auth.failed')]);
        }
        $user->forceFill([$data['channel'].'_verified_at' => now()])->save();

        return response()->json(['ok' => true, 'user' => $user]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('clinicianProfile.specialties', 'patientProfile'));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'locale' => ['sometimes', 'in:fa,en,tr'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', Rule::unique('users', 'phone')->ignore($user->id)],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:20'],
            'timezone' => ['sometimes', 'string', 'max:64'],
        ]);
        $user->update(collect($data)->only(['name', 'locale', 'phone'])->toArray());
        if ($user->isPatient()) {
            $user->patientProfile()->update(collect($data)->only(['birth_date', 'gender', 'timezone'])->toArray() + ['preferred_language' => $data['locale'] ?? $user->locale]);
        }

        return response()->json($user->fresh(['patientProfile', 'clinicianProfile']));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'string', 'min:10', 'different:current_password']]);
        $request->user()->forceFill(['password' => $data['password']])->save();
        $request->user()->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        return response()->json(['ok' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }
}
