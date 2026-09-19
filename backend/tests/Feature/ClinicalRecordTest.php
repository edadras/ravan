<?php

namespace Tests\Feature;

use App\Models\ClinicianProfile;
use App\Models\DiagnosisCode;
use App\Models\PatientRecord;
use App\Models\TherapySession;
use App\Models\User;
use App\Notifications\ScreeningFlagNotification;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DiagnosisCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClinicalRecordTest extends TestCase
{
    use RefreshDatabase;

    protected User $patient;

    protected User $clinician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([DemoSeeder::class, DiagnosisCodeSeeder::class]);
        $this->patient = User::where('email', 'patient@ravan.local')->first();
        $this->clinician = User::where('email', 'dr.sara@ravan.local')->first();
        $profile = ClinicianProfile::where('user_id', $this->clinician->id)->first();
        $this->actingAs($this->patient)->postJson('/api/appointments', ['clinician_profile_id' => $profile->id, 'starts_at' => now()->addDay()->toIso8601String(), 'mode' => 'video'])->assertCreated();
    }

    public function test_intake_screening_diagnosis_and_access_rules(): void
    {
        Notification::fake();
        $p = $this->patient->id;
        // patient fills intake and answers PHQ-9 with item 9 > 0 → clinician notified
        $this->actingAs($this->patient)->patchJson("/api/records/{$p}/intake", ['chief_complaint' => 'sleep problems', 'current_medications' => [['name' => 'sertraline', 'dose' => '50mg']]])->assertOk();
        $this->actingAs($this->clinician)->patchJson("/api/records/{$p}/clinical", ['formulation' => 'x'])->assertOk()->assertJsonPath('primary_clinician_id', $this->clinician->id);
        $this->actingAs($this->patient)->postJson("/api/records/{$p}/screenings", ['instrument' => 'phq9', 'answers' => [1, 2, 1, 2, 1, 1, 1, 0, 1]])
            ->assertCreated()->assertJsonPath('total_score', 10)->assertJsonPath('severity_band', 'moderate')->assertJsonPath('item_flag', true);
        Notification::assertSentTo($this->clinician, ScreeningFlagNotification::class);
        $this->actingAs($this->patient)->postJson("/api/records/{$p}/screenings", ['instrument' => 'gad7', 'answers' => [0, 0, 1, 0, 0, 0, 0]])->assertCreated()->assertJsonPath('severity_band', 'minimal');
        $this->actingAs($this->patient)->postJson("/api/records/{$p}/screenings", ['instrument' => 'phq9', 'answers' => [1, 2]])->assertStatus(500);

        // patient cannot write clinical sections or diagnoses
        $this->actingAs($this->patient)->patchJson("/api/records/{$p}/clinical", ['formulation' => 'hack'])->assertForbidden();
        $this->actingAs($this->patient)->postJson("/api/records/{$p}/diagnoses", ['label' => 'x', 'status' => 'confirmed'])->assertForbidden();

        // clinician adds SOAP entry and a provisional diagnosis from the code list
        $this->actingAs($this->clinician)->postJson("/api/records/{$p}/entries", ['type' => 'intake', 'subjective' => 's', 'assessment' => 'a', 'plan' => 'p', 'lock' => true])->assertCreated()->assertJsonPath('is_locked', true);
        $code = $this->actingAs($this->clinician)->getJson('/api/records/codes?q=6B00', ['Accept-Language' => 'tr'])->assertOk()->json('0');
        $this->assertSame('Yaygın anksiyete bozukluğu', $code['label_tr']);
        $dx = $this->actingAs($this->clinician)->postJson("/api/records/{$p}/diagnoses", ['diagnosis_code_id' => $code['id'], 'status' => 'provisional', 'evidence' => 'GAD-7 + history'], ['Accept-Language' => 'fa'])
            ->assertCreated()->assertJsonPath('source', 'clinician')->assertJsonPath('label', 'اختلال اضطراب فراگیر')->json();
        $this->actingAs($this->clinician)->patchJson("/api/records/{$p}/diagnoses/{$dx['id']}", ['status' => 'confirmed'])->assertOk()->assertJsonPath('status', 'confirmed');

        // patient sees only confirmed diagnoses and no clinician-private fields
        $view = $this->actingAs($this->patient)->getJson("/api/records/{$p}")->assertOk()->json();
        $this->assertArrayNotHasKey('formulation', $view);
        $this->assertArrayNotHasKey('risk_history', $view);
        $this->assertCount(1, $view['diagnoses']);
        // an unrelated clinician cannot read
        $other = User::where('email', 'dr.pending@ravan.local')->first();
        $this->actingAs($other)->getJson("/api/records/{$p}")->assertForbidden();
        $this->actingAs($this->clinician)->postJson("/api/records/{$p}/access", ['clinician_id' => $other->id])->assertCreated();
        $this->actingAs($this->clinician)->getJson("/api/records/{$p}")->assertOk()->assertJsonPath('formulation', 'x');
        $this->actingAs($other)->getJson("/api/records/{$p}")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'record.diagnosis_added']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'record.viewed']);
    }

    public function test_ai_formulation_is_stored_as_suggestion_and_becomes_diagnosis_only_after_clinician_accepts(): void
    {
        config(['ravan.analysis.base_url' => 'http://analysis.test']);
        Http::fake(['analysis.test/assist/formulation' => Http::response([
            'output' => ['summary' => 'x', 'differential' => [['label' => 'Generalised anxiety disorder', 'icd11' => '6B00', 'evidence_for' => ['GAD-7 = 12'], 'evidence_against' => [], 'confidence' => 'moderate']]],
            'guardrail_removed' => [], 'provider' => 'OpenAIProvider', 'model' => 'test-model',
        ])]);
        $session = TherapySession::first();
        $this->actingAs($this->patient)->postJson("/api/sessions/{$session->uuid}/ai/formulation")->assertForbidden();
        $s = $this->actingAs($this->clinician)->postJson("/api/sessions/{$session->uuid}/ai/formulation")->assertCreated()->assertJsonPath('status', 'pending')->json();
        Http::assertSent(function ($req) {
            $b = $req->data();

            return ! str_contains(json_encode($b), 'patient@ravan.local') && ! str_contains(json_encode($b), 'کاربر نمونه') && isset($b['patient']['pseudonym']);
        });
        $this->assertDatabaseCount('diagnoses', 0);
        $this->actingAs($this->clinician)->postJson("/api/ai/suggestions/{$s['uuid']}/review", ['status' => 'accepted'])->assertOk();
        $code = DiagnosisCode::where('code', '6B00')->first();
        $this->actingAs($this->clinician)->postJson("/api/records/{$this->patient->id}/diagnoses", ['diagnosis_code_id' => $code->id, 'status' => 'provisional', 'ai_suggestion_uuid' => $s['uuid']])
            ->assertCreated()->assertJsonPath('source', 'ai_suggested');
        $this->assertSame(1, PatientRecord::first()->diagnoses()->count());
    }
}
