<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks account eligibility on every authenticated request, so disabling
 * an account or membership ends existing sessions instead of waiting for
 * them to expire. Must run after `auth:<guard>`, which selects the guard.
 */
class EnsureAccountCanSignIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if ($account !== null && ! $account->canSignIn()) {
            Auth::guard()->logoutCurrentDevice();
            $request->session()->invalidate();

            throw new AuthenticationException('Unauthenticated.', [Auth::getDefaultDriver()]);
        }

        return $next($request);
    }
}
