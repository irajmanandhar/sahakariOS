<?php

use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\PlatformSessionController;
use App\Http\Controllers\Auth\StaffSessionController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CurrentCooperativeController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Stateless: no session, no cookies.
Route::get('/health', HealthController::class);

// Browser-facing routes reached through the Next.js BFF (same origin).
// The `web` group adds encrypted cookies, the session and CSRF protection.
Route::middleware('web')->group(function () {
    // Sets the XSRF-TOKEN cookie for clients that cannot rely on Sec-Fetch-Site.
    Route::get('/csrf-cookie', fn () => response()->noContent());

    Route::prefix('auth')->group(function () {
        Route::post('/login', [StaffSessionController::class, 'store'])->middleware('throttle:login');
        Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:password-reset');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

        Route::middleware(['auth:web', 'auth.session', 'account.can-sign-in'])->group(function () {
            Route::get('/me', [StaffSessionController::class, 'show']);
            Route::post('/logout', [StaffSessionController::class, 'destroy']);

            // Tenant selection (server-side session state).
            Route::get('/cooperatives', [CurrentCooperativeController::class, 'index']);
            Route::get('/cooperative', [CurrentCooperativeController::class, 'show']);
            Route::put('/cooperative', [CurrentCooperativeController::class, 'update']);
        });
    });

    // Cooperative-scoped routes: everything here runs inside a verified tenant context.
    Route::middleware(['auth:web', 'auth.session', 'account.can-sign-in', 'tenant'])->group(function () {
        Route::get('/branches', [BranchController::class, 'index']);
        Route::get('/branches/{branch}', [BranchController::class, 'show']);
    });

    Route::prefix('platform/auth')->group(function () {
        Route::post('/login', [PlatformSessionController::class, 'store'])->middleware('throttle:login');

        Route::middleware(['auth:platform', 'auth.session', 'account.can-sign-in'])->group(function () {
            Route::get('/me', [PlatformSessionController::class, 'show']);
            Route::post('/logout', [PlatformSessionController::class, 'destroy']);
        });
    });
});
