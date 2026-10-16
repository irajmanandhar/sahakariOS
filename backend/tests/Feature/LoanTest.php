<?php

namespace Tests\Feature;

use App\Accounting\PostingRejected;
use App\Accounting\PostingService;
use App\Loans\LoanService;
use App\Loans\ScheduleBuilder;
use App\Models\Branch;
use App\Models\Cooperative;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Savings\SavingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCooperative;
use Tests\TestCase;

class LoanTest extends TestCase
{
    use BuildsCooperative, RefreshDatabase;

    private User $approver;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCooperative();
        $this->approver = $this->secondUser();
        $this->primaryCapital('100000000.00'); // Rs 10 crore: single-member limit Rs 1.5 crore
        $this->signIn();
    }

    private function primaryCapital(string $amount): void
    {
        $this->inCoop(fn () => app(PostingService::class)->post('Share capital (test opening)', [
            ['account_code' => 'A1', 'debit' => $amount], ['account_code' => 'E1', 'credit' => $amount],
        ]));
    }

    private function product(string $collateral, array $v = []): LoanProduct
    {
        $n = ++$this->seq;

        return $this->inCoop(fn () => app(LoanService::class)->createProduct($v + [
            'code' => "LP{$n}", 'name' => 'Test loan', 'collateral_type' => $collateral, 'interest_rate' => '12.000000',
            'penalty_percent' => '2.00', 'service_fee_percent' => '1.00', 'repayment_frequency' => 'monthly',
            'schedule_method' => 'equal_principal', 'max_term_months' => 60, 'max_grace_months' => 0, 'max_amount' => '5000000.00',
            'max_ltv_percent' => $collateral === 'real_estate' ? '60.00' : null, 'ledger_account_code' => 'A2',
        ]));
    }

    private function savings(Member $member, string $amount, string $type = 'general'): SavingsAccount
    {
        $n = ++$this->seq;

        return $this->inCoop(function () use ($member, $amount, $type, $n) {
            $product = SavingsProduct::query()->where('type', $type)->first()
                ?? app(SavingsService::class)->createProduct(['code' => "S{$type}", 'name' => $type, 'type' => $type, 'interest_rate' => '5', 'interest_posting' => 'yearly', 'ledger_account_code' => 'L1']);
            $account = app(SavingsService::class)->open($member, $product);
            if ($amount !== '0') {
                app(SavingsService::class)->deposit($account, $amount, $this->user, "seed-sav-{$member->id}-{$n}");
            }

            return $account->refresh();
        });
    }

    private function apply(Member $member, LoanProduct $product, array $v)
    {
        return $this->postJson("/api/members/{$member->id}/loans", $v + ['loan_product_id' => $product->id, 'term_months' => 12, 'purpose' => 'Business (test)']);
    }

    private function asApprover(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'approver@example.test', 'password' => self::PASSWORD])->assertOk();
        $this->putJson('/api/auth/cooperative', ['cooperative_id' => $this->branch->cooperative_id])->assertOk();
    }

    public function test_savings_secured_loan_end_to_end(): void
    {
        $m = $this->activeMember();
        $pledged = $this->savings($m, '100000.00');
        $product = $this->product('savings');

        $this->apply($m, $product, ['principal' => '90000.01', 'pledged_savings_account_id' => $pledged->id])->assertUnprocessable()
            ->assertJsonPath('message', 'A savings-secured loan can be at most 90% of the pledged savings, Rs 90000.00 (NCRA Std 3/2.1).');
        $loanId = $this->apply($m, $product, ['principal' => '90000.00', 'pledged_savings_account_id' => $pledged->id])
            ->assertCreated()->assertJsonPath('data.status', 'applied')->json('data.id');

        $this->postJson("/api/loans/{$loanId}/approve")->assertUnprocessable()
            ->assertJsonPath('message', 'The person who entered a loan application cannot also decide it.');

        $this->asApprover();
        $this->postJson("/api/loans/{$loanId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->withHeader('Idempotency-Key', 'disburse-001')->postJson("/api/loans/{$loanId}/disburse", ['savings_account_id' => $pledged->id])
            ->assertCreated()->assertJsonPath('data.status', 'disbursed')->assertJsonPath('data.outstanding_principal', '90000.00');

        $account = $this->inCoop(fn () => $pledged->refresh());
        $this->assertSame('189100.00', $account->balance);           // + 90000 - 1% fee (900)
        $this->assertSame('100000.00', $account->hold_amount);       // lien: loan stays <= 90% of held savings
        $this->assertSame('90000.00', $this->balanceOf('A2'));
        $this->assertSame('-900.00', $this->balanceOf('I3'));
        $this->getJson("/api/loans/{$loanId}")->assertJsonCount(12, 'data.schedule')
            ->assertJsonPath('data.schedule.0.due_date', '2026-11-16')->assertJsonPath('data.schedule.0.principal_due', '7500.00');
        $this->artisan('ledger:verify')->assertSuccessful();

        // Retry of the disbursement does nothing new.
        $this->withHeader('Idempotency-Key', 'disburse-001')->postJson("/api/loans/{$loanId}/disburse", ['savings_account_id' => $pledged->id])->assertCreated();
        $this->assertSame(1, DB::table('loan_transactions')->where('loan_id', $loanId)->count());
    }

    public function test_membership_age_rules(): void
    {
        $new = $this->activeMember(['decided_at' => '2026-08-01 10:00:00']); // 2.5 months
        $pledged = $this->savings($new, '10000.00');

        $this->apply($new, $this->product('savings'), ['principal' => '1000.00', 'pledged_savings_account_id' => $pledged->id])->assertUnprocessable()
            ->assertJsonPath('message', 'This loan needs at least 3 months of membership (NCRA Std 3/1.1; Act s.50(8)).');

        $fiveMonths = $this->activeMember(['decided_at' => '2026-05-16 10:00:00']);
        $this->savings($fiveMonths, '10000.00', 'regular');
        $this->apply($fiveMonths, $this->product('unsecured'), ['principal' => '1000.00'])->assertUnprocessable()
            ->assertJsonPath('message', 'This loan needs at least 6 months of membership (NCRA Std 3/1.1; Act s.50(8)).');
    }

    public function test_unsecured_rules(): void
    {
        $m = $this->activeMember();
        $product = $this->product('unsecured');
        $g1 = $this->activeMember();
        $g2 = $this->activeMember();

        $this->savings($m, '40000.00');
        $this->apply($m, $product, ['principal' => '1000.00', 'guarantor_member_ids' => [$g1->id, $g2->id]])->assertUnprocessable()
            ->assertJsonPath('message', 'Unsecured loans are only for members who save regularly (an active regular savings account) (NCRA Std 3/1.3).');

        $this->savings($m, '30000.00', 'regular'); // total savings 70,000 -> 5x = 3,50,000 -> capped at 3 lakh
        $this->apply($m, $product, ['principal' => '300000.01', 'guarantor_member_ids' => [$g1->id, $g2->id]])->assertUnprocessable()
            ->assertJsonPath('message', "An unsecured loan can be at most the lower of 5 times the member's savings and Rs 3 lakh: Rs 300000.00 (NCRA Std 3/1.3).");
        $this->apply($m, $product, ['principal' => '300000.00', 'guarantor_member_ids' => [$g1->id]])->assertUnprocessable()
            ->assertJsonPath('message', 'Unsecured loans need at least 2 active member guarantors other than the borrower (NCRA Std 3/1.3).');
        $this->apply($m, $product, ['principal' => '300000.00', 'guarantor_member_ids' => [$g1->id, $m->id]])->assertUnprocessable();
        $this->apply($m, $product, ['principal' => '300000.00', 'guarantor_member_ids' => [$g1->id, $g2->id]])->assertCreated();
    }

    public function test_single_member_limit_counts_existing_loans(): void
    {
        $m = $this->activeMember();
        $product = $this->product('real_estate', ['max_amount' => '50000000.00']);

        $first = $this->apply($m, $product, ['principal' => '10000000.00', 'collateral_value' => '20000000.00'])->assertCreated()->json('data.id');
        $this->asApprover();
        $this->postJson("/api/loans/{$first}/approve")->assertOk();
        // Approved 1 crore + 50.00001 lakh > 15% of 10.00 crore (1.5 crore)
        $this->apply($m, $product, ['principal' => '5000001.00', 'collateral_value' => '20000000.00'])->assertUnprocessable()
            ->assertJsonPath('message', "The member's loans would total Rs 15000001.00; the limit is 15% of primary capital, Rs 15000000.00 (Act s.52(2)).");
    }

    public function test_real_estate_loan_to_value(): void
    {
        $m = $this->activeMember();
        $product = $this->product('real_estate');

        $this->apply($m, $product, ['principal' => '600000.01', 'collateral_value' => '1000000.00'])->assertUnprocessable()
            ->assertJsonPath('message', 'The loan can be at most 60.00% of the collateral valuation, Rs 600000.00.');
        $this->apply($m, $product, ['principal' => '600000.00'])->assertUnprocessable()->assertJsonPath('message', 'Enter the valuation of the real-estate collateral.');
        $this->apply($m, $product, ['principal' => '600000.00', 'collateral_value' => '1000000.00'])->assertCreated();
    }

    public function test_disbursement_only_into_borrowers_own_account(): void
    {
        $m = $this->activeMember();
        $other = $this->activeMember();
        $pledged = $this->savings($m, '100000.00');
        $foreign = $this->savings($other, '10.00');
        $loanId = $this->apply($m, $this->product('savings'), ['principal' => '50000.00', 'pledged_savings_account_id' => $pledged->id])->json('data.id');
        $this->asApprover();
        $this->postJson("/api/loans/{$loanId}/approve")->assertOk();

        $this->withHeader('Idempotency-Key', 'disburse-002')->postJson("/api/loans/{$loanId}/disburse", ['savings_account_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonPath('message', "Loans are disbursed only into the borrower's own active savings account (NCRA Std 8/10).");
        $this->withHeader('Idempotency-Key', 'disburse-003')->postJson('/api/loans/'.$loanId.'/disburse', ['savings_account_id' => $pledged->id])->assertCreated();
        $this->withHeader('Idempotency-Key', 'disburse-004')->postJson('/api/loans/'.$loanId.'/disburse', ['savings_account_id' => $pledged->id])
            ->assertUnprocessable()->assertJsonPath('message', 'Only approved loans can be disbursed.');
    }

    public function test_product_rules(): void
    {
        $base = ['code' => 'X1', 'name' => 'x', 'interest_rate' => '10', 'repayment_frequency' => 'monthly', 'schedule_method' => 'equal_principal',
            'max_term_months' => 12, 'max_amount' => '100000.00', 'ledger_account_code' => 'A2'];
        $this->postJson('/api/loan-products', $base + ['collateral_type' => 'savings', 'penalty_percent' => '5.01'])->assertUnprocessable()
            ->assertJsonPath('message', 'Penalty interest may not exceed 5% of the overdue interest (Act s.50(5क)).');
        $this->postJson('/api/loan-products', $base + ['collateral_type' => 'real_estate'])->assertUnprocessable()
            ->assertJsonPath('message', "Real-estate loans need the maximum loan-to-value % from the cooperative's policy (NCRA Std 3/2.2).");
        $this->postJson('/api/loan-products', ['ledger_account_code' => 'L1'] + $base + ['collateral_type' => 'savings'])->assertUnprocessable()
            ->assertJsonPath('message', 'Loans must be held in a posting asset account.');
        $this->postJson('/api/loan-products', $base + ['collateral_type' => 'savings'])->assertCreated();
    }

    public function test_schedule_builder(): void
    {
        $emi = ScheduleBuilder::build('100000.00', '12', 12, 0, 'monthly', 'equal_installment', '2026-10-16');
        $this->assertCount(12, $emi);
        $this->assertSame('7884.88', $emi[0]['principal_due']); // EMI 8884.88 - interest 1000.00
        $this->assertSame('100000.00', number_format(array_sum(array_map(fn ($r) => (float) $r['principal_due'], $emi)), 2, '.', ''));

        $grace = ScheduleBuilder::build('120000.00', '12', 12, 3, 'quarterly', 'equal_principal', '2026-01-31');
        $this->assertSame(['0.00', '40000.00', '40000.00', '40000.00'], array_column($grace, 'principal_due'));
        $this->assertSame('2026-04-30', $grace[0]['due_date']); // no month overflow

        $this->expectException(PostingRejected::class);
        ScheduleBuilder::build('1000.00', '12', 10, 0, 'quarterly', 'equal_principal', '2026-10-16'); // 10 not a multiple of 3
    }

    public function test_other_cooperatives_loans_are_not_found(): void
    {
        $other = Branch::factory()->headOffice()->for(Cooperative::factory())->create();
        $theirs = DB::table('loans')->insertGetId([
            'cooperative_id' => $other->cooperative_id, 'member_id' => DB::table('members')->insertGetId([
                'cooperative_id' => $other->cooperative_id, 'branch_id' => $other->id, 'status' => 'active', 'full_name' => 'Other',
                'date_of_birth' => '1990-01-01', 'gender' => 'male', 'declares_nepali_citizen' => 1, 'declares_resides_in_working_area' => 1,
                'declares_no_other_same_type_membership' => 1, 'applied_on' => '2026-01-01']),
            'loan_product_id' => DB::table('loan_products')->insertGetId(['cooperative_id' => $other->cooperative_id, 'code' => 'O', 'name' => 'o',
                'collateral_type' => 'unsecured', 'interest_rate' => 1, 'repayment_frequency' => 'monthly', 'schedule_method' => 'equal_principal',
                'max_term_months' => 1, 'max_amount' => 1, 'ledger_account_id' => DB::table('ledger_accounts')->insertGetId([
                    'cooperative_id' => $other->cooperative_id, 'code' => 'A', 'name' => 'a', 'type' => 'asset', 'is_postable' => 1])]),
            'loan_number' => 1, 'status' => 'applied', 'principal' => 1, 'interest_rate' => 1, 'penalty_percent' => 0, 'term_months' => 1,
            'purpose' => 'x', 'applied_on' => '2026-10-16',
        ]);

        $this->getJson("/api/loans/{$theirs}")->assertNotFound();
        $this->postJson("/api/loans/{$theirs}/approve")->assertNotFound();
    }
}
