<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant context per request / queued job.
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Login: 5/min per account+IP (guessing one password), 20/min per IP
        // (spraying many accounts). Keyed by path so staff and platform
        // logins are counted separately. Keys are hashed by the limiter.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($request->path().'|'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->path().'|'.$request->ip()),
        ]);

        // The password broker also throttles repeat links per email (config/auth.php).
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Reset links point to the frontend, the only origin users talk to.
        ResetPassword::createUrlUsing(fn ($user, string $token) => config('app.frontend_url').'/reset-password?'
            .http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]));

        // PROPOSED minimum; confirm the password policy with the customer.
        Password::defaults(fn () => Password::min(12));
    }
}
