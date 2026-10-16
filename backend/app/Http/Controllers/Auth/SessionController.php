<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Cookie-session login/logout for one guard. Subclasses fix the guard, so a
 * route can never choose which identity space it authenticates against.
 */
abstract class SessionController
{
    /** Guard this controller authenticates. */
    abstract protected function guard(): string;

    /** The other identity space, signed out on login so one browser session holds one identity. */
    abstract protected function otherGuard(): string;

    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        // attemptWhen is timeboxed by Laravel, so unknown emails, wrong passwords
        // and ineligible accounts take similar time and get the same message.
        $signedIn = Auth::guard($this->guard())->attemptWhen(
            $credentials,
            fn (Authenticatable $account) => $account->canSignIn(),
        );

        if (! $signedIn) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        Auth::guard($this->otherGuard())->logoutCurrentDevice();
        // Login already rotated the session ID (fixation protection); rotate the CSRF token too.
        $request->session()->regenerateToken();

        return response()->json(['data' => $this->present(Auth::guard($this->guard())->user())]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user())]);
    }

    public function destroy(Request $request): Response
    {
        Auth::guard($this->guard())->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /** Explicit allow-list: never serialize the whole model. */
    private function present(Authenticatable $account): array
    {
        return ['id' => $account->id, 'name' => $account->name, 'email' => $account->email];
    }
}
