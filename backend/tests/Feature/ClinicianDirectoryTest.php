<?php

namespace Tests\Feature;

use App\Models\ClinicianProfile;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicianDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_verified_clinicians_are_listed(): void
    {
        $this->seed(DemoSeeder::class);
        $res = $this->getJson('/api/clinicians')->assertOk();
        $names = collect($res->json('data'))->pluck('user.name');
        $this->assertTrue($names->contains('دکتر سارا احمدی'));
        $this->assertFalse($names->contains('دکتر رضا کریمی'));
        $this->assertArrayNotHasKey('license_number', $res->json('data.0'));
    }

    public function test_pending_clinician_profile_is_not_visible(): void
    {
        $this->seed(DemoSeeder::class);
        $pending = ClinicianProfile::whereHas('user', fn ($q) => $q->where('email', 'dr.pending@ravan.local'))->first();
        $this->getJson("/api/clinicians/{$pending->id}")->assertNotFound();
    }

    public function test_admin_approval_makes_clinician_visible(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = User::where('email', 'admin@ravan.local')->first();
        $pending = ClinicianProfile::whereHas('user', fn ($q) => $q->where('email', 'dr.pending@ravan.local'))->first();
        $this->actingAs($admin)->postJson("/api/admin/clinicians/{$pending->id}/decision", ['decision' => 'approved', 'checked_items' => ['license_valid' => true]])->assertOk();
        $this->getJson("/api/clinicians/{$pending->id}")->assertOk();
        $patient = User::where('email', 'patient@ravan.local')->first();
        $this->actingAs($patient)->postJson("/api/admin/clinicians/{$pending->id}/decision", ['decision' => 'rejected'])->assertForbidden();
    }
}
