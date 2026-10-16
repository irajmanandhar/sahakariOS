<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\PlatformSessionController;
use App\Http\Controllers\Auth\StaffSessionController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BusinessDayController;
use App\Http\Controllers\CurrentCooperativeController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\LedgerAccountController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\TrialBalanceController;
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
        Route::get('/business-day', [BusinessDayController::class, 'show']);
        Route::post('/business-day/open', [BusinessDayController::class, 'open'])->middleware('can:business_day.open');
        Route::post('/business-day/close', [BusinessDayController::class, 'close'])->middleware('can:business_day.close');
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit_log.view');

        // General ledger
        Route::get('/ledger-accounts', [LedgerAccountController::class, 'index'])->middleware('can:ledger.view');
        Route::post('/ledger-accounts', [LedgerAccountController::class, 'store'])->middleware('can:ledger_account.manage');
        Route::get('/journal-entries', [JournalEntryController::class, 'index'])->middleware('can:ledger.view');
        Route::post('/journal-entries', [JournalEntryController::class, 'store'])->middleware('can:journal.post');
        Route::get('/journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])->middleware('can:ledger.view');
        Route::post('/journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->middleware('can:journal.reverse');
        Route::get('/reports/trial-balance', [TrialBalanceController::class, 'show'])->middleware('can:ledger.view');

        // Members
        Route::get('/members', [MemberController::class, 'index'])->middleware('can:member.view');
        Route::post('/members', [MemberController::class, 'store'])->middleware('can:member.apply');
        Route::get('/members/{member}', [MemberController::class, 'show'])->middleware('can:member.view');
        Route::get('/members/{member}/kyc', [MemberController::class, 'kyc'])->middleware('can:member.kyc_view');
        Route::put('/members/{member}/kyc', [MemberController::class, 'updateKyc'])->middleware('can:member.kyc_update');
        Route::post('/members/{member}/risk', [MemberController::class, 'assessRisk'])->middleware('can:member.risk_assess');
        Route::post('/members/{member}/documents', [MemberController::class, 'uploadDocument'])->middleware('can:member.apply');
        Route::get('/members/{member}/documents/{document}', [MemberController::class, 'downloadDocument'])->middleware('can:member.kyc_view');
        Route::post('/members/{member}/approve', [MemberController::class, 'approve'])->middleware('can:member.decide');
        Route::post('/members/{member}/reject', [MemberController::class, 'reject'])->middleware('can:member.decide');
    });

    Route::prefix('platform/auth')->group(function () {
        Route::post('/login', [PlatformSessionController::class, 'store'])->middleware('throttle:login');

        Route::middleware(['auth:platform', 'auth.session', 'account.can-sign-in'])->group(function () {
            Route::get('/me', [PlatformSessionController::class, 'show']);
            Route::post('/logout', [PlatformSessionController::class, 'destroy']);
        });
    });
});
