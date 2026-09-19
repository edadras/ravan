<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('clinician_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at');
            $table->string('mode', 10);                  // text | audio | video
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('fee')->default(0);
            $table->string('currency', 8)->default('IRR');
            $table->text('patient_note')->nullable();     // reason for visit, free text (encrypted at rest by DB)
            $table->string('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['clinician_id', 'starts_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('currency', 8)->default('IRR');
            $table->string('gateway', 32);               // zarinpal, idpay, stripe, manual
            $table->string('gateway_ref')->nullable()->index();
            $table->string('status', 20)->default('initiated')->index(); // initiated|paid|failed|refunded
            $table->json('gateway_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32)->unique();
            $table->unsignedInteger('amount');
            $table->unsignedInteger('platform_fee')->default(0);
            $table->unsignedInteger('clinician_share')->default(0);
            $table->string('pdf_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('appointments');
    }
};
