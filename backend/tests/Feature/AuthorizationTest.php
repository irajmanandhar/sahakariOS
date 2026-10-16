<?php

namespace Tests\Feature;

use App\Authorization\Permission;
use App\Calendar\FiscalYearService;
use App\Models\Branch;
use App\Models\Cooperative;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/** Roles and permissions per cooperative membership. Synthetic data only. */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private Branch $branchA;

    private Branch $branchB;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-10-16 04:15:00'); // 2083 Asoj 30, Kathmandu morning

        $this->branchA = Branch::factory()->headOffice()->create();
        $this->branchB = Branch::factory()->headOffice()->for(Cooperative::factory())->create();
        $this->user = User::factory()->create(['email' => 'staff@example.test', 'password' => self::PASSWORD]);
        $this->branchA->cooperative->users()->attach($this->user, ['branch_id' => $this->branchA->id]);

        foreach ([$this->branchA, $this->branchB] as $branch) {
            $this->as($branch, fn () => app(FiscalYearService::class)->open(2083));
        }
    }

    private function as(Branch $branch, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($branch->cooperative_id, $branch->id, $callback);
    }

    /** @param  list<Permission>  $permissions */
    private function role(Branch $branch, array $permissions, string $key = 'manager'): Role
    {
        return $this->as($branch, function () use ($permissions, $key) {
            $role = Role::create(['key' => $key, 'name' => ucfirst($key)]);
            $role->syncPermissions($permissions);

            return $role;
        });
    }

    private function signInTo(Branch $branch): void
    {
        $this->postJson('/api/auth/login', ['email' => 'staff@example.test', 'password' => self::PASSWORD])->assertOk();
        $this->putJson('/api/auth/cooperative', ['cooperative_id' => $branch->cooperative_id])->assertOk();
    }

    public function test_no_role_means_no_permission(): void
    {
        $this->signInTo($this->branchA);

        $this->postJson('/api/business-day/open')->assertForbidden();
        $this->getJson('/api/audit-logs')->assertForbidden();
        $this->getJson('/api/auth/cooperative')->assertJsonPath('data.permissions', []);
    }

    public function test_permission_from_role_allows_the_action(): void
    {
        $this->role($this->branchA, [Permission::BusinessDayOpen, Permission::BusinessDayClose])->assignTo($this->user);
        $this->signInTo($this->branchA);

        $this->getJson('/api/auth/cooperative')->assertJsonPath('data.permissions', ['business_day.close', 'business_day.open']);
        $this->postJson('/api/business-day/open')->assertCreated()
            ->assertJsonPath('data.date', '2026-10-16')->assertJsonPath('data.date_bs', '2083-06-30');
        $this->postJson('/api/business-day/close')->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_each_permission_is_separate(): void
    {
        $this->role($this->branchA, [Permission::BusinessDayOpen])->assignTo($this->user);
        $this->signInTo($this->branchA);

        $this->postJson('/api/business-day/open')->assertCreated();
        $this->postJson('/api/business-day/close')->assertForbidden();
    }

    public function test_business_rule_refusals_are_422_with_a_message(): void
    {
        $this->role($this->branchA, [Permission::BusinessDayOpen])->assignTo($this->user);
        $this->signInTo($this->branchA);

        $this->postJson('/api/business-day/open', ['date' => '2026-10-17'])->assertUnprocessable()
            ->assertExactJson(['message' => 'A business day cannot be opened in the future.']);
        $this->postJson('/api/business-day/open', ['date' => 'tomorrow'])->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_roles_do_not_carry_over_to_another_cooperative(): void
    {
        $this->branchB->cooperative->users()->attach($this->user, ['branch_id' => $this->branchB->id]);
        $this->role($this->branchA, Permission::cases())->assignTo($this->user);
        $this->signInTo($this->branchB);

        $this->postJson('/api/business-day/open')->assertForbidden();
        $this->getJson('/api/auth/cooperative')->assertJsonPath('data.permissions', []);
    }

    public function test_a_role_cannot_be_given_to_someone_outside_its_cooperative(): void
    {
        $outsider = User::factory()->create();
        $this->branchB->cooperative->users()->attach($outsider, ['branch_id' => $this->branchB->id]);
        $role = $this->role($this->branchA, Permission::cases());

        try {
            $role->assignTo($outsider);
            $this->fail('Role assigned across cooperatives');
        } catch (QueryException $e) {
            $this->assertSame(1452, $e->errorInfo[1]);
        }
    }

    public function test_revoking_a_role_takes_effect_on_the_next_request(): void
    {
        $role = $this->role($this->branchA, [Permission::AuditLogView]);
        $role->assignTo($this->user);
        $this->signInTo($this->branchA);
        $this->getJson('/api/audit-logs')->assertOk();

        $role->removeFrom($this->user);

        $this->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_platform_admin_has_no_cooperative_permissions(): void
    {
        PlatformUser::factory()->create(['email' => 'ops@example.test', 'password' => self::PASSWORD]);
        $this->postJson('/api/platform/auth/login', ['email' => 'ops@example.test', 'password' => self::PASSWORD])->assertOk();

        $this->postJson('/api/business-day/open')->assertUnauthorized();
        $this->getJson('/api/audit-logs')->assertUnauthorized();
    }

    public function test_without_tenant_context_nothing_is_allowed(): void
    {
        $this->role($this->branchA, Permission::cases())->assignTo($this->user);

        $this->assertFalse($this->user->can(Permission::BusinessDayOpen->value));
        $this->assertTrue($this->as($this->branchA, fn () => $this->user->can(Permission::BusinessDayOpen->value)));
    }
}
