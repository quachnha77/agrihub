<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\Platform\HealthController as PlatformHealthController;
use App\Http\Controllers\Api\V1\Platform\PlanController;
use App\Http\Controllers\Api\V1\Platform\PlatformBackupController;
use App\Http\Controllers\Api\V1\Platform\SubscriptionController as PlatformSubscriptionController;
use App\Http\Controllers\Api\V1\Platform\SupportSessionController;
use App\Http\Controllers\Api\V1\Platform\TenantBackupController;
use App\Http\Controllers\Api\V1\Platform\TenantController;
use App\Http\Controllers\Api\V1\Platform\UserController as PlatformUserController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TenantContextController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

// Prefix /api/v1 được cấu hình trong bootstrap/app.php
Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);
Route::get('/health/monitor', [HealthController::class, 'monitor'])->middleware('throttle:60,1');

// Công khai: địa chỉ này là của nền tảng hay doanh nghiệp nào (để trang đăng nhập hiển thị đúng tên).
Route::get('/tenant-context', [TenantContextController::class, 'show'])->middleware('throttle:60,1');

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'tenant.context'])->group(function () {
    // Luôn dùng được, kể cả khi đang phải đổi mật khẩu tạm.
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/password', [AuthController::class, 'changePassword'])->middleware('throttle:sensitive');

    Route::middleware('password.fresh')->group(function () {
        Route::get('/auth/sessions', [SessionController::class, 'index']);
        Route::delete('/auth/sessions', [SessionController::class, 'destroyOthers']);
        Route::delete('/auth/sessions/{handle}', [SessionController::class, 'destroy']);

        // Hộp thông báo của chính người đăng nhập (Platform Admin hoặc người dùng Tenant).
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{notice}/read', [NotificationController::class, 'read']);

        // Platform Admin: chạy ở chế độ nền tảng (thấy mọi Tenant).
        Route::middleware('platform.admin')->prefix('platform')->group(function () {
            Route::get('/tenants', [TenantController::class, 'index']);
            Route::post('/tenants', [TenantController::class, 'store'])->middleware('throttle:sensitive');
            Route::get('/tenants/{tenant}', [TenantController::class, 'show']);
            Route::patch('/tenants/{tenant}', [TenantController::class, 'update']);
            Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend']);
            Route::post('/tenants/{tenant}/activate', [TenantController::class, 'activate']);

            Route::get('/support-session', [SupportSessionController::class, 'show']);
            Route::post('/tenants/{tenant}/support-session', [SupportSessionController::class, 'store']);
            Route::delete('/support-session', [SupportSessionController::class, 'destroy']);

            Route::get('/users', [PlatformUserController::class, 'index']);
            Route::patch('/users/{user}', [PlatformUserController::class, 'update']);
            Route::post('/users/{user}/reset-password', [PlatformUserController::class, 'resetPassword'])->middleware('throttle:sensitive');

            Route::get('/audit-logs', [AuditLogController::class, 'platformIndex']);

            Route::get('/health', [PlatformHealthController::class, 'index']);

            Route::get('/backups', [PlatformBackupController::class, 'index']);
            Route::get('/backups/status', [PlatformBackupController::class, 'status']);
            Route::post('/backups', [PlatformBackupController::class, 'store'])->middleware('throttle:sensitive');
            Route::get('/backups/{backup}', [PlatformBackupController::class, 'show']);
            Route::post('/backups/{backup}/download-link', [PlatformBackupController::class, 'downloadLink'])->middleware('throttle:sensitive');
            Route::get('/backups/{backup}/download', [PlatformBackupController::class, 'download'])
                ->middleware(['signed:relative', 'throttle:sensitive'])->name('platform.backups.download');

            Route::get('/tenants/{tenant}/backups', [TenantBackupController::class, 'index']);
            Route::post('/tenants/{tenant}/backups', [TenantBackupController::class, 'store'])->middleware('throttle:sensitive');
            Route::post('/tenants/{tenant}/backups/import', [TenantBackupController::class, 'import'])->middleware('throttle:sensitive');
            Route::get('/tenants/{tenant}/backups/restores', [TenantBackupController::class, 'restores']);
            Route::get('/tenants/{tenant}/backups/{backup}', [TenantBackupController::class, 'show']);
            Route::get('/tenants/{tenant}/backups/{backup}/download', [TenantBackupController::class, 'download'])->middleware('throttle:sensitive');
            Route::post('/tenants/{tenant}/backups/{backup}/restore', [TenantBackupController::class, 'restore'])->middleware('throttle:sensitive');

            Route::get('/plans', [PlanController::class, 'index']);
            Route::post('/plans', [PlanController::class, 'store']);
            Route::patch('/plans/{plan}', [PlanController::class, 'update']);

            Route::get('/subscriptions/renewals', [PlatformSubscriptionController::class, 'renewals']);
            Route::get('/tenants/{tenant}/subscription', [PlatformSubscriptionController::class, 'show']);
            Route::patch('/tenants/{tenant}/subscription', [PlatformSubscriptionController::class, 'update']);
            Route::post('/tenants/{tenant}/subscription/renew', [PlatformSubscriptionController::class, 'renew']);
            Route::post('/tenants/{tenant}/subscription/reopen', [PlatformSubscriptionController::class, 'reopen']);
            Route::post('/tenants/{tenant}/subscription/contact', [PlatformSubscriptionController::class, 'contact']);
        });

        // Dữ liệu của Tenant. Platform Admin chỉ vào được trong phiên hỗ trợ (chỉ đọc).
        // Quyền chi tiết do Policy kiểm tra trong từng controller.
        Route::middleware('tenant.user')->group(function () {
            Route::get('/subscription', [SubscriptionController::class, 'show']);

            Route::apiResource('branches', BranchController::class);

            Route::get('/audit-logs', [AuditLogController::class, 'index']);

            Route::get('/backups', [BackupController::class, 'index']);
            Route::post('/backups', [BackupController::class, 'store'])->middleware('throttle:sensitive');
            Route::post('/backups/import', [BackupController::class, 'import'])->middleware('throttle:sensitive');
            Route::get('/backups/restores', [BackupController::class, 'restores']);
            Route::get('/backups/{backup}', [BackupController::class, 'show']);
            Route::get('/backups/{backup}/download', [BackupController::class, 'download'])->middleware('throttle:sensitive');
            Route::post('/backups/{backup}/restore', [BackupController::class, 'restore'])->middleware('throttle:sensitive');

            Route::get('/permissions', [PermissionController::class, 'index']);
            Route::apiResource('roles', RoleController::class);

            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store'])->middleware('throttle:sensitive');
            Route::get('/users/{user}', [UserController::class, 'show']);
            Route::patch('/users/{user}', [UserController::class, 'update']);
            Route::delete('/users/{user}', [UserController::class, 'destroy']);
            Route::put('/users/{user}/roles', [UserController::class, 'syncRoles']);
            Route::put('/users/{user}/branches', [UserController::class, 'syncBranches']);
            Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('throttle:sensitive');
        });
    });
});
