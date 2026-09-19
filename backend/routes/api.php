<?php

use App\Http\Controllers\Api\Admin\ClinicianVerificationController;
use App\Http\Controllers\Api\AiAssistController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AsrController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BehaviorEventController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClinicalNoteController;
use App\Http\Controllers\Api\ClinicianController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\RecordController;
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
Route::post('auth/code/request', [AuthController::class, 'requestCode'])->middleware('throttle:5,1');
Route::post('auth/code/login', [AuthController::class, 'loginWithCode'])->middleware('throttle:10,1');
Route::post('auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
Route::get('clinicians', [ClinicianController::class, 'index']);
Route::get('clinicians/specialties', [ClinicianController::class, 'specialties']);
Route::get('clinicians/{clinician}', [ClinicianController::class, 'show']);
Route::get('clinicians/{clinician}/availability', [ClinicianController::class, 'availability']);
Route::get('consents/texts', [ConsentController::class, 'texts']);
Route::get('payments/{payment}/callback', [PaymentController::class, 'callback'])->name('payments.callback');
Route::get('health', fn () => ['ok' => true, 'time' => now()->toIso8601String()]);

// ---------------------------------------------------------------- analysis-service webhook (HMAC signed)
Route::post('webhooks/analysis/events', [AnalysisEventController::class, 'store'])->middleware(VerifyAnalysisSignature::class);

// ---------------------------------------------------------------- authenticated
Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::patch('auth/me', [AuthController::class, 'updateProfile']);
    Route::post('auth/password/change', [AuthController::class, 'changePassword']);
    Route::post('auth/verify', [AuthController::class, 'verifyContact']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('notifications', fn () => request()->user()->notifications()->limit(50)->get());
    Route::post('notifications/read', function () {
        request()->user()->unreadNotifications->markAsRead();

        return ['ok' => true];
    });

    Route::get('appointments', [AppointmentController::class, 'index']);
    Route::post('appointments', [AppointmentController::class, 'store'])->middleware(EnsureRole::class.':patient');
    Route::post('appointments/{appointment}/confirm', [AppointmentController::class, 'confirm']);
    Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
    Route::post('appointments/{appointment}/pay', [PaymentController::class, 'initiate']);
    Route::get('payments', [PaymentController::class, 'index']);

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
        Route::post('asr/chunk', [AsrController::class, 'chunk']);

        Route::get('events', [BehaviorEventController::class, 'index']);
        Route::get('notes', [ClinicalNoteController::class, 'index']);
        Route::post('notes', [ClinicalNoteController::class, 'store']);
        Route::get('report', [ReportController::class, 'show']);
        Route::post('report/review', [ReportController::class, 'review']);

        // clinical decision support (clinician only, every output stored for accept/edit/reject)
        Route::get('ai', [AiAssistController::class, 'index']);
        Route::post('ai/formulation', [AiAssistController::class, 'formulation']);
        Route::post('ai/qa', [AiAssistController::class, 'qaAnalysis']);
        Route::post('ai/chat', [AiAssistController::class, 'chat']);
    });
    Route::post('ai/suggestions/{suggestion}/review', [AiAssistController::class, 'review']);
    Route::get('events/{event}', [BehaviorEventController::class, 'show']);
    Route::post('events/{event}/review', [BehaviorEventController::class, 'review']);

    // clinical record
    Route::get('records/codes', [RecordController::class, 'diagnosisCodes']);
    Route::get('records/instruments', [RecordController::class, 'instruments']);
    Route::prefix('records/{patient}')->group(function () {
        Route::get('/', [RecordController::class, 'show']);
        Route::patch('intake', [RecordController::class, 'updateIntake']);
        Route::patch('clinical', [RecordController::class, 'updateClinical']);
        Route::post('entries', [RecordController::class, 'addEntry']);
        Route::post('diagnoses', [RecordController::class, 'addDiagnosis']);
        Route::patch('diagnoses/{diagnosis}', [RecordController::class, 'updateDiagnosis']);
        Route::post('screenings', [RecordController::class, 'addScreening']);
        Route::post('medications', [RecordController::class, 'addMedication']);
        Route::post('access', [RecordController::class, 'grantAccess']);
    });

    // direct messaging
    Route::get('conversations', [ConversationController::class, 'index']);
    Route::post('conversations/with/{user}', [ConversationController::class, 'open']);
    Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages']);
    Route::post('conversations/{conversation}/messages', [ConversationController::class, 'send']);
    Route::post('conversations/{conversation}/read', [ConversationController::class, 'markRead']);

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
