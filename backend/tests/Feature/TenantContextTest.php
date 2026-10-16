<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cooperative;
use App\Models\PlatformUser;
use App\Models\User;
use App\Tenancy\MissingTenantContext;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/** Tenant selection and isolation for cooperative staff. Synthetic data only. */
class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private Cooperative $coopA;

    private Cooperative $coopB;

    private Branch $branchA;

    private Branch $branchB;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->coopA, $this->coopB] = Cooperative::factory()->count(2)->sequence(['name' => 'Alpha'], ['name' => 'Beta'])->create();
        $this->branchA = Branch::factory()->headOffice()->for($this->coopA)->create();
        Branch::factory()->for($this->coopA)->create(['code' => 'A-2']);
        $this->branchB = Branch::factory()->headOffice()->for($this->coopB)->create();

        $this->user = User::factory()->create(['email' => 'staff@example.test', 'password' => self::PASSWORD]);
        $this->coopA->users()->attach($this->user, ['branch_id' => $this->branchA->id]);
    }

    private function login(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'staff@example.test', 'password' => self::PASSWORD])->assertOk();
    }

    private function select(int $cooperativeId)
    {
        return $this->putJson('/api/auth/cooperative', ['cooperative_id' => $cooperativeId]);
    }

    private function nextRequestReloadsAccount(): void
    {
        $this->app['auth']->forgetGuards();
    }

    // --- selection ---------------------------------------------------------------

    public function test_lists_only_eligible_memberships(): void
    {
        $this->coopB->users()->attach($this->user, ['branch_id' => $this->branchB->id, 'status' => 'disabled']);
        $this->login();

        $this->getJson('/api/auth/cooperatives')->assertOk()->assertExactJson(['data' => [[
            'cooperative' => ['id' => $this->coopA->id, 'code' => $this->coopA->code, 'name' => 'Alpha', 'status' => 'active'],
            'branch' => ['id' => $this->branchA->id, 'code' => 'HO', 'name' => 'Head Office'],
        ]]]);
    }

    public function test_tenant_routes_require_a_selection(): void
    {
        $this->login();

        $this->getJson('/api/auth/cooperative')->assertOk()->assertExactJson(['data' => null]);
        $this->getJson('/api/branches')->assertStatus(409);
    }

    public function test_can_select_own_cooperative(): void
    {
        $this->login();

        $this->select($this->coopA->id)->assertOk()->assertJsonPath('data.cooperative.id', $this->coopA->id)
            ->assertJsonPath('data.branch.id', $this->branchA->id);
        $this->getJson('/api/auth/cooperative')->assertJsonPath('data.cooperative.id', $this->coopA->id);
    }

    public function test_selecting_rotates_the_session_id(): void
    {
        $this->login();
        $before = session()->getId();

        $this->select($this->coopA->id)->assertOk();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_cannot_select_another_cooperative_or_a_missing_one_and_answers_are_identical(): void
    {
        $this->login();

        $other = $this->select($this->coopB->id)->assertForbidden();
        $missing = $this->select(999999)->assertForbidden();

        $this->assertSame($other->json(), $missing->json());
        $this->getJson('/api/auth/cooperative')->assertExactJson(['data' => null]);
    }

    public function test_cannot_select_cooperative_with_disabled_membership(): void
    {
        $this->coopB->users()->attach($this->user, ['branch_id' => $this->branchB->id, 'status' => 'disabled']);
        $this->login();

        $this->select($this->coopB->id)->assertForbidden();
    }

    public function test_selection_requires_staff_authentication(): void
    {
        $this->select($this->coopA->id)->assertUnauthorized();

        PlatformUser::factory()->create(['email' => 'ops@example.test', 'password' => self::PASSWORD]);
        $this->postJson('/api/platform/auth/login', ['email' => 'ops@example.test', 'password' => self::PASSWORD])->assertOk();

        $this->select($this->coopA->id)->assertUnauthorized();
        $this->getJson('/api/branches')->assertUnauthorized();
    }

    // --- isolation ----------------------------------------------------------------

    public function test_branch_list_shows_only_current_cooperative(): void
    {
        $this->login();
        $this->select($this->coopA->id);

        $codes = collect($this->getJson('/api/branches')->assertOk()->json('data'))->pluck('code')->all();

        $this->assertSame(['A-2', 'HO'], $codes);
    }

    public function test_other_cooperatives_branch_is_not_found(): void
    {
        $this->login();
        $this->select($this->coopA->id);

        $this->getJson("/api/branches/{$this->branchA->id}")->assertOk()->assertJsonPath('data.code', 'HO');
        $this->getJson("/api/branches/{$this->branchB->id}")->assertNotFound();
    }

    public function test_client_supplied_tenant_ids_are_ignored(): void
    {
        $this->login();
        $this->select($this->coopA->id);

        $response = $this->withHeader('X-Cooperative-Id', (string) $this->coopB->id)
            ->getJson("/api/branches?cooperative_id={$this->coopB->id}&branch_id={$this->branchB->id}")
            ->assertOk();

        $this->assertNotContains($this->branchB->id, collect($response->json('data'))->pluck('id'));
    }

    public function test_switching_between_own_cooperatives_switches_data(): void
    {
        $this->coopB->users()->attach($this->user, ['branch_id' => $this->branchB->id]);
        $this->login();

        $this->select($this->coopA->id);
        $this->getJson("/api/branches/{$this->branchB->id}")->assertNotFound();

        $this->select($this->coopB->id)->assertOk();
        $this->getJson("/api/branches/{$this->branchB->id}")->assertOk();
        $this->getJson("/api/branches/{$this->branchA->id}")->assertNotFound();
    }

    public function test_losing_membership_after_selection_revokes_tenant_access(): void
    {
        $this->coopB->users()->attach($this->user, ['branch_id' => $this->branchB->id]); // stays able to sign in
        $this->login();
        $this->select($this->coopA->id);

        $this->user->cooperatives()->updateExistingPivot($this->coopA->id, ['status' => 'disabled']);
        $this->nextRequestReloadsAccount();

        $this->getJson('/api/branches')->assertForbidden();
        $this->getJson('/api/auth/cooperative')->assertExactJson(['data' => null]); // selection cleared
    }

    public function test_closing_the_selected_cooperative_revokes_tenant_access(): void
    {
        $this->coopB->users()->attach($this->user, ['branch_id' => $this->branchB->id]);
        $this->login();
        $this->select($this->coopA->id);

        $this->coopA->update(['status' => 'closed']);

        $this->getJson('/api/branches')->assertForbidden();
    }

    public function test_ineligible_account_is_signed_out_before_tenant_check(): void
    {
        $this->login();
        $this->select($this->coopA->id);

        $this->user->cooperatives()->updateExistingPivot($this->coopA->id, ['status' => 'disabled']);
        $this->nextRequestReloadsAccount();

        // No eligible membership left at all: 401 (signed out), not 403.
        $this->getJson('/api/branches')->assertUnauthorized();
    }

    public function test_new_login_does_not_inherit_previous_selection(): void
    {
        $this->login();
        $this->select($this->coopA->id);

        $this->login();

        $this->getJson('/api/auth/cooperative')->assertExactJson(['data' => null]);
    }

    public function test_tenant_context_is_cleared_after_the_request(): void
    {
        $this->login();
        $this->select($this->coopA->id);
        $this->getJson('/api/branches')->assertOk();

        $this->assertFalse(app(TenantContext::class)->has());
    }

    // --- scope rules (no HTTP) -------------------------------------------------------

    public function test_query_without_tenant_context_fails_closed(): void
    {
        $this->expectException(MissingTenantContext::class);

        Branch::query()->get();
    }

    public function test_explicit_bypass_sees_all_cooperatives(): void
    {
        $count = app(TenantContext::class)->withoutTenantScope(fn () => Branch::query()->count());

        $this->assertSame(3, $count);
        $this->assertFalse(app(TenantContext::class)->isBypassed());
    }

    public function test_create_inside_context_uses_current_cooperative_and_refuses_others(): void
    {
        $tenant = app(TenantContext::class);
        $tenant->set($this->coopA->id, $this->branchA->id);

        $branch = Branch::create(['code' => 'A-3', 'name' => 'Third']);
        $this->assertSame($this->coopA->id, $branch->cooperative_id);

        $this->expectException(LogicException::class);
        $this->coopB->branches()->create(['code' => 'B-9', 'name' => 'Sneaky']);
    }

    public function test_run_as_is_reentrant_only_for_the_same_tenant(): void
    {
        $tenant = app(TenantContext::class);

        $inner = $tenant->runAs($this->coopA->id, $this->branchA->id, fn () => $tenant->runAs($this->coopA->id, $this->branchA->id, fn () => $tenant->cooperativeId()));
        $this->assertSame($this->coopA->id, $inner);
        $this->assertFalse($tenant->has());

        $this->expectException(LogicException::class);
        $tenant->runAs($this->coopA->id, $this->branchA->id, fn () => $tenant->runAs($this->coopB->id, $this->branchB->id, fn () => null));
    }

    public function test_context_cannot_be_overwritten_within_a_request(): void
    {
        $tenant = app(TenantContext::class);
        $tenant->set($this->coopA->id, $this->branchA->id);

        $this->expectException(LogicException::class);
        $tenant->set($this->coopB->id, $this->branchB->id);
    }
}
