<?php

use App\Http\Controllers\Api\v1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Version 1 (/api/v1/)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // Public Authentication Endpoints (Rate limited)
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('/register-student', [AuthController::class, 'registerStudent'])->middleware('throttle:5,1');
        Route::post('/2fa/verify', [AuthController::class, 'verify2fa'])->middleware('throttle:10,1');

        // Authenticated Auth Endpoints
        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/refresh', [AuthController::class, 'refresh'])->withoutMiddleware(['auth:sanctum', 'active']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/2fa/setup', [AuthController::class, 'setup2fa']);
            Route::post('/2fa/enable', [AuthController::class, 'enable2fa']);
            Route::post('/2fa/disable', [AuthController::class, 'disable2fa']);
        });
    });

    // Student Endpoints (Strictly NO movement history per requirement)
    Route::prefix('student')->middleware(['auth:sanctum', 'active', 'role:STUDENT'])->group(function () {
        Route::get('/profile', [\App\Http\Controllers\Api\v1\StudentController::class, 'profile']);
        Route::put('/profile', [\App\Http\Controllers\Api\v1\StudentController::class, 'updateProfile']);
        Route::post('/profile/photo', [\App\Http\Controllers\Api\v1\StudentController::class, 'uploadPhoto']);
        Route::delete('/profile/photo', [\App\Http\Controllers\Api\v1\StudentController::class, 'deletePhoto']);
        Route::get('/status', [\App\Http\Controllers\Api\v1\StudentController::class, 'status']);
    });

    // Gate & Movement Endpoints
    Route::prefix('gates')->middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\v1\GateController::class, 'index']);
        Route::get('/{gate}', [\App\Http\Controllers\Api\v1\GateController::class, 'show']);
        Route::post('/{gate}/qr', [\App\Http\Controllers\Api\v1\GateController::class, 'generateQr']);
    });

    Route::prefix('gate-entry')->middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/options', [\App\Http\Controllers\Api\v1\GateEntryController::class, 'options']);
        Route::post('/verify-qr', [\App\Http\Controllers\Api\v1\GateEntryController::class, 'verifyQr'])->middleware('throttle:30,1');
        Route::post('/in', [\App\Http\Controllers\Api\v1\GateEntryController::class, 'in'])->middleware('throttle:30,1');
        Route::post('/out', [\App\Http\Controllers\Api\v1\GateEntryController::class, 'out'])->middleware('throttle:30,1');
    });

    // Security Guard Endpoints
    Route::prefix('security')->middleware(['auth:sanctum', 'active', 'role:SECURITY'])->group(function () {
        // Pre-duty authentication endpoints
        Route::get('/duty/active', [\App\Http\Controllers\Api\v1\SecurityController::class, 'activeDuty']);
        Route::post('/duty/activate', [\App\Http\Controllers\Api\v1\SecurityController::class, 'activateDuty'])->middleware('throttle:5,1');

        // Operational endpoints (STRICTLY REQUIRE active Admin-authorized duty session)
        Route::middleware(['duty.active'])->group(function () {
            Route::post('/duty/end', [\App\Http\Controllers\Api\v1\SecurityController::class, 'endDuty']);
            Route::get('/today', [\App\Http\Controllers\Api\v1\SecurityController::class, 'todayMovements']);
            Route::get('/students/search', [\App\Http\Controllers\Api\v1\SecurityController::class, 'searchStudent']);
            Route::get('/students/{student}', [\App\Http\Controllers\Api\v1\SecurityController::class, 'showStudent']);
            Route::get('/students/{student}/history', [\App\Http\Controllers\Api\v1\SecurityController::class, 'studentHistory']);
            Route::get('/history', [\App\Http\Controllers\Api\v1\SecurityController::class, 'history']);
            Route::get('/live-stream', [\App\Http\Controllers\Api\v1\SecurityController::class, 'liveStream']);
            Route::post('/manual-movement', [\App\Http\Controllers\Api\v1\SecurityController::class, 'manualMovement'])->middleware('throttle:30,1');
        });
    });

    // Admin Endpoints
    Route::prefix('admin')->middleware(['auth:sanctum', 'active', 'role:ADMIN'])->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Api\v1\AdminController::class, 'dashboard']);
        Route::get('/students', [\App\Http\Controllers\Api\v1\AdminController::class, 'students']);
        Route::post('/students', [\App\Http\Controllers\Api\v1\AdminController::class, 'storeStudent']);
        Route::get('/students/{student}', [\App\Http\Controllers\Api\v1\AdminController::class, 'showStudent']);
        Route::put('/students/{student}', [\App\Http\Controllers\Api\v1\AdminController::class, 'updateStudent']);
        Route::post('/students/{student}/photo', [\App\Http\Controllers\Api\v1\AdminController::class, 'uploadStudentPhoto']);
        Route::delete('/students/{student}/photo', [\App\Http\Controllers\Api\v1\AdminController::class, 'deleteStudentPhoto']);
        Route::post('/students/{student}/approve', [\App\Http\Controllers\Api\v1\AdminController::class, 'approveStudent']);
        Route::post('/students/{student}/reject', [\App\Http\Controllers\Api\v1\AdminController::class, 'rejectStudent']);
        Route::post('/students/{student}/suspend', [\App\Http\Controllers\Api\v1\AdminController::class, 'suspendStudent']);
        Route::post('/students/{student}/reactivate', [\App\Http\Controllers\Api\v1\AdminController::class, 'reactivateStudent']);
        Route::get('/security', [\App\Http\Controllers\Api\v1\AdminController::class, 'securityGuards']);
        Route::post('/security', [\App\Http\Controllers\Api\v1\AdminController::class, 'storeSecurityGuard']);
        Route::post('/security/sessions/{session}/force-end', [\App\Http\Controllers\Api\v1\AdminController::class, 'forceEndDutySession']);
        Route::post('/security/duty-otp', [\App\Http\Controllers\Api\v1\AdminController::class, 'generateDutyOtp']);
        Route::get('/gates', [\App\Http\Controllers\Api\v1\AdminController::class, 'gates']);
        Route::post('/gates', [\App\Http\Controllers\Api\v1\AdminController::class, 'storeGate']);
        Route::get('/movements', [\App\Http\Controllers\Api\v1\AdminController::class, 'movements']);
        Route::get('/movements/export', [\App\Http\Controllers\Api\v1\AdminController::class, 'exportMovements']);
        Route::get('/reports/movements', [\App\Http\Controllers\Api\v1\AdminController::class, 'movementReports']);
        Route::get('/reports/students/{student}', [\App\Http\Controllers\Api\v1\AdminController::class, 'studentReport']);
        Route::get('/audit-logs', [\App\Http\Controllers\Api\v1\AdminController::class, 'auditLogs']);
    });

    // Real-Time Notification Endpoints (All authenticated active roles)
    Route::prefix('notifications')->middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\v1\NotificationController::class, 'index']);
        Route::get('/unread-count', [\App\Http\Controllers\Api\v1\NotificationController::class, 'unreadCount']);
        Route::post('/{id}/read', [\App\Http\Controllers\Api\v1\NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [\App\Http\Controllers\Api\v1\NotificationController::class, 'markAllAsRead']);
    });

    // Media Route (Profile Photos - authenticated, active session, role-checked inside MediaController)
    Route::get('/media/students/{student}/photo', [\App\Http\Controllers\Api\v1\MediaController::class, 'studentPhoto'])
        ->name('api.media.student-photo')
        ->middleware(['auth:sanctum', 'active']);
});
