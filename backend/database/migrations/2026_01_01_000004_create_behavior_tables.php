<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The signal catalog (seeded from catalog/signal_catalog.json) so the UI, reports and
        // per-clinician preferences can reference signals by id with bilingual text.
        Schema::create('behavior_signals', function (Blueprint $table) {
            $table->id();
            $table->string('signal_id', 80)->unique();
            $table->string('group', 48)->index();
            $table->string('tier', 24)->index();
            $table->string('observation_en');
            $table->string('observation_fa');
            $table->string('observation_tr');
            $table->json('features');
            $table->json('detector');
            $table->json('quality_gates');
            $table->json('possible_contexts');
            $table->text('clinical_rationale_en')->nullable();
            $table->text('clinical_rationale_fa')->nullable();
            $table->text('clinical_rationale_tr')->nullable();
            $table->text('clinical_note_en')->nullable();
            $table->text('clinical_note_fa')->nullable();
            $table->text('clinical_note_tr')->nullable();
            $table->json('forbidden_labels');
            $table->string('catalog_version', 16);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        // Per-clinician display preferences (e.g. hide 'observation' tier, mute a group).
        Schema::create('clinician_signal_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinician_id')->constrained('users')->cascadeOnDelete();
            $table->string('signal_id', 80)->nullable();   // null = whole group
            $table->string('group', 48)->nullable();
            $table->boolean('muted')->default(false);
            $table->unsignedTinyInteger('min_confidence_pct')->default(0);
            $table->timestamps();
            $table->unique(['clinician_id', 'signal_id', 'group']);
        });

        Schema::create('behavior_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('therapy_session_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 64);
            $table->string('speaker_state', 24)->default('any');
            $table->double('median')->nullable();
            $table->double('sigma')->nullable();
            $table->double('rate_per_min')->nullable();
            $table->unsignedInteger('n')->default(0);
            $table->unsignedInteger('coverage_s')->default(0);
            $table->decimal('quality_fraction', 4, 3)->nullable();
            $table->timestamps();
            $table->unique(['therapy_session_id', 'feature', 'speaker_state']);
        });

        Schema::create('behavior_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                 // id assigned by the analysis service
            $table->foreignId('therapy_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->string('signal_id', 80)->index();
            $table->string('group', 48)->index();
            $table->string('tier', 24)->index();
            $table->unsignedInteger('t_start_ms')->index();
            $table->unsignedInteger('t_end_ms');
            $table->string('observation_en');
            $table->string('observation_fa');
            $table->string('observation_tr');
            $table->double('baseline_value')->nullable();
            $table->double('observed_value')->nullable();
            $table->double('delta')->nullable();
            $table->double('delta_ratio')->nullable();
            $table->double('z_score')->nullable();
            $table->string('unit', 24)->nullable();
            $table->decimal('confidence', 4, 3);
            $table->json('quality')->nullable();
            $table->json('context')->nullable();            // speaker, topic, preceding question, member signals
            $table->json('possible_contexts');
            $table->text('clinical_rationale_en')->nullable();
            $table->text('clinical_rationale_fa')->nullable();
            $table->text('clinical_rationale_tr')->nullable();
            $table->text('clinical_note_en')->nullable();
            $table->text('clinical_note_fa')->nullable();
            $table->text('clinical_note_tr')->nullable();
            $table->json('member_event_uuids')->nullable();
            $table->foreignId('transcript_segment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('clinician_status', 20)->default('unreviewed')->index();
            $table->timestamps();
            $table->index(['therapy_session_id', 't_start_ms']);
        });

        Schema::create('clinician_event_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('behavior_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinician_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20);                   // relevant | dismissed | noted
            $table->text('note')->nullable();
            $table->string('selected_context', 48)->nullable();  // which possible_context the clinician chose
            $table->timestamps();
        });

        Schema::create('clinical_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('therapy_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinician_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('t_ms')->nullable();
            $table->text('body');                           // clinician-authored; never AI-authored
            $table->boolean('is_private')->default(true);
            $table->timestamps();
        });

        Schema::create('session_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('therapy_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('structured');                     // the analysis service SessionReport
            $table->text('ai_draft_summary')->nullable();
            $table->string('ai_provider', 32)->nullable();
            $table->json('guardrail_removed')->nullable();
            $table->string('status', 20)->default('draft'); // draft | reviewed | finalized
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('report_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('items');                          // [{key, ai_text, clinician_text, status}]
            $table->text('summary')->nullable();            // clinician's final summary text
            $table->timestamps();
            $table->unique(['session_report_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_versions');
        Schema::dropIfExists('session_reports');
        Schema::dropIfExists('clinical_notes');
        Schema::dropIfExists('clinician_event_reviews');
        Schema::dropIfExists('behavior_events');
        Schema::dropIfExists('behavior_baselines');
        Schema::dropIfExists('clinician_signal_preferences');
        Schema::dropIfExists('behavior_signals');
    }
};
