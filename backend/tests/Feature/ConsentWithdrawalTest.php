<?php

namespace Tests\Feature;

use App\Enums\ConsentType;
use App\Models\AsrJob;
use App\Models\BehaviorEvent;
use App\Models\ClinicianProfile;
use App\Models\DataDeletionRequest;
use App\Models\TherapySession;
use App\Models\TranscriptSegment;
use App\Models\User;
use App\Services\AnalysisServiceClient;
use Database\Seeders\ConsentTextSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Withdrawing a consent has to take effect: stop the processing it permitted,
 * and remove what was already captured under it.
 */
class ConsentWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    protected User $patient;

    protected TherapySession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([DemoSeeder::class, ConsentTextSeeder::class]);
        config([
            'ravan.analysis.webhook_secret' => 'test-secret',
            'ravan.analysis.base_url' => 'http://analysis.test',
            'ravan.analysis.token' => 'service-secret',
        ]);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->patient = User::where('email', 'patient@ravan.local')->first();
        $clinician = User::where('email', 'dr.sara@ravan.local')->first();
        $profile = ClinicianProfile::where('user_id', $clinician->id)->first();
        $appt = $this->actingAs($this->patient)->postJson('/api/appointments', [
            'clinician_profile_id' => $profile->id, 'starts_at' => now()->addDay()->toIso8601String(), 'mode' => 'video',
        ])->assertCreated()->json();
        $this->session = TherapySession::where('uuid', $appt['session']['uuid'])->first();

        $this->actingAs($this->patient)
            ->postJson("/api/sessions/{$this->session->uuid}/consents", ['types' => ['video_call', 'behavior_analysis', 'transcription']])
            ->assertCreated();
    }

    protected function withdraw(string $type): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->patient)
            ->postJson("/api/sessions/{$this->session->uuid}/consents/withdraw", ['type' => $type]);
    }

    public function test_withdrawing_transcription_stops_new_segments_and_schedules_deletion(): void
    {
        $this->actingAs($this->patient)->postJson("/api/sessions/{$this->session->uuid}/transcript", [
            'segments' => [['speaker' => 'patient', 't_start_ms' => 0, 't_end_ms' => 1200, 'text' => 'said before withdrawal']],
        ])->assertCreated();

        $this->withdraw('transcription')->assertOk()->assertJson(['transcription_allowed' => false]);

        $this->actingAs($this->patient)->postJson("/api/sessions/{$this->session->uuid}/transcript", [
            'segments' => [['speaker' => 'patient', 't_start_ms' => 2000, 't_end_ms' => 3000, 'text' => 'said after']],
        ])->assertForbidden();

        $this->assertDatabaseHas('data_deletion_requests', [
            'therapy_session_id' => $this->session->id,
            'scope' => 'session_transcript',
            'status' => 'pending',
        ]);
    }

    public function test_the_purge_actually_removes_withdrawn_transcripts(): void
    {
        TranscriptSegment::create([
            'therapy_session_id' => $this->session->id, 'speaker' => 'patient',
            't_start_ms' => 0, 't_end_ms' => 900, 'text' => 'to be removed', 'language' => 'fa',
        ]);
        AsrJob::create([
            'therapy_session_id' => $this->session->id, 'speaker' => 'patient',
            't_start_ms' => 0, 'duration_ms' => 900,
        ]);

        $this->withdraw('transcription')->assertOk();
        DataDeletionRequest::where('therapy_session_id', $this->session->id)->update(['scheduled_for' => now()->subHour()]);
        $this->artisan('ravan:purge')->assertSuccessful();

        $this->assertSame(0, TranscriptSegment::where('therapy_session_id', $this->session->id)->count());
        $this->assertSame(0, AsrJob::where('therapy_session_id', $this->session->id)->count());
    }

    public function test_withdrawing_the_call_consent_withdraws_the_consents_that_depend_on_it(): void
    {
        $this->withdraw('video_call')->assertOk()
            ->assertJson(['analysis_allowed' => false, 'transcription_allowed' => false]);

        $this->session->refresh();
        $this->assertFalse($this->session->analysis_enabled);
        $this->assertFalse($this->session->hasActiveConsent(ConsentType::BehaviorAnalysis));
        $this->assertFalse($this->session->hasActiveConsent(ConsentType::Transcription));
    }

    public function test_withdrawing_analysis_leaves_transcription_alone(): void
    {
        $this->withdraw('behavior_analysis')->assertOk()
            ->assertJson(['analysis_allowed' => false, 'transcription_allowed' => true]);

        $this->assertDatabaseHas('data_deletion_requests', ['scope' => 'session_derived', 'status' => 'pending']);
        $this->assertDatabaseMissing('data_deletion_requests', ['scope' => 'session_transcript']);

        $this->actingAs($this->patient)->postJson("/api/sessions/{$this->session->uuid}/transcript", [
            'segments' => [['speaker' => 'patient', 't_start_ms' => 0, 't_end_ms' => 800, 'text' => 'still allowed']],
        ])->assertCreated();
    }

    public function test_behaviour_events_are_purged_after_withdrawing_analysis(): void
    {
        BehaviorEvent::create([
            'uuid' => (string) Str::uuid(),
            'therapy_session_id' => $this->session->id, 'patient_id' => $this->patient->id,
            'signal_id' => 'body.posture.lean_change', 'group' => 'torso', 'tier' => 1,
            't_start_ms' => 0, 't_end_ms' => 1000, 'confidence' => 0.8,
            'observation_en' => 'leaned forward', 'observation_fa' => 'به جلو خم شد', 'observation_tr' => 'öne eğildi',
            'possible_contexts' => [],
        ]);
        $this->withdraw('behavior_analysis')->assertOk();
        DataDeletionRequest::where('therapy_session_id', $this->session->id)->update(['scheduled_for' => now()->subHour()]);
        $this->artisan('ravan:purge')->assertSuccessful();

        $this->assertSame(0, BehaviorEvent::where('therapy_session_id', $this->session->id)->count());
    }

    /**
     * The browser is handed a credential for its own session, not the secret
     * the backend and the analysis service share.
     */
    public function test_the_join_response_never_carries_the_analysis_service_secret(): void
    {
        $join = $this->actingAs($this->patient)
            ->postJson("/api/sessions/{$this->session->uuid}/join", ['browser' => 'test'])
            ->assertOk()->json();

        $this->assertArrayHasKey('analysis_ingest', $join);
        $this->assertNotSame('service-secret', $join['analysis_ingest']['token']);
        $this->assertStringNotContainsString('service-secret', json_encode($join));
        $this->assertStringStartsWith("v1.{$this->session->uuid}.", $join['analysis_ingest']['token']);
        $this->assertGreaterThan(time(), $join['analysis_ingest']['expires_at']);
    }

    /** Every recorder in the session gets the same time origin from the server. */
    public function test_the_join_response_carries_a_shared_clock(): void
    {
        $join = $this->actingAs($this->patient)
            ->postJson("/api/sessions/{$this->session->uuid}/join", ['browser' => 'test'])
            ->assertOk()->json();

        $this->assertArrayHasKey('clock', $join);
        $this->assertIsInt($join['clock']['server_now_ms']);
        $this->assertIsInt($join['clock']['session_start_ms']);
    }

    /** The browser must be given a URL it can actually resolve. */
    public function test_the_ingest_url_is_the_public_one_not_the_internal_service_address(): void
    {
        config(['ravan.analysis.public_ws_url' => 'wss://ravan.example/analysis']);
        $url = app(AnalysisServiceClient::class)->ingestUrl($this->session);

        $this->assertSame("wss://ravan.example/analysis/ws/sessions/{$this->session->uuid}", $url);
        $this->assertStringNotContainsString('analysis.test', $url);
    }

    /**
     * Who transcribes the audio is part of what the patient is agreeing to, so
     * a server configured to use a third party has to say so, and a consent
     * given under the local engine must not silently cover the remote one.
     */
    public function test_a_remote_speech_engine_is_disclosed_and_invalidates_local_consent(): void
    {
        $localVersion = config('ravan.consent.current_versions.transcription');
        $this->assertTrue($this->session->hasActiveConsent(ConsentType::Transcription));

        config([
            'ravan.asr.backend' => 'openai',
            'ravan.consent.current_versions.transcription' => $localVersion.'-remote',
        ]);

        $texts = collect($this->getJson('/api/consents/texts?locale=en')->assertOk()->json())
            ->firstWhere('type', 'transcription');
        $this->assertStringContainsString('OpenAI', $texts['body']);

        // The consent granted while everything stayed on this server does not
        // carry over to an arrangement that sends the audio elsewhere.
        $this->assertFalse($this->session->fresh()->hasActiveConsent(ConsentType::Transcription));
    }
}
