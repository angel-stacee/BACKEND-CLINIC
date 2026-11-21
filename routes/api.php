<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\StudentController;
use App\Http\Controllers\ClinicVisitController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\MedicineController;
use App\Http\Controllers\AiChatbotController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\OrganizationController;

Route::get('/organization', [OrganizationController::class, 'index']);

Route::get('/login', function () {
    $frontendUrl = rtrim((string) config('services.frontend.url', 'http://localhost:5173'), '/');

    return redirect()->away($frontendUrl . '/login');
});
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password/send-otp', [AuthController::class, 'sendResetOtp']);
Route::post('/forgot-password/verify-otp', [AuthController::class, 'verifyResetOtp']);
Route::post('/forgot-password/reset', [AuthController::class, 'resetPasswordWithOtp']);

Route::get('/init-db', function () {
    try {
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        return response()->json([
            'status' => 'success',
            'message' => 'Database migrated successfully!',
            'output' => \Illuminate\Support\Facades\Artisan::output(),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], 500);
    }
});
             
Route::get('/test-students', function () {
    return response()->json(
        \App\Models\Student::with('clinicVisits')
            ->latest()
            ->paginate(25)
    );
});

Route::get('/db-info', function () {
    return response()->json([
        'driver' => \Illuminate\Support\Facades\DB::getDriverName(),
        'database' => \Illuminate\Support\Facades\DB::getDatabaseName(),
        'connection' => config('database.default'),
        'base_path' => base_path(),
        'public_html' => realpath(base_path('..')),
        'env_file_exists' => file_exists(base_path('.env')),
        'db_connection_env' => env('DB_CONNECTION'),
        'db_database_env' => env('DB_DATABASE'),
    ]);
});


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/password', [AuthController::class, 'changePassword']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/clinic-visit-options', [ClinicVisitController::class, 'options']);
    Route::get('/patients/check-id', [StudentController::class, 'checkId']);
    Route::get('/students/{student}/medical-history', [StudentController::class, 'medicalHistory']);
    Route::get('/staff/{staff}/medical-history', [StaffController::class, 'medicalHistory']);
    Route::get('/faculties/{faculty}/medical-history', [FacultyController::class, 'medicalHistory']);

    // Trash & Restore routes (must be placed before apiResource to avoid route collision)
    Route::get('/students/trashed', [StudentController::class, 'trashed']);
    Route::post('/students/{id}/restore', [StudentController::class, 'restore']);
    Route::delete('/students/{id}/force', [StudentController::class, 'forceDelete']);

    Route::get('/staff/trashed', [StaffController::class, 'trashed']);
    Route::post('/staff/{id}/restore', [StaffController::class, 'restore']);
    Route::delete('/staff/{id}/force', [StaffController::class, 'forceDelete']);

    Route::get('/faculties/trashed', [FacultyController::class, 'trashed']);
    Route::post('/faculties/{id}/restore', [FacultyController::class, 'restore']);
    Route::delete('/faculties/{id}/force', [FacultyController::class, 'forceDelete']);

    Route::get('/clinic-visits/trashed', [ClinicVisitController::class, 'trashed']);
    Route::post('/clinic-visits/{id}/restore', [ClinicVisitController::class, 'restore']);
    Route::delete('/clinic-visits/{id}/force', [ClinicVisitController::class, 'forceDelete']);

    Route::apiResource('students', StudentController::class);
    Route::apiResource('staff', StaffController::class);
    Route::apiResource('faculties', FacultyController::class);
    Route::apiResource('clinic-visits', ClinicVisitController::class);
    Route::get('/nurses', [ClinicVisitController::class, 'nurses']);
    Route::get('/medicines/trashed', [MedicineController::class, 'trashed']);
    Route::post('/medicines/{id}/restore', [MedicineController::class, 'restore']);
    Route::delete('/medicines/{id}/force', [MedicineController::class, 'forceDelete']);

    Route::apiResource('medicines', MedicineController::class);
    Route::post('/ai-chat', [AiChatbotController::class, 'chat']);
    Route::get('/reports/monthly', [ReportController::class, 'monthly']);

    // Organization Management (Database-backed)
    Route::post('/organization/members', [OrganizationController::class, 'saveMember']);
    Route::put('/organization/members/{id}', [OrganizationController::class, 'saveMember']);
    Route::delete('/organization/members/{id}', [OrganizationController::class, 'deleteMember']);
    Route::post('/organization/reset', [OrganizationController::class, 'resetDefaults']);
    Route::put('/organization/categories/{category}', [OrganizationController::class, 'updateCategory']);
});
