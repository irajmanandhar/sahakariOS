<?php

use App\Accounting\PostingRejected;
use App\Accounting\VerifyLedger;
use App\Audit\VerifyAuditLog;
use App\Calendar\CalendarRuleViolation;
use App\Calendar\OutOfCalendarRange;
use App\Http\Middleware\EnsureAccountCanSignIn;
use App\Http\Middleware\EnsureTenantContext;
use App\Members\MembershipRuleViolation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'account.can-sign-in' => EnsureAccountCanSignIn::class,
            'tenant' => EnsureTenantContext::class,
        ]);

        // Run eligibility and tenant checks before route-model binding, so
        // {model} parameters are always resolved inside the tenant scope.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureAccountCanSignIn::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureTenantContext::class);

        // This is an API: there is no Laravel login page to redirect to. Guests
        // (including sessions ended by auth.session) get a 401; the frontend
        // decides where to send the user. The default would call route('login').
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Business-rule refusals carry staff-safe messages.
        $exceptions->render(fn (CalendarRuleViolation|OutOfCalendarRange|PostingRejected|MembershipRuleViolation $e) => response()->json(['message' => $e->getMessage()], 422));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->withCommands([VerifyAuditLog::class, VerifyLedger::class])
    ->create();
