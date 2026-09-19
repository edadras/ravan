<?php

namespace Tests\Feature;

use App\Events\ConversationMessageSent;
use App\Models\ClinicianProfile;
use App\Models\DataDeletionRequest;
use App\Models\TherapySession;
use App\Models\User;
use Database\Seeders\ConsentTextSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MessagingPaymentsAsrTest extends TestCase
{
    use RefreshDatabase;

    protected User $patient;

    protected User $clinician;

    protected array $appt;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed([DemoSeeder::class, ConsentTextSeeder::class]);
        $this->patient = User::where('email', 'patient@ravan.local')->first();
        $this->clinician = User::where('email', 'dr.sara@ravan.local')->first();
        $profile = ClinicianProfile::where('user_id', $this->clinician->id)->first();
        $this->appt = $this->actingAs($this->patient)->postJson('/api/appointments', ['clinician_profile_id' => $profile->id, 'starts_at' => now()->addDay()->toIso8601String(), 'mode' => 'video'])->json();
    }

    public function test_direct_messaging_with_receipts_and_broadcast(): void
    {
        Event::fake([ConversationMessageSent::class]);
        $conv = $this->actingAs($this->patient)->postJson("/api/conversations/with/{$this->clinician->id}")->assertOk()->json();
        $stranger = User::where('email', 'dr.pending@ravan.local')->first();
        $this->actingAs($this->patient)->postJson("/api/conversations/with/{$stranger->id}")->assertForbidden();
        $this->actingAs($this->patient)->postJson("/api/conversations/{$conv['id']}/messages", ['body' => 'سلام دکتر'])->assertCreated();
        Event::assertDispatched(ConversationMessageSent::class);
        $list = $this->actingAs($this->clinician)->getJson('/api/conversations')->assertOk()->json();
        $this->assertSame(1, $list[0]['unread_count']);
        $this->actingAs($this->clinician)->getJson("/api/conversations/{$conv['id']}/messages")->assertOk();
        $this->actingAs($this->clinician)->postJson("/api/conversations/{$conv['id']}/read")->assertOk()->assertJsonPath('read', 1);
        $this->actingAs($stranger)->getJson("/api/conversations/{$conv['id']}/messages")->assertForbidden();
    }

    public function test_sandbox_payment_confirms_appointment_and_creates_invoice(): void
    {
        $res = $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appt['id']}/pay")->assertCreated()->json();
        $this->assertStringContainsString('status=OK', $res['redirect_url']);
        $this->getJson(parse_url($res['redirect_url'], PHP_URL_PATH).'?'.parse_url($res['redirect_url'], PHP_URL_QUERY))->assertOk()->assertJsonPath('status', 'paid');
        $this->assertDatabaseHas('appointments', ['id' => $this->appt['id'], 'status' => 'confirmed']);
        $this->assertDatabaseHas('invoices', ['amount' => 6000000, 'platform_fee' => 900000, 'clinician_share' => 5100000]);
        $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appt['id']}/pay")->assertStatus(409);
    }

    public function test_asr_chunk_becomes_transcript_segments_and_reaches_analysis(): void
    {
        config(['ravan.asr.base_url' => 'http://asr.test', 'ravan.analysis.base_url' => 'http://analysis.test']);
        Http::fake([
            'asr.test/transcribe' => Http::response(['backend' => 'fake', 'language' => 'fa', 'segments' => [
                ['t_start_ms' => 12000, 't_end_ms' => 14500, 'text' => 'رابطه شما با خانواده چطور است؟', 'confidence' => 0.93, 'is_question' => true],
            ]]),
            'analysis.test/*' => Http::response(['ok' => true]),
        ]);
        $session = TherapySession::first();
        $this->actingAs($this->patient)->postJson("/api/sessions/{$session->uuid}/join");
        $this->actingAs($this->clinician)->postJson("/api/sessions/{$session->uuid}/join");
        $file = UploadedFile::fake()->createWithContent('chunk.webm', str_repeat('a', 2000));
        $this->actingAs($this->clinician)->post("/api/sessions/{$session->uuid}/asr/chunk", ['audio' => $file, 't_start_ms' => 12000, 'duration_ms' => 5000])->assertForbidden();
        $this->actingAs($this->patient)->postJson("/api/sessions/{$session->uuid}/consents", ['types' => ['video_call', 'behavior_analysis', 'transcription']]);
        $this->actingAs($this->patient)->postJson("/api/sessions/{$session->uuid}/analysis/start")->assertOk();
        $res = $this->actingAs($this->clinician)->post("/api/sessions/{$session->uuid}/asr/chunk", ['audio' => $file, 't_start_ms' => 12000, 'duration_ms' => 5000])->assertOk()->json();
        $this->assertCount(1, $res['segments']);
        $this->assertDatabaseHas('transcript_segments', ['speaker' => 'clinician', 'is_question' => true, 't_start_ms' => 12000]);
        $this->assertDatabaseHas('asr_jobs', ['status' => 'done', 'speaker' => 'clinician', 'segments' => 1]);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/transcript') && $r['0']['is_question'] === true);
    }

    public function test_purge_command_executes_deletion_requests(): void
    {
        $session = TherapySession::first();
        DataDeletionRequest::create(['user_id' => $this->patient->id, 'therapy_session_id' => $session->id, 'scope' => 'session_derived', 'scheduled_for' => now()->subMinute()]);
        $this->artisan('ravan:purge')->assertSuccessful();
        $this->assertDatabaseHas('data_deletion_requests', ['status' => 'completed']);
    }
}
