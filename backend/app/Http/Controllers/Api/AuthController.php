<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Public self-registration is for patients only; clinicians are created/invited by an admin. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:10'],
            'locale' => ['nullable', 'in:fa,en'],
        ]);
        $user = User::create($data + ['role' => Role::Patient->value]);
        PatientProfile::create(['user_id' => $user->id, 'preferred_language' => $data['locale'] ?? 'fa']);

        return response()->json(['user' => $user, 'token' => $user->createToken('app')->plainTextToken], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required'], 'device' => ['nullable', 'string']]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['user' => $user->load('clinicianProfile', 'patientProfile'), 'token' => $user->createToken($data['device'] ?? 'app')->plainTextToken]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('clinicianProfile.specialties', 'patientProfile'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }
}
