<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cooperative;
use App\Models\PlatformUser;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Session authentication for cooperative staff and platform admins. Synthetic data only. */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private const FAILED = 'These credentials do not match our records.';

    private function staff(array $membership = [], array $cooperative = [], array $branch = []): User
    {
        $branch = Branch::factory()->headOffice()->for(Cooperative::factory()->state($cooperative))->create($branch);
        $user = User::factory()->create(['email' => 'staff@example.test', 'password' => self::PASSWORD]);
        $branch->cooperative->users()->attach($user, ['branch_id' => $branch->id] + $membership);

        return $user;
    }

    /**
     * In tests the app (and its guards) lives across requests, so the guard
     * keeps the already-loaded account. A real HTTP request always reloads it;
     * this simulates that next request after the database changed.
     */
    private function nextRequestReloadsAccount(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function admin(array $attributes = []): PlatformUser
    {
        return PlatformUser::factory()->create(['email' => 'ops@example.test', 'password' => self::PASSWORD] + $attributes);
    }

    private function loginStaff(string $password = self::PASSWORD, string $email = 'staff@example.test')
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function loginAdmin(string $password = self::PASSWORD, string $email = 'ops@example.test')
    {
        return $this->postJson('/api/platform/auth/login', ['email' => $email, 'password' => $password]);
    }

    // --- staff login / logout -------------------------------------------------

    public function test_staff_can_log_in_and_see_only_safe_profile_fields(): void
    {
        $user = $this->staff();

        $this->loginStaff()
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => 'staff@example.test']]);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('platform');
    }

    public function test_email_lookup_is_case_insensitive(): void
    {
        $this->staff();

        $this->loginStaff(email: 'STAFF@Example.test')->assertOk();
    }

    public function test_wrong_password_and_unknown_email_get_the_same_response(): void
    {
        $this->staff();

        $wrong = $this->loginStaff('wrong-password')->assertUnprocessable();
        $unknown = $this->loginStaff(email: 'nobody@example.test')->assertUnprocessable();

        $this->assertSame(['email' => [self::FAILED]], $wrong->json('errors'));
        $this->assertSame($wrong->json(), $unknown->json());
        $this->assertGuest('web');
    }

    public function test_login_validates_input(): void
    {
        $this->postJson('/api/auth/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_logout_ends_the_session(): void
    {
        $this->staff();
        $this->loginStaff()->assertOk();

        $this->postJson('/api/auth/logout')->assertNoContent();

        $this->assertGuest('web');
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_login_rotates_the_session_id(): void
    {
        $this->staff();
        $this->get('/api/csrf-cookie');
        $before = session()->getId();

        $this->loginStaff()->assertOk();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_session_cookie_is_http_only_and_same_site_lax(): void
    {
        $this->staff();

        $cookie = collect($this->loginStaff()->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    // --- eligibility ------------------------------------------------------------

    public function test_user_without_any_membership_cannot_log_in(): void
    {
        User::factory()->create(['email' => 'staff@example.test', 'password' => self::PASSWORD]);

        $this->loginStaff()->assertUnprocessable()->assertJsonPath('errors.email.0', self::FAILED);
    }

    public function test_disabled_membership_cannot_log_in(): void
    {
        $this->staff(membership: ['status' => 'disabled']);

        $this->loginStaff()->assertUnprocessable()->assertJsonPath('errors.email.0', self::FAILED);
    }

    public function test_closed_cooperative_staff_cannot_log_in(): void
    {
        $this->staff(cooperative: ['status' => 'closed']);

        $this->loginStaff()->assertUnprocessable();
    }

    public function test_closed_branch_staff_cannot_log_in(): void
    {
        $this->staff(branch: ['status' => 'closed']);

        $this->loginStaff()->assertUnprocessable();
    }

    public function test_licence_suspended_cooperative_staff_can_still_log_in(): void
    {
        $this->staff(cooperative: ['status' => 'licence_suspended']);

        $this->loginStaff()->assertOk();
    }

    public function test_disabling_a_membership_ends_an_existing_session(): void
    {
        $user = $this->staff();
        $this->loginStaff()->assertOk();

        $user->cooperatives()->updateExistingPivot($user->cooperatives()->sole()->id, ['status' => 'disabled']);

        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->assertGuest('web');
    }

    // --- unauthenticated access ---------------------------------------------------

    public function test_protected_endpoints_reject_guests_with_401_json(): void
    {
        foreach (['/api/auth/me', '/api/platform/auth/me'] as $uri) {
            $this->getJson($uri)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        }
        foreach (['/api/auth/logout', '/api/platform/auth/logout'] as $uri) {
            $this->postJson($uri)->assertUnauthorized();
        }
        // Without an Accept: application/json header (plain browser fetch) it is still a 401, not a redirect.
        $this->get('/api/auth/me')->assertUnauthorized();
    }

    // --- throttling ---------------------------------------------------------------

    public function test_login_is_throttled_after_five_attempts_per_account(): void
    {
        $this->staff();

        for ($i = 0; $i < 5; $i++) {
            $this->loginStaff('wrong-password')->assertUnprocessable();
        }

        // Even the correct password is refused while throttled.
        $this->loginStaff()->assertTooManyRequests()->assertHeader('Retry-After');
        // A different account from the same IP is still allowed.
        $this->loginStaff(email: 'other@example.test')->assertUnprocessable();
    }

    public function test_spoofed_forwarded_for_header_does_not_bypass_throttling(): void
    {
        $this->staff();

        for ($i = 1; $i <= 5; $i++) {
            $this->withHeader('X-Forwarded-For', "198.51.100.{$i}")->loginStaff('wrong-password')->assertUnprocessable();
        }

        $this->withHeader('X-Forwarded-For', '198.51.100.99')->loginStaff()->assertTooManyRequests();
    }

    public function test_login_is_throttled_after_twenty_attempts_per_ip(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->loginStaff('x', "user{$i}@example.test")->assertUnprocessable();
        }

        $this->loginStaff('x', 'user20@example.test')->assertTooManyRequests();
    }

    // --- platform administrators --------------------------------------------------

    public function test_platform_admin_can_log_in_and_out(): void
    {
        $admin = $this->admin();

        $this->loginAdmin()->assertOk()->assertJsonPath('data.id', $admin->id);
        $this->getJson('/api/platform/auth/me')->assertOk()->assertJsonPath('data.email', 'ops@example.test');
        $this->assertAuthenticatedAs($admin, 'platform');

        $this->postJson('/api/platform/auth/logout')->assertNoContent();
        $this->getJson('/api/platform/auth/me')->assertUnauthorized();
    }

    public function test_disabled_platform_admin_cannot_log_in(): void
    {
        $this->admin(['status' => 'disabled']);

        $this->loginAdmin()->assertUnprocessable()->assertJsonPath('errors.email.0', self::FAILED);
    }

    public function test_disabling_a_platform_admin_ends_an_existing_session(): void
    {
        $admin = $this->admin();
        $this->loginAdmin()->assertOk();

        $admin->update(['status' => 'disabled']);
        $this->nextRequestReloadsAccount();

        $this->getJson('/api/platform/auth/me')->assertUnauthorized();
    }

    // --- guard separation -----------------------------------------------------------

    public function test_staff_session_cannot_reach_platform_routes(): void
    {
        $this->staff();
        $this->loginStaff()->assertOk();

        $this->getJson('/api/platform/auth/me')->assertUnauthorized();
    }

    public function test_platform_session_cannot_reach_staff_routes(): void
    {
        $this->admin();
        $this->loginAdmin()->assertOk();

        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_same_email_in_both_tables_does_not_cross_identity_spaces(): void
    {
        $this->staff(); // staff@example.test / PASSWORD
        PlatformUser::factory()->create(['email' => 'staff@example.test', 'password' => 'a-different-admin-password']);

        $this->loginAdmin(self::PASSWORD, 'staff@example.test')->assertUnprocessable();
        $this->loginStaff()->assertOk();
        $this->getJson('/api/platform/auth/me')->assertUnauthorized();
    }

    public function test_matching_ids_do_not_cross_identity_spaces(): void
    {
        $user = $this->staff();
        $admin = $this->admin(['id' => $user->id]);
        $this->assertSame($user->id, $admin->id);

        $this->loginAdmin()->assertOk();

        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logging_in_to_one_identity_space_signs_out_the_other(): void
    {
        $this->staff();
        $this->admin();

        $this->loginStaff()->assertOk();
        $this->loginAdmin()->assertOk();

        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/platform/auth/me')->assertOk();
    }

    // --- CSRF -------------------------------------------------------------------------

    /** Laravel skips CSRF checks in unit tests; turn them back on for these tests. */
    private function enforceCsrf(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    public function test_cross_site_post_without_csrf_token_is_rejected(): void
    {
        $this->enforceCsrf();
        $this->staff();

        $this->withHeader('Sec-Fetch-Site', 'cross-site')->postJson('/api/auth/login', [
            'email' => 'staff@example.test', 'password' => self::PASSWORD,
        ])->assertStatus(419);

        $this->assertGuest('web');
    }

    public function test_post_with_valid_xsrf_token_is_accepted(): void
    {
        $this->enforceCsrf();
        $this->staff();

        $xsrf = collect($this->get('/api/csrf-cookie')->assertNoContent()->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'XSRF-TOKEN');
        $this->assertNotNull($xsrf);
        $this->assertFalse($xsrf->isHttpOnly(), 'XSRF-TOKEN must be readable by the frontend');

        $this->withHeader('X-XSRF-TOKEN', urldecode($xsrf->getValue()))
            ->postJson('/api/auth/login', ['email' => 'staff@example.test', 'password' => self::PASSWORD])
            ->assertOk();
    }

    public function test_same_origin_browser_post_is_accepted_without_token(): void
    {
        $this->enforceCsrf();
        $this->staff();

        $this->withHeader('Sec-Fetch-Site', 'same-origin')
            ->postJson('/api/auth/login', ['email' => 'staff@example.test', 'password' => self::PASSWORD])
            ->assertOk();
    }

    // --- password reset ---------------------------------------------------------------

    public function test_forgot_password_response_does_not_reveal_whether_email_exists(): void
    {
        Notification::fake();
        $user = $this->staff();

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'staff@example.test'])->assertOk();
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.test'])->assertOk();

        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_reset_link_points_to_frontend_and_token_is_never_returned(): void
    {
        Notification::fake();
        $user = $this->staff();

        $response = $this->postJson('/api/auth/forgot-password', ['email' => 'staff@example.test']);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user, $response) {
            $url = $n->toMail($user)->actionUrl;
            $this->assertStringStartsWith(config('app.frontend_url').'/reset-password?token=', $url);
            $this->assertStringNotContainsString($n->token, $response->getContent());

            return true;
        });
        $this->assertNotSame(
            Notification::sent($user, ResetPassword::class)->first()->token,
            \DB::table('password_reset_tokens')->value('token'),
            'tokens must be stored hashed',
        );
    }

    public function test_password_can_be_reset_with_valid_token_and_old_session_is_ended(): void
    {
        Notification::fake();
        $user = $this->staff();
        $this->loginStaff()->assertOk();
        $this->postJson('/api/auth/forgot-password', ['email' => 'staff@example.test']);
        $token = Notification::sent($user, ResetPassword::class)->first()->token;

        $this->postJson('/api/auth/reset-password', [
            'token' => $token, 'email' => 'staff@example.test',
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();
        $this->nextRequestReloadsAccount();

        // The session opened with the old password is no longer valid.
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->loginStaff()->assertUnprocessable();
        $this->loginStaff('a-brand-new-password')->assertOk();
    }

    public function test_reset_with_invalid_token_fails_generically(): void
    {
        $this->staff();

        $this->postJson('/api/auth/reset-password', [
            'token' => 'not-a-real-token', 'email' => 'staff@example.test',
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ])->assertUnprocessable()->assertJsonPath('errors.email.0', 'This password reset link is invalid or has expired.');

        $this->loginStaff()->assertOk();
    }

    public function test_reset_requires_minimum_password_length(): void
    {
        $this->staff();

        $this->postJson('/api/auth/reset-password', [
            'token' => 'x', 'email' => 'staff@example.test', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
