<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One-time codes for e-mail/phone verification and password reset (hashed, short-lived).
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 8);                 // email | sms
            $table->string('target');                     // e-mail or phone
            $table->string('purpose', 24);                // verify | reset | login
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['target', 'purpose']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 16)->nullable();
        });

        // Direct (off-session) messaging between a patient and their clinician, with read receipts.
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('clinician_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['patient_id', 'clinician_id']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::table('session_messages', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('t_ms');
        });

        // ASR jobs: audio chunks are transcribed by the ASR service; we keep only the job metadata.
        Schema::create('asr_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('therapy_session_id')->constrained()->cascadeOnDelete();
            $table->string('speaker', 16);
            $table->unsignedInteger('t_start_ms');
            $table->unsignedInteger('duration_ms');
            $table->string('status', 16)->default('queued');   // queued | done | failed
            $table->string('backend', 24)->nullable();
            $table->unsignedSmallInteger('segments')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asr_jobs');
        Schema::table('session_messages', fn (Blueprint $t) => $t->dropColumn('delivered_at'));
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['phone_verified_at', 'terms_accepted_at', 'terms_version']));
        Schema::dropIfExists('verification_codes');
    }
};
