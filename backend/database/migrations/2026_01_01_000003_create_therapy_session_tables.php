<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Named therapy_sessions to avoid the framework's HTTP `sessions` table.
        Schema::create('therapy_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('clinician_id')->constrained('users')->cascadeOnDelete();
            $table->string('mode', 10);
            $table->string('status', 20)->default('scheduled')->index();
            $table->string('room_name', 128)->nullable()->unique();   // SFU room
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_s')->nullable();
            $table->boolean('analysis_enabled')->default(false);        // effective (consented + not paused)
            $table->timestamp('analysis_started_at')->nullable();
            $table->timestamp('analysis_paused_at')->nullable();
            $table->string('analysis_session_ref', 64)->nullable();    // id at the analysis service
            $table->string('language', 8)->default('fa');
            $table->json('quality_summary')->nullable();
            $table->json('baseline_summary')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('session_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('therapy_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);                  // patient | clinician | observer
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->json('device_info')->nullable();     // browser, camera resolution, fps, no identifiers
            $table->timestamps();
            $table->unique(['therapy_session_id', 'user_id']);
        });

        Schema::create('consent_versions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);                  // video_call | behavior_analysis | transcription
            $table->string('version', 16);
            $table->string('locale', 8)->default('fa');
            $table->text('title');
            $table->longText('body');                    // full text shown to the patient
            $table->json('bullet_points')->nullable();   // short, patient-facing summary
            $table->boolean('is_current')->default(false);
            $table->timestamps();
            $table->unique(['type', 'version', 'locale']);
        });

        Schema::create('session_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('therapy_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('version', 16);
            $table->timestamp('granted_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['therapy_session_id', 'type']);
        });

        Schema::create('session_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('therapy_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->unsignedInteger('t_ms')->nullable();   // session-relative time
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['therapy_session_id', 'created_at']);
        });

        Schema::create('transcript_segments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('therapy_session_id')->constrained()->cascadeOnDelete();
            $table->string('speaker', 16);               // patient | clinician | unknown
            $table->unsignedInteger('t_start_ms')->index();
            $table->unsignedInteger('t_end_ms');
            $table->text('text');
            $table->decimal('confidence', 4, 3)->default(1.000);
            $table->boolean('is_question')->default(false);
            $table->string('topic', 64)->nullable();
            $table->string('language', 8)->default('fa');
            $table->json('features')->nullable();        // language features computed for this segment
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcript_segments');
        Schema::dropIfExists('session_messages');
        Schema::dropIfExists('session_consents');
        Schema::dropIfExists('consent_versions');
        Schema::dropIfExists('session_participants');
        Schema::dropIfExists('therapy_sessions');
    }
};
