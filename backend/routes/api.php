<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\SessionController;
use Illuminate\Support\Facades\Route;

// Prefix /api/v1 được cấu hình trong bootstrap/app.php
Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'tenant.context'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/auth/sessions', [SessionController::class, 'index']);
    Route::delete('/auth/sessions', [SessionController::class, 'destroyOthers']);
    Route::delete('/auth/sessions/{handle}', [SessionController::class, 'destroy']);

    // Dữ liệu của Tenant: Platform Admin không vào được nếu chưa có cơ chế impersonation.
    Route::middleware('tenant.user')->group(function () {
        Route::get('/branches', [BranchController::class, 'index']);
        Route::get('/branches/{branch}', [BranchController::class, 'show']);
    });
});
