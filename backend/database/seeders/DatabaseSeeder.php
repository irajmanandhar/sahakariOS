<?php

namespace Database\Seeders;

use App\Accounting\LedgerAccountService;
use App\Authorization\Permission;
use App\Calendar\BusinessDayService;
use App\Calendar\FiscalYearService;
use App\Models\BusinessDay;
use App\Models\Cooperative;
use App\Models\FiscalYear;
use App\Models\LedgerAccount;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Synthetic local-development data only: one demo cooperative with a head
 * office, fiscal year 2083/84 and today's business day, one staff member with
 * a demo Manager role, and one platform administrator. Idempotent.
 * Refuses to run outside local/testing so the shared password never reaches
 * a real environment.
 *
 * Model events stay ON (no WithoutModelEvents): the tenant guard in
 * BelongsToTenant runs on the `creating` event.
 */
class DatabaseSeeder extends Seeder
{
    public const LOCAL_PASSWORD = 'local-dev-password';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DatabaseSeeder only seeds synthetic data in local/testing environments.');
        }

        $coop = Cooperative::firstOrCreate(['code' => 'DEMO'], ['name' => 'Demo Savings and Credit Cooperative (synthetic)']);
        $branch = app(TenantContext::class)->withoutTenantScope(
            fn () => $coop->branches()->firstOrCreate(['code' => 'HO'], ['name' => 'Head Office', 'is_head_office' => true]),
        );

        $staff = User::firstOrCreate(
            ['email' => 'staff@demo.test'],
            ['name' => 'Demo Staff', 'password' => self::LOCAL_PASSWORD],
        );
        $coop->users()->syncWithoutDetaching([$staff->id => ['branch_id' => $branch->id]]);

        // Fiscal year 2083/84, today's business day (Nepal time) and a demo "Manager"
        // role with every current permission, if not already there.
        app(TenantContext::class)->runAs($coop->id, $branch->id, function () use ($staff) {
            $manager = Role::firstOrCreate(['key' => 'manager'], ['name' => 'Manager']);
            $manager->syncPermissions(Permission::cases());
            $manager->assignTo($staff);

            // Tiny DEMO chart so the ledger can be tried locally. Not a recommended
            // chart of accounts: the real chart is the cooperative's accountant's decision.
            if (! LedgerAccount::query()->exists()) {
                $accounts = app(LedgerAccountService::class);
                $accounts->create('DEMO-1', 'Assets (demo)', 'asset', false);
                $accounts->create('DEMO-1.1', 'Cash in hand (demo)', 'asset', true, 'DEMO-1');
                $accounts->create('DEMO-1.2', 'Bank account (demo)', 'asset', true, 'DEMO-1');
                $accounts->create('DEMO-2', 'Liabilities (demo)', 'liability', false);
                $accounts->create('DEMO-2.1', 'Member savings (demo)', 'liability', true, 'DEMO-2');
            }

            if (! FiscalYear::query()->where('start_bs_year', 2083)->exists()) {
                app(FiscalYearService::class)->open(2083);
            }
            $days = app(BusinessDayService::class);
            $today = BusinessDayService::today();
            if ($days->current() === null && ! BusinessDay::query()->where('business_date', '>=', $today)->exists()) {
                $days->open($today);
            }
        });

        PlatformUser::firstOrCreate(
            ['email' => 'ops@demo.test'],
            ['name' => 'Demo Platform Admin', 'password' => self::LOCAL_PASSWORD],
        );
    }
}
