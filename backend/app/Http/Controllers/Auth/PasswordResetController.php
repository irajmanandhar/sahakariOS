<?php

namespace App\Http\Controllers\Auth;

use App\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Password reset for cooperative staff, using Laravel's password broker
 * (hashed, expiring, per-email throttled tokens in password_reset_tokens).
 * Platform administrators are not covered; they are reset by operators.
 */
class PasswordResetController
{
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        // Same response whether or not the email exists, or is throttled,
        // so this endpoint cannot be used to discover accounts.
        Password::broker('users')->sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If that email address is registered, a password reset link has been sent.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'max:255', PasswordRule::defaults()],
        ]);

        $status = Password::broker('users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password, // hashed by the model cast
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
                app(AuditLogger::class)->record('auth.password_reset', $user, actor: $user, cooperativeId: false);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // One message for unknown email, bad token and expired token.
            throw ValidationException::withMessages(['email' => 'This password reset link is invalid or has expired.']);
        }

        return response()->json(['message' => 'Your password has been reset. Please sign in.']);
    }
}
