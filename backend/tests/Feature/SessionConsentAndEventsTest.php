<?php

namespace Tests\Feature;

use App\Events\BehaviorEventCreated;
use App\Models\ClinicianProfile;
use App\Models\TherapySession;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionConsentAndEventsTest extends TestCase
{
    use RefreshDatabase;

    protected User $patient;

    protected User $clinician;

    protected TherapySession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['ravan.analysis.webhook_secret' => 'test-secret', 'ravan.analysis.base_url' => 'http://analysis.test']);
        $this->patient = User::where('email', 'patient@ravan.local')->first();
        $this->clinician = User::where('email', 'dr.sara@ravan.local')->first();
        $profile = ClinicianProfile::where('user_id', $this->clinician->id)->first();
        $appt = $this->actingAs($this->patient)->postJson('/api/appointments', [
            'clinician_profile_id' => $profile->id, 'starts_at' => now()->addDay()->toIso8601String(), 'mode' => 'video',
        ])->assertCreated()->json();
        $this->session = TherapySession::where('uuid', $appt['session']['uuid'])->first();
    }

    protected function goLive(): void
    {
        $this->actingAs($this->patient)->postJson("/api/sessions/{$this->session->uuid}/join")->assertOk();
        $this->actingAs($this->clinician)->postJson("/api/sessions/{$this->session->uuid}/join")->assertOk();
        $this->session->refresh();
    }

    protected function sign(array $payload): array
    {
        return ['X-Ravan-Signature' => hash_hmac('sha256', json_encode($payload), 'test-secret')];
    }

    protected function eventPayload(array $over = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(), 'session_id' => $this->session->uuid, 'signal_id' => 'response_latency_increase',
            'group' => 'speech_prosody', 'tier' => 'change', 't_start_ms' => 754210, 't_end_ms' => 758900,
            'observation' => ['en' => 'Time from question end to answer start longer than baseline', 'fa' => 'تأخیر پاسخ طولانی‌تر از خط پایه'],
            'baseline_value' => 1.4, 'observed_value' => 4.7, 'delta' => 3.3, 'z_score' => 2.9, 'unit' => 's', 'confidence' => 0.86,
            'quality' => ['audio_quality' => 0.95], 'context' => ['speaker' => 'patient_speaking'],
            'possible_contexts' => [['key' => 'thinking', 'en' => 'thinking', 'fa' => 'فکر کردن']],
            'clinical_note' => ['en' => 'Observation only.', 'fa' => 'فقط مشاهده.'], 'member_events' => [], 'diagnostic_claim' => null,
        ], $over);
    }

    public function test_analysis_cannot_start_without_both_consents(): void
    {
        $this->goLive();
        $this->actingAs($this->patient)->postJson("/api/sessions/{$this->session->uuid}/analysis/start")->assertForbidden();
        $this->actingAs($this->patient)->postJson("/api/sessions/{$this->session->uuid}/consents", ['types' => ['video_call']])->assertCreated();
        $this->actingAs($this->patient)->postJson("/api/sessions/{$this->session->uuid}/analysis/start")->assertForbidden();
    }

    public function test_full_flow_consent_start_webhook_review_withdraw(): void
    {
        Event::fake([BehaviorEventCreated::class]);
        Http::fake(['analysis.test/*' => Http::response(['ok' => true], 200)]);
        $this->goLive();
        $this->assertSame('live', $this->session->status->value);

        $this->actingAs($this->patient)->postJson("/api/sessions/{$this->session->uuid}/consents", ['types' => ['video_call', 'behavior_analysis', 'transcription']])
            ->assertCreated()->assertJsonPath('analysis_allowed', true);
        // only the patient may grant consent
        $this->actingAs($this->clinician)->postJson("/api/sessions/{$this->session->uuid}/consents", ['types' => ['video_call']])->assertForbidden();

        $this->actingAs($this->patient)->postJson("/api/sessions/{$this->session->uuid}/analysis/start")->assertOk()->assertJsonPath('analysis_enabled', true);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/sessions') && $req['patient_ref'] !== $this->patient->email && $req['patient_ref'] !== $this->patient->name);

        // transcript segment then a signed event that links to it
        $this->actingAs($this->clinician)->postJson("/api/sessions/{$this->session->uuid}/transcript", ['segments' => [
            ['speaker' => 'clinician', 't_start_ms' => 750000, 't_end_ms' => 754000, 'text' => 'رابطه شما با خانواده چطور است؟', 'is_question' => true],
            ['speaker' => 'patient', 't_start_ms' => 758700, 't_end_ms' => 760000, 'text' => 'هیچی'],
        ]])->assertCreated();

        $payload = $this->eventPayload();
        $this->postJson('/api/webhooks/analysis/events', $payload, ['X-Ravan-Signature' => 'bad'])->assertUnauthorized();
        $this->postJson('/api/webhooks/analysis/events', $payload, $this->sign($payload))->assertCreated();
        Event::assertDispatched(BehaviorEventCreated::class);

        // a payload carrying a diagnostic claim is rejected
        $bad = $this->eventPayload(['diagnostic_claim' => 'anxiety']);
        $this->postJson('/api/webhooks/analysis/events', $bad, $this->sign($bad))->assertStatus(422);

        // clinician sees the event with transcript link; patient cannot
        $list = $this->actingAs($this->clinician)->getJson("/api/sessions/{$this->session->uuid}/events")->assertOk()->json();
        $this->assertCount(1, $list['data']);
        $this->assertNull($list['data'][0]['diagnostic_claim']);
        $this->assertSame('هیچی', $list['data'][0]['transcript_segment']['text']);
        $this->actingAs($this->patient)->getJson("/api/sessions/{$this->session->uuid}/events")->assertForbidden();

        // review
        $uuid = $list['data'][0]['uuid'];
        $this->actingAs($this->clinician)->postJson("/api/events/{$uuid}/review", ['status' => 'relevant', 'note' => 'بعد از سؤال خانواده', 'selected_context' => 'topic_related'])
            ->assertOk()->assertJsonPath('clinician_status', 'relevant');
        $this->actingAs($this->patient)->postJson("/api/events/{$uuid}/review", ['status' => 'dismissed'])->assertForbidden();

        // withdrawal stops storage and schedules deletion
        $this->actingAs($this->patient)->postJson("/api/sessions/{$this->session->uuid}/consents/withdraw", ['type' => 'behavior_analysis'])
            ->assertOk()->assertJsonPath('analysis_allowed', false);
        $this->assertDatabaseHas('data_deletion_requests', ['therapy_session_id' => $this->session->id, 'scope' => 'session_derived']);
        $late = $this->eventPayload();
        $this->postJson('/api/webhooks/analysis/events', $late, $this->sign($late))->assertStatus(202)->assertJsonPath('stored', false);
        $this->assertDatabaseCount('behavior_events', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'consent.withdrawn']);
        $this->assertDatabaseHas('access_logs', ['resource' => 'events', 'user_id' => $this->clinician->id]);
    }

    public function test_end_session_stores_report_and_versions(): void
    {
        Http::fake([
            'analysis.test/sessions' => Http::response(['ok' => true]),
            'analysis.test/sessions/*/finish*' => Http::response([
                'session_id' => $this->session->uuid, 'duration_ms' => 100000, 'baseline' => ['ready' => true], 'quality' => [],
                'ai_draft_summary' => ['text' => 'پیش‌نویس برای بازبینی درمانگر. ارزیابی بالینی نیست.', 'provider' => 'NullProvider', 'guardrail_removed' => []],
            ]),
            'analysis.test/*' => Http::response(['ok' => true]),
        ]);
        $this->goLive();
        $this->actingAs($this->patient)->postJson("/api/sessions/{$this->session->uuid}/consents", ['types' => ['video_call', 'behavior_analysis']]);
        $this->actingAs($this->patient)->postJson("/api/sessions/{$this->session->uuid}/analysis/start")->assertOk();
        $this->actingAs($this->clinician)->postJson("/api/sessions/{$this->session->uuid}/end")->assertOk()->assertJsonPath('status', 'ended');

        $this->actingAs($this->clinician)->getJson("/api/sessions/{$this->session->uuid}/report")->assertOk()->assertJsonPath('status', 'draft');
        $this->actingAs($this->patient)->getJson("/api/sessions/{$this->session->uuid}/report")->assertForbidden();
        $this->actingAs($this->clinician)->postJson("/api/sessions/{$this->session->uuid}/report/review", [
            'items' => [['key' => 'ai_summary', 'ai_text' => 'x', 'clinician_text' => 'y', 'status' => 'edited']], 'summary' => 'خلاصه درمانگر', 'finalize' => true,
        ])->assertOk()->assertJsonPath('status', 'finalized')->assertJsonPath('versions.0.version', 1);
    }
}
