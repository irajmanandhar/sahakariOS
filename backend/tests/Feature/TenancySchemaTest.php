<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cooperative;
use App\Models\PlatformUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema-level guarantees for cooperatives, branches, staff membership and
 * platform users. These rules live in MySQL, so they hold even if application
 * code has a bug. All data is synthetic.
 */
class TenancySchemaTest extends TestCase
{
    use RefreshDatabase;

    // MySQL error codes, so each test fails for the intended reason only.
    private const DUPLICATE = 1062;      // unique constraint

    private const FK_CHILD = 1452;       // child row references missing parent

    private const FK_PARENT = 1451;      // parent row still referenced

    private const NO_DEFAULT = 1364;     // NOT NULL column without value

    private const INVALID_VALUE = 1265;  // value not allowed by ENUM (strict mode)

    private function assertMysqlError(int $code, callable $statement): void
    {
        try {
            $statement();
        } catch (QueryException $e) {
            $this->assertSame($code, $e->errorInfo[1] ?? null, $e->getMessage());

            return;
        }

        $this->fail("Expected MySQL error {$code}, but the statement succeeded.");
    }

    public function test_tests_run_against_the_dedicated_test_database(): void
    {
        $this->assertSame('sahakarios_test', DB::connection()->getDatabaseName());
    }

    public function test_one_cooperative_with_one_head_office_and_one_staff_member(): void
    {
        $coop = Cooperative::factory()->create();
        $branch = Branch::factory()->headOffice()->for($coop)->create();
        $user = User::factory()->create();
        $coop->users()->attach($user, ['branch_id' => $branch->id]);

        $this->assertTrue($coop->branches()->sole()->is_head_office);
        $membership = $user->cooperatives()->sole();
        $this->assertTrue($membership->is($coop));
        $this->assertSame($branch->id, $membership->pivot->branch_id);
        $this->assertSame('active', $membership->pivot->status);
        $this->assertSame('active', $coop->fresh()->status);
    }

    public function test_cooperative_code_is_unique(): void
    {
        Cooperative::factory()->create(['code' => 'DUP']);

        $this->assertMysqlError(self::DUPLICATE, fn () => Cooperative::factory()->create(['code' => 'DUP']));
    }

    public function test_cooperative_status_rejects_unknown_values(): void
    {
        $this->assertMysqlError(self::INVALID_VALUE, fn () => Cooperative::factory()->create(['status' => 'deleted']));
    }

    public function test_branch_code_is_unique_within_a_cooperative_only(): void
    {
        [$a, $b] = Cooperative::factory()->count(2)->create();
        Branch::factory()->for($a)->create(['code' => 'KTM']);
        Branch::factory()->for($b)->create(['code' => 'KTM']);

        $this->assertMysqlError(self::DUPLICATE, fn () => Branch::factory()->for($a)->create(['code' => 'KTM']));
    }

    public function test_only_one_head_office_per_cooperative(): void
    {
        $coop = Cooperative::factory()->create();
        Branch::factory()->headOffice()->for($coop)->create();
        Branch::factory()->count(2)->for($coop)->create(); // ordinary branches are unlimited
        Branch::factory()->headOffice()->for(Cooperative::factory())->create(); // other tenant

        $this->assertMysqlError(self::DUPLICATE, fn () => Branch::factory()->for($coop)->create(['code' => 'HO2', 'is_head_office' => true]));
    }

    public function test_user_can_join_a_cooperative_only_once(): void
    {
        $branch = Branch::factory()->headOffice()->create();
        $user = User::factory()->create();
        $branch->cooperative->users()->attach($user, ['branch_id' => $branch->id]);

        $this->assertMysqlError(self::DUPLICATE, fn () => $branch->cooperative->users()->attach($user, ['branch_id' => $branch->id]));
    }

    public function test_one_user_may_belong_to_two_cooperatives(): void
    {
        $user = User::factory()->create();
        foreach (Branch::factory()->count(2)->headOffice()->create() as $branch) {
            $branch->cooperative->users()->attach($user, ['branch_id' => $branch->id]);
        }

        $this->assertCount(2, $user->cooperatives);
    }

    public function test_membership_branch_must_belong_to_the_same_cooperative(): void
    {
        $coopA = Cooperative::factory()->create();
        Branch::factory()->headOffice()->for($coopA)->create();
        $foreignBranch = Branch::factory()->headOffice()->create(); // belongs to another cooperative

        $this->assertMysqlError(self::FK_CHILD, fn () => $coopA->users()->attach(User::factory()->create(), ['branch_id' => $foreignBranch->id]));
    }

    public function test_membership_requires_a_branch(): void
    {
        $coop = Cooperative::factory()->create();

        $this->assertMysqlError(self::NO_DEFAULT, fn () => $coop->users()->attach(User::factory()->create()));
    }

    public function test_cooperative_with_branches_cannot_be_deleted(): void
    {
        $branch = Branch::factory()->create();

        $this->assertMysqlError(self::FK_PARENT, fn () => $branch->cooperative->delete());
    }

    public function test_branch_with_staff_cannot_be_deleted(): void
    {
        $branch = Branch::factory()->headOffice()->create();
        $branch->cooperative->users()->attach(User::factory()->create(), ['branch_id' => $branch->id]);

        $this->assertMysqlError(self::FK_PARENT, fn () => $branch->delete());
    }

    public function test_user_with_membership_cannot_be_deleted(): void
    {
        $branch = Branch::factory()->headOffice()->create();
        $user = User::factory()->create();
        $branch->cooperative->users()->attach($user, ['branch_id' => $branch->id]);

        $this->assertMysqlError(self::FK_PARENT, fn () => $user->delete());
    }

    public function test_user_email_is_unique_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'staff@example.test']);

        $this->assertMysqlError(self::DUPLICATE, fn () => User::factory()->create(['email' => 'STAFF@example.test']));
    }

    public function test_platform_users_are_a_separate_identity_space(): void
    {
        $platform = PlatformUser::factory()->create(['email' => 'same@example.test']);
        User::factory()->create(['email' => 'same@example.test']);

        $this->assertSame(1, User::count());
        $this->assertNotSame($platform->getTable(), (new User)->getTable());
        $this->assertFalse(Schema::hasColumn('platform_users', 'cooperative_id'));
        $this->assertArrayNotHasKey('password', $platform->toArray());
    }

    public function test_platform_user_email_is_unique(): void
    {
        PlatformUser::factory()->create(['email' => 'ops@example.test']);

        $this->assertMysqlError(self::DUPLICATE, fn () => PlatformUser::factory()->create(['email' => 'Ops@example.test']));
    }
}
