<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('patient')->index()->after('password');
            $table->string('phone', 32)->nullable()->unique()->after('email');
            $table->string('locale', 8)->default('fa')->after('role');
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });

        // Identity data is separated from session data (see docs/06-privacy-security.md).
        Schema::create('patient_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('pseudonym')->unique(); // used towards the analysis service instead of any identity
            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('preferred_language', 8)->default('fa');
            $table->string('timezone', 64)->default('Asia/Tehran');
            $table->text('emergency_contact_encrypted')->nullable();
            $table->json('accessibility_prefs')->nullable();
            $table->timestamps();
        });

        Schema::create('specialties', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name_fa');
            $table->string('name_en');
            $table->timestamps();
        });

        Schema::create('clinician_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('title', 64)->nullable();            // روان‌شناس بالینی / روان‌پزشک
            $table->string('license_number', 64)->nullable();
            $table->string('license_authority', 128)->nullable(); // سازمان نظام روان‌شناسی / نظام پزشکی
            $table->date('license_expires_at')->nullable();
            $table->text('bio_fa')->nullable();
            $table->text('bio_en')->nullable();
            $table->json('languages')->nullable();
            $table->unsignedInteger('years_experience')->default(0);
            $table->unsignedInteger('session_fee')->default(0);     // smallest currency unit
            $table->string('currency', 8)->default('IRR');
            $table->unsignedSmallInteger('session_length_min')->default(50);
            $table->json('session_modes')->nullable();              // ["text","audio","video"]
            $table->string('verification_status', 20)->default('pending')->index();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('accepts_new_patients')->default(true);
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
            $table->string('avatar_path')->nullable();
            $table->timestamps();
        });

        Schema::create('clinician_specialty', function (Blueprint $table) {
            $table->foreignId('clinician_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('specialty_id')->constrained()->cascadeOnDelete();
            $table->primary(['clinician_profile_id', 'specialty_id']);
        });

        Schema::create('clinician_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinician_profile_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);              // license, degree, id_card, photo
            $table->string('path');                  // private disk
            $table->string('original_name');
            $table->string('mime', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->timestamps();
        });

        Schema::create('clinician_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinician_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 20);          // approved / rejected / suspended / info_requested
            $table->text('notes')->nullable();
            $table->json('checked_items')->nullable(); // {"license_valid":true,"identity_matched":true,...}
            $table->timestamps();
        });

        Schema::create('clinician_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinician_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');   // 0 = Saturday .. 6 = Friday (Iranian week)
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('slot_minutes')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['clinician_profile_id', 'weekday']);
        });

        Schema::create('clinician_time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinician_profile_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinician_time_off');
        Schema::dropIfExists('clinician_schedules');
        Schema::dropIfExists('clinician_verifications');
        Schema::dropIfExists('clinician_documents');
        Schema::dropIfExists('clinician_specialty');
        Schema::dropIfExists('clinician_profiles');
        Schema::dropIfExists('specialties');
        Schema::dropIfExists('patient_profiles');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'phone', 'locale', 'last_login_at', 'is_active', 'deleted_at']);
        });
    }
};
