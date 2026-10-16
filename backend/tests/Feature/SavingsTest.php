<?php

namespace Tests\Feature;

use App\Accounting\LedgerAccountService;
use App\Accounting\PostingService;
use App\Authorization\Permission;
use App\Models\Branch;
use App\Models\Cooperative;
use App\Models\Member;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Savings\SavingsLimits;
use App\Savings\SavingsService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCooperative;
use Tests\TestCase;

class SavingsTest extends TestCase
{
    use BuildsCooperative, RefreshDatabase;

    private int $general;

    private int $term;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCooperative();
        $this->signIn();
        $this->general = $this->product(['code' => 'GEN', 'type' => 'general', 'minimum_balance' => '100.00'])->json('data.id');
        $this->term = $this->product(['code' => 'T12', 'type' => 'term', 'term_months' => 12, 'ledger_account_code' => 'L2'])->json('data.id');
        $this->primaryCapital('1000000.00'); // Rs 10 lakh: member limit Rs 1 lakh, total Rs 1.5 crore
    }

    private function product(array $v)
    {
        return $this->postJson('/api/savings-products', $v + [
            'name' => 'Test product', 'interest_rate' => '6.000000', 'interest_posting' => 'quarterly', 'ledger_account_code' => 'L1',
        ])->assertCreated();
    }

    private function primaryCapital(string $amount): void
    {
        $this->inCoop(fn () => app(PostingService::class)->post('Share capital (test opening)', [
            ['account_code' => 'A1', 'debit' => $amount], ['account_code' => 'E1', 'credit' => $amount],
        ]));
    }

    private function account(?Member $member = null, ?int $product = null): int
    {
        $member ??= $this->activeMember();

        return $this->postJson("/api/members/{$member->id}/savings-accounts", ['savings_product_id' => $product ?? $this->general])->assertCreated()->json('data.id');
    }

    private function deposit(int $account, string $amount, string $key, array $extra = [])
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson("/api/savings-accounts/{$account}/deposits", ['amount' => $amount] + $extra);
    }

    private function withdraw(int $account, string $amount, string $key, array $extra = [])
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson("/api/savings-accounts/{$account}/withdrawals", ['amount' => $amount] + $extra);
    }

    public function test_deposit_and_withdrawal_post_and_keep_running_balance(): void
    {
        $a = $this->account();

        $this->deposit($a, '5000.00', 'dep-000001')->assertCreated()->assertJsonPath('data.balance_after', '5000.00');
        $this->withdraw($a, '1200.50', 'wd-0000001')->assertCreated()->assertJsonPath('data.balance_after', '3799.50');

        $this->getJson("/api/savings-accounts/{$a}")->assertJsonPath('data.balance', '3799.50')
            ->assertJsonCount(2, 'data.transactions')->assertJsonPath('data.transactions.1.type', 'withdrawal');
        $this->assertSame('-3799.50', $this->balanceOf('L1'));
        $this->assertSame('1003799.50', $this->balanceOf('A1'));
        $this->artisan('ledger:verify')->assertSuccessful();
    }

    public function test_minimum_balance_and_no_overdraft(): void
    {
        $a = $this->account();
        $this->deposit($a, '1000.00', 'dep-000002');

        $this->withdraw($a, '950.00', 'wd-0000002')->assertUnprocessable()
            ->assertJsonPath('message', 'Insufficient available balance: Rs 900.00 can be withdrawn.');
        $this->withdraw($a, '900.00', 'wd-0000003')->assertCreated();

        // The database itself refuses a negative balance.
        try {
            DB::table('savings_accounts')->where('id', $a)->update(['balance' => '-1.00']);
            $this->fail('negative balance accepted');
        } catch (QueryException $e) {
            $this->assertSame(3819, $e->errorInfo[1]);
        }
    }

    public function test_member_savings_limit_is_ten_percent_of_primary_capital(): void
    {
        $a = $this->account();
        $this->deposit($a, '100000.00', 'dep-000003')->assertCreated();

        $this->deposit($a, '0.01', 'dep-000004')->assertUnprocessable()
            ->assertJsonPath('message', "This deposit would bring the member's savings to Rs 100000.01; the limit is 10% of primary capital, Rs 100000.00 (Act s.52(1)).");
    }

    public function test_member_limit_counts_all_of_the_members_accounts(): void
    {
        $member = $this->activeMember();
        $this->deposit($this->account($member), '60000.00', 'dep-000005')->assertCreated();

        $this->deposit($this->account($member), '40000.01', 'dep-000006')->assertUnprocessable();
    }

    public function test_total_savings_limit_is_fifteen_times_primary_capital(): void
    {
        $a = $this->account();
        // Simulate Rs 1.5 crore already saved by others (bypassing the service; this test checks the limit only).
        $other = $this->account();
        DB::table('savings_accounts')->where('id', $other)->update(['balance' => '15000000.00']);

        $this->deposit($a, '0.01', 'dep-000015')->assertUnprocessable()
            ->assertJsonPath('message', 'Total savings would reach Rs 15000000.01; the limit is 15 times primary capital, Rs 15000000.00 (Act s.50(7)).');
    }

    public function test_member_limit_uses_primary_capital_at_last_shrawan_or_magh_count(): void
    {
        $limits = $this->inCoop(fn () => app(SavingsLimits::class));
        // 2026-10-16 = 2083 Asoj 30: last count date is Shrawan 1, 2083 = 2026-07-17.
        $this->assertSame('2026-07-17', $limits->lastCountDate('2026-10-16'));
        // 2027-02-01 = Magh 2083: Magh 1, 2083 = 2027-01-15.
        $this->assertSame('2027-01-15', $limits->lastCountDate('2027-02-01'));
    }

    public function test_source_of_funds_from_ten_lakh(): void
    {
        $this->primaryCapital('20000000.00'); // raise limits: member limit 21 lakh
        $a = $this->account();

        $this->deposit($a, '1000000.00', 'dep-000007')->assertUnprocessable()
            ->assertJsonPath('message', 'Source of funds is required for deposits of Rs 10 lakh or more (AML Instruction 8; Act s.52क).');
        $this->deposit($a, '1000000.00', 'dep-000008', ['source_of_funds' => 'Remittance from son (test)'])->assertCreated();
        $this->getJson('/api/reports/threshold-transactions')->assertJsonPath('data.0.total', '1000000.00');
    }

    public function test_cash_withdrawal_above_five_lakh_needs_reason_and_authority(): void
    {
        $this->primaryCapital('20000000.00'); // member limit now Rs 21 lakh
        $a = $this->account();
        $this->deposit($a, '1500000.00', 'dep-000009', ['source_of_funds' => 'Business income (test)'])->assertCreated();

        $this->withdraw($a, '500000.00', 'wd-0000004')->assertCreated(); // exactly Rs 5 lakh: no reason needed
        $this->withdraw($a, '500000.01', 'wd-0000005')->assertUnprocessable()
            ->assertJsonPath('message', 'Cash withdrawals above Rs 5 lakh need a written reason (Nepal Gazette, from 15 Jan 2026).');
        $this->withdraw($a, '600000.00', 'wd-0000006', ['large_cash_reason' => 'Land purchase (test)'])->assertCreated();
        $this->assertSame('Land purchase (test)', DB::table('savings_transactions')->where('amount', '600000.00')->value('large_cash_reason'));

        // A teller without large-withdrawal authority is refused even with a reason.
        $teller = User::factory()->create();
        $this->branch->cooperative->users()->attach($teller, ['branch_id' => $this->branch->id]);
        $this->inCoop(function () use ($teller) {
            $role = Role::create(['key' => 'teller', 'name' => 'Teller']);
            $role->syncPermissions([Permission::SavingsWithdraw]);
            $role->assignTo($teller);
        });
        $message = $this->inCoop(fn () => rescue(
            fn () => app(SavingsService::class)->withdraw(SavingsAccount::query()->find($a), '600000.00', $teller, 'wd-0000007', 'Land purchase'),
            fn ($e) => $e->getMessage(), false));
        $this->assertSame('Cash withdrawals above Rs 5 lakh need approval by someone with large-withdrawal authority.', $message);
    }

    public function test_term_savings_cannot_be_withdrawn_before_maturity(): void
    {
        $a = $this->account(null, $this->term);
        $this->getJson("/api/savings-accounts/{$a}")->assertJsonPath('data.maturity_date', '2027-10-16');
        $this->deposit($a, '1000.00', 'dep-000011')->assertCreated();

        $this->withdraw($a, '10.00', 'wd-0000008')->assertUnprocessable()
            ->assertJsonPath('message', 'Term savings cannot be withdrawn before maturity (2027-10-16).');
    }

    public function test_idempotent_deposit_posts_once(): void
    {
        $a = $this->account();

        $first = $this->deposit($a, '700.00', 'dep-000012')->assertCreated()->json('data.transaction_id');
        $this->deposit($a, '700.00', 'dep-000012')->assertCreated()->assertJsonPath('data.transaction_id', $first)->assertJsonPath('data.balance', '700.00');
        $this->deposit($a, '701.00', 'dep-000012')->assertUnprocessable();

        $this->assertSame(1, DB::table('savings_transactions')->where('savings_account_id', $a)->count());
    }

    public function test_only_active_members_and_accounts(): void
    {
        $applicant = $this->activeMember(['status' => 'applied', 'member_number' => null]);
        $this->postJson("/api/members/{$applicant->id}/savings-accounts", ['savings_product_id' => $this->general])->assertUnprocessable()
            ->assertJsonPath('message', 'Savings accounts are for active members only (Act s.50(1)).');

        $a = $this->account();
        DB::table('savings_accounts')->where('id', $a)->update(['status' => 'frozen']);
        $this->deposit($a, '10.00', 'dep-000013')->assertUnprocessable();
    }

    public function test_product_rules(): void
    {
        $base = ['name' => 'x', 'interest_rate' => '5.000000', 'interest_posting' => 'monthly', 'ledger_account_code' => 'L1'];
        $this->postJson('/api/savings-products', $base + ['code' => 'T48', 'type' => 'term', 'term_months' => 48])->assertUnprocessable()
            ->assertJsonPath('message', 'Term savings need a term of 1 to 36 months (NCRA Std 2/3.1).');
        $this->postJson('/api/savings-products', $base + ['code' => 'G2', 'type' => 'general', 'term_months' => 6])->assertUnprocessable()
            ->assertJsonPath('message', 'Only term products have a term.');
        $this->postJson('/api/savings-products', ['ledger_account_code' => 'E1'] + $base + ['code' => 'G3', 'type' => 'general'])->assertUnprocessable()
            ->assertJsonPath('message', 'Savings must be held in a posting liability account.');
        $this->postJson('/api/savings-products', $base + ['code' => 'GEN', 'type' => 'general'])->assertUnprocessable()
            ->assertJsonPath('message', 'Product code GEN already exists.');
    }

    public function test_reconciliation_detects_sub_ledger_drift(): void
    {
        $a = $this->account();
        $this->deposit($a, '500.00', 'dep-000014');
        $this->artisan('ledger:verify')->assertSuccessful();

        DB::table('savings_accounts')->where('id', $a)->update(['balance' => '600.00']); // drift (bypassing the service)
        $this->artisan('ledger:verify')->expectsOutputToContain('savings accounts total 600.00')->assertFailed();
    }

    public function test_cross_tenant_accounts_are_not_found(): void
    {
        $other = Branch::factory()->headOffice()->for(Cooperative::factory())->create();
        $theirs = app(TenantContext::class)->runAs($other->cooperative_id, $other->id, function () use ($other) {
            $account = app(LedgerAccountService::class)->create('L9', 'Savings', 'liability', true);
            $product = app(SavingsService::class)->createProduct(['code' => 'X', 'name' => 'x', 'type' => 'general', 'interest_rate' => '1', 'interest_posting' => 'yearly', 'ledger_account_code' => 'L9']);
            $member = Member::query()->forceCreate(['cooperative_id' => $other->cooperative_id, 'branch_id' => $other->id, 'member_number' => 1, 'status' => 'active',
                'full_name' => 'Other', 'date_of_birth' => '1990-01-01', 'gender' => 'male', 'declares_nepali_citizen' => true,
                'declares_resides_in_working_area' => true, 'declares_no_other_same_type_membership' => true, 'applied_on' => '2026-01-01']);

            return app(SavingsService::class)->open($member, $product);
        });

        $this->getJson("/api/savings-accounts/{$theirs->id}")->assertNotFound();
        $this->deposit($theirs->id, '10.00', 'dep-000016')->assertNotFound();
        $this->postJson("/api/members/{$theirs->member_id}/savings-accounts", ['savings_product_id' => $this->general])->assertNotFound();
    }
}
