<?php

use App\Http\Controllers\Api\Admin\ClinicianVerificationController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BehaviorEventController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClinicalNoteController;
use App\Http\Controllers\Api\ClinicianController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\TranscriptController;
use App\Http\Controllers\Webhook\AnalysisEventController;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\VerifyAnalysisSignature;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- public
Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('clinicians', [ClinicianController::class, 'index']);
Route::get('clinicians/specialties', [ClinicianController::class, 'specialties']);
Route::get('clinicians/{clinician}', [ClinicianController::class, 'show']);
Route::get('clinicians/{clinician}/availability', [ClinicianController::class, 'availability']);
Route::get('consents/texts', [ConsentController::class, 'texts']);
Route::get('health', fn () => ['ok' => true, 'time' => now()->toIso8601String()]);

// ---------------------------------------------------------------- analysis-service webhook (HMAC signed)
Route::post('webhooks/analysis/events', [AnalysisEventController::class, 'store'])->middleware(VerifyAnalysisSignature::class);

// ---------------------------------------------------------------- authenticated
Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('appointments', [AppointmentController::class, 'index']);
    Route::post('appointments', [AppointmentController::class, 'store'])->middleware(EnsureRole::class.':patient');
    Route::post('appointments/{appointment}/confirm', [AppointmentController::class, 'confirm']);
    Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);

    Route::prefix('sessions/{session}')->group(function () {
        Route::get('/', [SessionController::class, 'show']);
        Route::post('join', [SessionController::class, 'join']);
        Route::post('end', [SessionController::class, 'end']);
        Route::post('analysis/start', [SessionController::class, 'startAnalysis']);
        Route::post('analysis/pause', [SessionController::class, 'pauseAnalysis']);
        Route::post('analysis/mark', [SessionController::class, 'clinicianMark']);
        Route::post('analysis/topic', [SessionController::class, 'topic']);

        Route::post('consents', [ConsentController::class, 'grant']);
        Route::post('consents/withdraw', [ConsentController::class, 'withdraw']);

        Route::get('messages', [MessageController::class, 'index']);
        Route::post('messages', [MessageController::class, 'store']);

        Route::get('transcript', [TranscriptController::class, 'index']);
        Route::post('transcript', [TranscriptController::class, 'store']);

        Route::get('events', [BehaviorEventController::class, 'index']);
        Route::get('notes', [ClinicalNoteController::class, 'index']);
        Route::post('notes', [ClinicalNoteController::class, 'store']);
        Route::get('report', [ReportController::class, 'show']);
        Route::post('report/review', [ReportController::class, 'review']);
    });
    Route::get('events/{event}', [BehaviorEventController::class, 'show']);
    Route::post('events/{event}/review', [BehaviorEventController::class, 'review']);

    Route::get('catalog/signals', [CatalogController::class, 'index']);
    Route::get('catalog/signals/{signalId}', [CatalogController::class, 'show']);

    // ------------------------------------------------------------ admin
    Route::prefix('admin')->middleware(EnsureRole::class.':admin')->group(function () {
        Route::get('clinicians', [ClinicianVerificationController::class, 'index']);
        Route::post('clinicians', [ClinicianVerificationController::class, 'store']);
        Route::post('clinicians/{clinician}/documents', [ClinicianVerificationController::class, 'uploadDocument']);
        Route::get('clinicians/{clinician}/documents/{documentId}', [ClinicianVerificationController::class, 'document']);
        Route::post('clinicians/{clinician}/decision', [ClinicianVerificationController::class, 'decide']);
        Route::post('specialties', [ClinicianVerificationController::class, 'specialtiesStore']);
    });
});
