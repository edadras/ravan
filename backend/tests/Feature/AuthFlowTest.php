<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_patient_registration_creates_record_and_sends_code_then_password_reset_works(): void
    {
        Notification::fake();
        $res = $this->postJson('/api/auth/register', ['name' => 'Ali', 'email' => 'ali@example.com', 'password' => 'strong-password-1', 'accept_terms' => true, 'locale' => 'tr'])
            ->assertCreated()->assertJsonPath('user.role', 'patient')->assertJsonPath('clinician_pending_verification', false)->json();
        $this->assertDatabaseHas('patient_records', ['patient_id' => $res['user']['id']]);
        $this->assertDatabaseHas('verification_codes', ['target' => 'ali@example.com', 'purpose' => 'verify']);

        // request a reset code (debug_code is exposed only in testing/local)
        $code = $this->postJson('/api/auth/code/request', ['target' => 'ali@example.com', 'purpose' => 'reset'])->assertOk()->json('debug_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->postJson('/api/auth/password/reset', ['email' => 'ali@example.com', 'code' => '000001', 'password' => 'another-strong-pw'])->assertStatus(422);
        $this->postJson('/api/auth/password/reset', ['email' => 'ali@example.com', 'code' => $code, 'password' => 'another-strong-pw'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'ali@example.com', 'password' => 'another-strong-pw'])->assertOk()->assertJsonStructure(['token']);
        // code is single-use
        $this->postJson('/api/auth/password/reset', ['email' => 'ali@example.com', 'code' => $code, 'password' => 'third-strong-pw-x'])->assertStatus(422);
        // unknown target does not reveal existence
        $this->postJson('/api/auth/code/request', ['target' => 'nobody@example.com', 'purpose' => 'reset'])->assertOk()->assertJsonMissing(['debug_code']);
    }

    public function test_clinician_registration_is_pending_and_hidden_until_admin_approves(): void
    {
        Notification::fake();
        $res = $this->postJson('/api/auth/register', ['name' => 'Dr X', 'email' => 'x@example.com', 'password' => 'strong-password-1', 'accept_terms' => true,
            'role' => 'clinician', 'title' => 'Psychologist', 'license_number' => 'L-1', 'license_authority' => 'Board'])
            ->assertCreated()->assertJsonPath('clinician_pending_verification', true)->json();
        $this->assertDatabaseHas('clinician_profiles', ['user_id' => $res['user']['id'], 'verification_status' => 'pending']);
        $this->assertCount(0, $this->getJson('/api/clinicians')->json('data'));
    }

    public function test_passwordless_code_login_and_profile_update(): void
    {
        Notification::fake();
        $u = User::factory()->create(['email' => 'p@example.com', 'role' => 'patient']);
        $code = $this->postJson('/api/auth/code/request', ['target' => 'p@example.com', 'purpose' => 'login'])->json('debug_code');
        $token = $this->postJson('/api/auth/code/login', ['target' => 'p@example.com', 'code' => $code])->assertOk()->json('token');
        $this->withToken($token)->patchJson('/api/auth/me', ['locale' => 'en', 'name' => 'Pat'])->assertOk()->assertJsonPath('locale', 'en');
        $this->withToken($token)->postJson('/api/auth/password/change', ['current_password' => 'password', 'password' => 'brand-new-password'])->assertOk();
        $this->assertNotNull($u->fresh()->email_verified_at);
    }
}
