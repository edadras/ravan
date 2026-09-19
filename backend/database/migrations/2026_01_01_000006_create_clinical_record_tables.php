<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One clinical record per patient. Clinician-authored; AI never writes into it directly.
        Schema::create('patient_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('primary_clinician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('chief_complaint')->nullable();
            $table->text('history_of_present_illness')->nullable();
            $table->text('psychiatric_history')->nullable();
            $table->text('medical_history')->nullable();
            $table->text('family_history')->nullable();
            $table->text('social_history')->nullable();
            $table->text('substance_use')->nullable();
            $table->json('current_medications')->nullable();   // [{name, dose, since}]
            $table->json('allergies')->nullable();
            $table->text('risk_history')->nullable();           // clinician-documented; never AI-derived
            $table->text('formulation')->nullable();            // clinician's case formulation
            $table->text('treatment_plan')->nullable();
            $table->json('goals')->nullable();
            $table->string('status', 20)->default('active');    // active | closed | transferred
            $table->timestamps();
        });

        // Reference list of diagnostic codes (ICD-11 chapter 06 / DSM-5-TR labels) used for clinician selection.
        Schema::create('diagnosis_codes', function (Blueprint $table) {
            $table->id();
            $table->string('system', 12);                       // icd11 | dsm5tr
            $table->string('code', 24);
            $table->string('label_en');
            $table->string('label_fa');
            $table->string('label_tr');
            $table->string('category', 64)->nullable();
            $table->timestamps();
            $table->unique(['system', 'code']);
        });

        // Diagnoses are always entered or confirmed by the clinician. `source` records whether an AI
        // suggestion was the starting point; `status` tracks provisional → confirmed → ruled_out.
        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinician_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('diagnosis_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');                            // free text if no code
            $table->string('status', 20)->default('provisional'); // provisional | confirmed | ruled_out | resolved
            $table->string('source', 20)->default('clinician'); // clinician | ai_suggested
            $table->foreignId('ai_suggestion_id')->nullable();
            $table->text('evidence')->nullable();               // clinician's reasoning
            $table->date('onset_date')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        // SOAP-style progress notes per session.
        Schema::create('record_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('therapy_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20)->default('progress');    // intake | progress | discharge | phone | other
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->json('mental_status_exam')->nullable();      // structured MSE checklist chosen by clinician
            $table->boolean('is_locked')->default(false);        // locked entries are immutable (amendments append)
            $table->timestamps();
        });

        // Standardised screening instruments (PHQ-9, GAD-7, ...) answered by the patient; scored server-side.
        Schema::create('screening_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('therapy_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('instrument', 24);                   // phq9 | gad7 | pss10 | isi | pcl5 | audit_c
            $table->json('answers');                            // [0..3,...]
            $table->unsignedSmallInteger('total_score');
            $table->string('severity_band', 32)->nullable();    // instrument-specific label
            $table->boolean('item_flag')->default(false);       // e.g. PHQ-9 item 9 > 0 → clinician alerted
            $table->foreignId('administered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Medication list managed by a psychiatrist (or documented from patient report).
        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prescriber_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('dose', 64)->nullable();
            $table->string('frequency', 64)->nullable();
            $table->date('started_at')->nullable();
            $table->date('stopped_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Every AI output offered to the clinician: formulation drafts, differential hypotheses,
        // Q&A response analyses, chat answers. Stored with the exact input hash and the guardrail log.
        Schema::create('ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('therapy_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_record_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 32);                          // formulation | differential | qa_analysis | chat
            $table->json('input_summary')->nullable();           // what was sent (de-identified)
            $table->json('output');                              // structured provider output after guardrail
            $table->json('guardrail_removed')->nullable();
            $table->string('provider', 32);
            $table->string('model', 64)->nullable();
            $table->string('status', 20)->default('pending');    // pending | accepted | edited | rejected
            $table->text('clinician_response')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // Record access is separately controlled and logged: which clinician may read which record.
        Schema::create('record_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinician_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['patient_record_id', 'clinician_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_access_grants');
        Schema::dropIfExists('ai_suggestions');
        Schema::dropIfExists('medications');
        Schema::dropIfExists('screening_results');
        Schema::dropIfExists('record_entries');
        Schema::dropIfExists('diagnoses');
        Schema::dropIfExists('diagnosis_codes');
        Schema::dropIfExists('patient_records');
    }
};
