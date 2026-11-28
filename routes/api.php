<?php

use App\Http\Controllers\AiChatbotController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClinicInnovationController;
use App\Http\Controllers\ClinicQueueController;
use App\Http\Controllers\ClinicVisitController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\MedicationScheduleController;
use App\Http\Controllers\MedicineController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;

Route::get('/organization', [OrganizationController::class, 'index']);
Route::post('/public-ai-chat', [AiChatbotController::class, 'publicChat'])->middleware('throttle:public-ai');

Route::get('/login', function () {
    $frontendUrl = rtrim((string) config('services.frontend.url', 'http://localhost:5173'), '/');

    return redirect()->away($frontendUrl.'/login');
});

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/forgot-password/send-otp', [AuthController::class, 'sendResetOtp'])->middleware('throttle:otp');
Route::post('/forgot-password/verify-otp', [AuthController::class, 'verifyResetOtp'])->middleware('throttle:otp');
Route::post('/forgot-password/reset', [AuthController::class, 'resetPasswordWithOtp'])->middleware('throttle:otp');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Self-service authenticated endpoints (any authenticated clinic user)
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/password', [AuthController::class, 'changePassword']);

    // Notifications (scoped to authenticated user in controller)
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications', [NotificationController::class, 'clearAll']);

    // Clinical & Inventory Management (Admin & Nurse only) - trashed routes placed before {id} show routes
    Route::middleware('role:Admin,Nurse')->group(function () {
        Route::get('/students/trashed', [StudentController::class, 'trashed']);
        Route::post('/students/{id}/restore', [StudentController::class, 'restore']);
        Route::post('/students/{student}/qr-regenerate', [StudentController::class, 'regenerateQr']);
        Route::delete('/students/{student}', [StudentController::class, 'destroy']);

        Route::get('/staff/trashed', [StaffController::class, 'trashed']);
        Route::post('/staff/{id}/restore', [StaffController::class, 'restore']);
        Route::post('/staff/{staff}/qr-regenerate', [StaffController::class, 'regenerateQr']);
        Route::delete('/staff/{staff}', [StaffController::class, 'destroy']);

        Route::get('/faculties/trashed', [FacultyController::class, 'trashed']);
        Route::post('/faculties/{id}/restore', [FacultyController::class, 'restore']);
        Route::post('/faculties/{faculty}/qr-regenerate', [FacultyController::class, 'regenerateQr']);
        Route::delete('/faculties/{faculty}', [FacultyController::class, 'destroy']);

        Route::get('/clinic-visits/trashed', [ClinicVisitController::class, 'trashed']);
        Route::post('/clinic-visits/{id}/restore', [ClinicVisitController::class, 'restore']);
        Route::delete('/clinic-visits/{clinic_visit}', [ClinicVisitController::class, 'destroy']);

        Route::get('/medicines/trashed', [MedicineController::class, 'trashed']);
        Route::post('/medicines/{id}/restore', [MedicineController::class, 'restore']);
        Route::post('/medicines/batches', [MedicineController::class, 'storeBatch']);
        Route::post('/medicines/dispense', [MedicineController::class, 'dispense']);
        Route::apiResource('medicines', MedicineController::class)->only(['store', 'update', 'destroy']);

        Route::delete('/medication-schedules/{medication_schedule}', [MedicationScheduleController::class, 'destroy']);

        Route::patch('/clinic-visits/{id}/follow-up', [ClinicInnovationController::class, 'updateFollowUp']);
        Route::patch('/queue/{id}/status', [ClinicQueueController::class, 'updateStatus']);
    });

    // Authorized Clinic Personnel (Admin, Nurse, Staff) - Read & Standard Clinical Workflows
    Route::middleware('role:Admin,Nurse,Staff')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/clinic-visit-options', [ClinicVisitController::class, 'options']);
        Route::get('/patients/lookup', [ClinicVisitController::class, 'lookupPatient']);
        Route::get('/patients/check-id', [StudentController::class, 'checkId']);
        Route::get('/patients/qr-resolve', [ClinicInnovationController::class, 'qrResolve']);
        Route::post('/patients/qr-send-email', [ClinicInnovationController::class, 'sendQrEmail']);
        Route::get('/patients/{type}/{id}/timeline', [ClinicInnovationController::class, 'patientTimeline']);
        Route::get('/nurses', [ClinicVisitController::class, 'nurses']);
        Route::get('/follow-ups', [ClinicInnovationController::class, 'followUps']);
        Route::get('/queue', [ClinicQueueController::class, 'index']);
        Route::post('/queue', [ClinicQueueController::class, 'store']);

        Route::get('/students/{student}/medical-history', [StudentController::class, 'medicalHistory']);
        Route::get('/staff/{staff}/medical-history', [StaffController::class, 'medicalHistory']);
        Route::get('/faculties/{faculty}/medical-history', [FacultyController::class, 'medicalHistory']);

        Route::apiResource('students', StudentController::class)->only(['index', 'show', 'store', 'update']);
        Route::apiResource('staff', StaffController::class)->only(['index', 'show', 'store', 'update']);
        Route::apiResource('faculties', FacultyController::class)->only(['index', 'show', 'store', 'update']);
        Route::apiResource('clinic-visits', ClinicVisitController::class)->only(['index', 'show', 'store', 'update']);

        Route::get('/medicines/batches', [MedicineController::class, 'batches']);
        Route::get('/medicines/preview-dispense', [MedicineController::class, 'previewDispense']);
        Route::get('/medicines/transactions', [MedicineController::class, 'transactions']);
        Route::get('/medicines/analytics/most-used', [MedicineController::class, 'mostUsed']);
        Route::get('/medicines/analytics/stock-trend', [MedicineController::class, 'stockTrend']);
        Route::get('/medicines/expiry-intelligence', [ClinicInnovationController::class, 'expiryIntelligence']);
        Route::get('/medicines/forecast', [ClinicInnovationController::class, 'medicineForecast']);
        Route::get('/medicines/requisitions', [MedicineController::class, 'getRequisitions']);
        Route::post('/medicines/requisitions', [MedicineController::class, 'storeRequisition']);
        Route::apiResource('medicines', MedicineController::class)->only(['index', 'show']);

        Route::apiResource('medication-schedules', MedicationScheduleController::class)->only(['index', 'show', 'store', 'update']);

        Route::post('/ai-chat', [AiChatbotController::class, 'chat']);

        Route::get('/reports/monthly', [ReportController::class, 'monthly']);
        Route::get('/reports/analytics', [ReportController::class, 'analytics']);
        Route::get('/reports/medicine', [ReportController::class, 'medicineReport']);
        Route::get('/reports/health-trends', [ClinicInnovationController::class, 'healthTrends']);
    });

    // Administrator-only endpoints (Permanent deletions, Requisition approvals, Organization config)
    Route::middleware('role:Admin')->group(function () {
        Route::delete('/students/{id}/force', [StudentController::class, 'forceDelete']);
        Route::delete('/staff/{id}/force', [StaffController::class, 'forceDelete']);
        Route::delete('/faculties/{id}/force', [FacultyController::class, 'forceDelete']);
        Route::delete('/clinic-visits/{id}/force', [ClinicVisitController::class, 'forceDelete']);
        Route::delete('/medicines/{id}/force', [MedicineController::class, 'forceDelete']);

        Route::patch('/medicines/requisitions/{id}/status', [MedicineController::class, 'updateRequisitionStatus']);

        Route::post('/organization/members', [OrganizationController::class, 'saveMember']);
        Route::put('/organization/members/{id}', [OrganizationController::class, 'saveMember']);
        Route::delete('/organization/members/{id}', [OrganizationController::class, 'deleteMember']);
        Route::post('/organization/reset', [OrganizationController::class, 'resetDefaults']);
        Route::put('/organization/categories/{category}', [OrganizationController::class, 'updateCategory']);
        Route::get('/audit-logs', [ClinicInnovationController::class, 'auditLogs']);
    });
});
