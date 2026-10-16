<?php

namespace Database\Seeders;

use App\Accounting\LedgerAccountService;
use App\Accounting\LedgerMappings;
use App\Authorization\Permission;
use App\Calendar\BusinessDayService;
use App\Calendar\FiscalYearService;
use App\Loans\LoanService;
use App\Models\BusinessDay;
use App\Models\Cooperative;
use App\Models\FiscalYear;
use App\Models\LedgerAccount;
use App\Models\LoanProduct;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Savings\SavingsService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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
        // Second person, so "who entered it may not approve it" can be tried locally.
        $approver = User::firstOrCreate(
            ['email' => 'approver@demo.test'],
            ['name' => 'Demo Approver', 'password' => self::LOCAL_PASSWORD],
        );
        $coop->users()->syncWithoutDetaching([
            $staff->id => ['branch_id' => $branch->id],
            $approver->id => ['branch_id' => $branch->id],
        ]);

        // Fiscal year 2083/84, today's business day (Nepal time) and a demo "Manager"
        // role with every current permission, if not already there.
        app(TenantContext::class)->runAs($coop->id, $branch->id, function () use ($coop, $staff, $approver) {
            $manager = Role::firstOrCreate(['key' => 'manager'], ['name' => 'Manager']);
            $manager->syncPermissions(Permission::cases());
            $manager->assignTo($staff);
            $manager->assignTo($approver);

            // Tiny DEMO chart so the ledger can be tried locally. Not a recommended
            // chart of accounts: the real chart is the cooperative's accountant's decision.
            $accounts = app(LedgerAccountService::class);
            foreach ([
                ['DEMO-1', 'Assets (demo)', 'asset', null, null],
                ['DEMO-1.1', 'Cash in hand (demo)', 'asset', 'DEMO-1', 'cash'],
                ['DEMO-1.2', 'Bank account (demo)', 'asset', 'DEMO-1', null],
                ['DEMO-1.3', 'Loans to members (demo)', 'asset', 'DEMO-1', null],
                ['DEMO-1.4', 'Loan loss provision (demo)', 'asset', 'DEMO-1', 'loan_loss_provision'],
                ['DEMO-2', 'Liabilities (demo)', 'liability', null, null],
                ['DEMO-2.1', 'Member savings (demo)', 'liability', 'DEMO-2', null],
                ['DEMO-2.2', 'Term savings (demo)', 'liability', 'DEMO-2', null],
                ['DEMO-3', 'Equity (demo)', 'equity', null, null],
                ['DEMO-3.1', 'Share capital (demo)', 'equity', 'DEMO-3', 'share_capital'],
                ['DEMO-3.2', 'Reserve fund (demo)', 'equity', 'DEMO-3', 'reserve_fund'],
                ['DEMO-3.3', 'Opening balances (demo)', 'equity', 'DEMO-3', 'opening_balance'],
                ['DEMO-4', 'Income (demo)', 'income', null, null],
                ['DEMO-4.1', 'Loan interest income (demo)', 'income', 'DEMO-4', 'loan_interest_income'],
                ['DEMO-4.2', 'Penalty interest income (demo)', 'income', 'DEMO-4', 'loan_penalty_income'],
                ['DEMO-4.3', 'Loan fee income (demo)', 'income', 'DEMO-4', 'loan_fee_income'],
                ['DEMO-5', 'Expenses (demo)', 'expense', null, null],
                ['DEMO-5.1', 'Interest on savings (demo)', 'expense', 'DEMO-5', 'savings_interest_expense'],
                ['DEMO-5.2', 'Loan loss provision expense (demo)', 'expense', 'DEMO-5', 'loan_loss_provision_expense'],
            ] as [$code, $name, $type, $parent, $role]) {
                if (! LedgerAccount::query()->where('code', $code)->exists()) {
                    $accounts->create($code, $name, $type, $parent !== null, $parent);
                }
                if ($role) {
                    app(LedgerMappings::class)->set($role, $code);
                }
            }

            // DEMO bank account tag for liquidity, capital and bank reconciliation.
            $bank = LedgerAccount::query()->where('code', 'DEMO-1.2')->value('id');
            DB::table('ledger_account_tags')->insertOrIgnore(['cooperative_id' => $coop->id, 'ledger_account_id' => $bank, 'tag' => 'bank_deposit', 'created_at' => now(), 'updated_at' => now()]);

            // DEMO loan product (savings-secured). Placeholder terms for local testing only.
            if (! LoanProduct::query()->where('code', 'DEMO-LS')->exists()) {
                app(LoanService::class)->createProduct(['code' => 'DEMO-LS', 'name' => 'Loan against savings (demo)',
                    'collateral_type' => 'savings', 'interest_rate' => '1.000000', 'penalty_percent' => '0', 'service_fee_percent' => '0',
                    'repayment_frequency' => 'monthly', 'schedule_method' => 'equal_principal', 'max_term_months' => 24,
                    'max_amount' => '500000.00', 'ledger_account_code' => 'DEMO-1.3']);
            }

            // DEMO savings products. Rates are placeholders for local testing only, not advice.
            foreach ([
                ['DEMO-GEN', 'General savings (demo)', 'general', null, 'DEMO-2.1'],
                ['DEMO-T12', 'Term savings 12 months (demo)', 'term', 12, 'DEMO-2.2'],
            ] as [$code, $name, $type, $term, $account]) {
                if (! SavingsProduct::query()->where('code', $code)->exists()) {
                    app(SavingsService::class)->createProduct(['code' => $code, 'name' => $name, 'type' => $type,
                        'interest_rate' => '1.000000', 'interest_posting' => 'quarterly', 'term_months' => $term, 'ledger_account_code' => $account]);
                }
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
