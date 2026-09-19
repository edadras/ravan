<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64)->index();          // consent.granted, event.reviewed, report.finalized ...
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });

        // Every read of session content (transcript, events, report) is logged for the patient's access report.
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('therapy_session_id')->constrained()->cascadeOnDelete();
            $table->string('resource', 32);                 // transcript | events | report | messages
            $table->string('purpose', 32)->default('care'); // care | emergency | audit | export
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['therapy_session_id', 'created_at']);
        });

        Schema::create('data_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('therapy_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 32);                    // session_derived | all_sessions | account
            $table->string('status', 20)->default('pending');
            $table->timestamp('scheduled_for');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('data_deletion_requests');
        Schema::dropIfExists('access_logs');
        Schema::dropIfExists('audit_logs');
    }
};
