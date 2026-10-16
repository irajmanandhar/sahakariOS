<?php

namespace Tests\Support;

use App\Accounting\LedgerAccountService;
use App\Accounting\LedgerMappings;
use App\Accounting\TrialBalance;
use App\Authorization\Permission;
use App\Calendar\BusinessDayService;
use App\Calendar\FiscalYearService;
use App\Models\Branch;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;

/**
 * Synthetic cooperative for money-module tests: fiscal year 2083/84, open
 * business day 2026-10-16, a small TEST chart of accounts mapped to the
 * ledger roles, and a signed-in user with every permission.
 * The chart is test data, not a recommended chart of accounts.
 */
trait BuildsCooperative
{
    protected Branch $branch;

    protected User $user;

    protected const PASSWORD = 'correct-horse-battery-staple';

    /** code => [name, type, parent code, ledger role] */
    protected const TEST_CHART = [
        'A' => ['Assets', 'asset', null, null],
        'A1' => ['Cash', 'asset', 'A', 'cash'],
        'A2' => ['Loans to members', 'asset', 'A', null],
        'A3' => ['Loan loss provision', 'asset', 'A', 'loan_loss_provision'],
        'A4' => ['Bank deposits', 'asset', 'A', null],
        'L' => ['Liabilities', 'liability', null, null],
        'L1' => ['Member savings - general', 'liability', 'L', null],
        'L2' => ['Member savings - term', 'liability', 'L', null],
        'E' => ['Equity', 'equity', null, null],
        'E1' => ['Share capital', 'equity', 'E', 'share_capital'],
        'E2' => ['Reserve fund', 'equity', 'E', 'reserve_fund'],
        'I' => ['Income', 'income', null, null],
        'I1' => ['Loan interest', 'income', 'I', 'loan_interest_income'],
        'I2' => ['Penalty interest', 'income', 'I', 'loan_penalty_income'],
        'I3' => ['Loan fees', 'income', 'I', 'loan_fee_income'],
        'X' => ['Expenses', 'expense', null, null],
        'X1' => ['Savings interest', 'expense', 'X', 'savings_interest_expense'],
        'X2' => ['Provision expense', 'expense', 'X', 'loan_loss_provision_expense'],
    ];

    protected function buildCooperative(string $now = '2026-10-16 04:15:00'): void
    {
        Date::setTestNow($now);
        $this->branch = Branch::factory()->headOffice()->create();
        $this->user = User::factory()->create(['email' => 'staff@example.test', 'password' => self::PASSWORD]);
        $this->branch->cooperative->users()->attach($this->user, ['branch_id' => $this->branch->id]);

        $this->inCoop(function () {
            app(FiscalYearService::class)->open(2083);
            app(BusinessDayService::class)->open(BusinessDayService::today());
            $accounts = app(LedgerAccountService::class);
            foreach (self::TEST_CHART as $code => [$name, $type, $parent]) {
                $accounts->create($code, "{$name} (test)", $type, $parent !== null, $parent);
            }
            foreach (self::TEST_CHART as $code => [, , , $role]) {
                if ($role) {
                    app(LedgerMappings::class)->set($role, $code);
                }
            }
            $role = Role::create(['key' => 'all', 'name' => 'All (test)']);
            $role->syncPermissions(Permission::cases());
            $role->assignTo($this->user);
        });
    }

    protected function inCoop(callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($this->branch->cooperative_id, $this->branch->id, $callback);
    }

    protected function signIn(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'staff@example.test', 'password' => self::PASSWORD])->assertOk();
        $this->putJson('/api/auth/cooperative', ['cooperative_id' => $this->branch->cooperative_id])->assertOk();
    }

    /** An active member created directly (the full KYM flow is covered by MemberTest). */
    protected function activeMember(array $attributes = []): Member
    {
        static $number = 0;
        $number++;

        return $this->inCoop(fn () => Member::query()->forceCreate($attributes + [
            'cooperative_id' => $this->branch->cooperative_id,
            'branch_id' => $this->branch->id,
            'member_number' => $number,
            'status' => 'active',
            'full_name' => "Test Member {$number}",
            'date_of_birth' => '1990-01-01',
            'gender' => 'female',
            'declares_nepali_citizen' => true,
            'declares_resides_in_working_area' => true,
            'declares_no_other_same_type_membership' => true,
            'applied_on' => '2026-01-01',
            'risk_level' => 'low',
        ]));
    }

    protected function balanceOf(string $code): string
    {
        return $this->inCoop(function () use ($code) {
            $row = collect(app(TrialBalance::class)->compute()['accounts'])->firstWhere('code', $code);

            return $row ? ($row['debit_balance'] !== '0.00' ? $row['debit_balance'] : '-'.$row['credit_balance']) : '0.00';
        });
    }
}
