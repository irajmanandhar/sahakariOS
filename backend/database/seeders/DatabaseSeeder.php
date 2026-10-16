<?php

namespace Database\Seeders;

use App\Calendar\BusinessDayService;
use App\Calendar\FiscalYearService;
use App\Models\BusinessDay;
use App\Models\Cooperative;
use App\Models\FiscalYear;
use App\Models\PlatformUser;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Synthetic local-development data only: one demo cooperative with a head
 * office, fiscal year 2083/84 and today's business day, one staff member and
 * one platform administrator. Idempotent.
 * Refuses to run outside local/testing so the shared password never reaches
 * a real environment.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

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

        // Fiscal year 2083/84 and today's business day (Nepal time), if not already there.
        app(TenantContext::class)->runAs($coop->id, $branch->id, function () {
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
