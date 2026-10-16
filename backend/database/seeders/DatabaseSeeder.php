<?php

namespace Database\Seeders;

use App\Models\Cooperative;
use App\Models\PlatformUser;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Synthetic local-development data only: one demo cooperative with a head
 * office, one staff member and one platform administrator. Idempotent.
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

        PlatformUser::firstOrCreate(
            ['email' => 'ops@demo.test'],
            ['name' => 'Demo Platform Admin', 'password' => self::LOCAL_PASSWORD],
        );
    }
}
