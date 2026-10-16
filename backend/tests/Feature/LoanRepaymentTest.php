<?php

namespace Tests\Feature;

use App\Accounting\PostingService;
use App\Calendar\BusinessDayService;
use App\Loans\LoanClassifier;
use App\Loans\LoanService;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Savings\SavingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCooperative;
use Tests\TestCase;

/**
 * Real-estate loan Rs 1,20,000 at 12%, 12 monthly equal-principal instalments of Rs 10,000,
 * disbursed 2026-10-16; first due date 2026-11-16. Penalty 2% of overdue interest.
 */
class LoanRepaymentTest extends TestCase
{
    use BuildsCooperative, RefreshDatabase;

    private User $approver;

    private SavingsAccount $savings;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCooperative();
        $this->approver = $this->secondUser();
        $this->inCoop(fn () => app(PostingService::class)->post('Share capital (test opening)', [['account_code' => 'A1', 'debit' => '100000000'], ['account_code' => 'E1', 'credit' => '100000000']]));
        $this->signIn();
    }

    private function loan(string $collateral = 'real_estate', string $principal = '120000.00'): Loan
    {
        $n = ++$this->seq;

        return $this->inCoop(function () use ($collateral, $principal, $n) {
            $member = $this->activeMember();
            $sp = SavingsProduct::query()->first()
                ?? app(SavingsService::class)->createProduct(['code' => 'SG', 'name' => 'g', 'type' => 'general', 'interest_rate' => '0', 'interest_posting' => 'yearly', 'ledger_account_code' => 'L1']);
            $this->savings = app(SavingsService::class)->open($member, $sp);
            app(SavingsService::class)->deposit($this->savings, '200000.00', $this->user, "seed-{$n}");
            $product = app(LoanService::class)->createProduct([
                'code' => "LP{$n}", 'name' => 'Test', 'collateral_type' => $collateral, 'interest_rate' => '12.000000', 'penalty_percent' => '2.00',
                'service_fee_percent' => '0', 'repayment_frequency' => 'monthly', 'schedule_method' => 'equal_principal', 'max_term_months' => 60,
                'max_amount' => '5000000.00', 'max_ltv_percent' => $collateral === 'real_estate' ? '60.00' : null, 'ledger_account_code' => 'A2',
            ]);
            $loan = app(LoanService::class)->apply($member, $product, ['principal' => $principal, 'term_months' => 12, 'purpose' => 'x',
                'collateral_value' => $collateral === 'real_estate' ? '1000000.00' : null,
                'pledged_savings_account_id' => $collateral === 'savings' ? $this->savings->id : null], $this->user);
            app(LoanService::class)->approve($loan, $this->approver);

            return app(LoanService::class)->disburse($loan, $this->savings->refresh(), $this->approver, "disburse-{$n}");
        });
    }

    /** Close the current business day and open $date (running end-of-day processing). */
    private function moveTo(string $date): void
    {
        $this->inCoop(fn () => app(BusinessDayService::class)->close($this->user));
        Date::setTestNow("{$date} 04:15:00");
        $this->inCoop(fn () => app(BusinessDayService::class)->open($date));
    }

    private function repay(Loan $loan, string $amount, string $key, array $extra = [])
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson("/api/loans/{$loan->id}/repayments", $extra + ['amount' => $amount, 'channel' => 'cash']);
    }

    public function test_daily_interest_on_declining_balance_and_allocation(): void
    {
        $loan = $this->loan();
        $this->moveTo('2026-11-15');

        // 120000 x 12% x 30 / 365 = 1183.56
        $this->getJson("/api/loans/{$loan->id}/repayment-quote")->assertJsonPath('data.interest', '1183.56')
            ->assertJsonPath('data.penalty', '0.00')->assertJsonPath('data.payoff', '121183.56');

        $this->repay($loan, '1183.55', 'repay-0001')->assertUnprocessable()
            ->assertJsonPath('message', 'The payment must at least cover penalty and interest due: Rs 1183.56.');
        $this->repay($loan, '11183.56', 'repay-0002')->assertCreated()
            ->assertJsonPath('data.interest', '1183.56')->assertJsonPath('data.principal', '10000.00')->assertJsonPath('data.outstanding_after', '110000.00');

        $this->assertSame('110000.00', $this->balanceOf('A2'));
        $this->assertSame('-1183.56', $this->balanceOf('I1'));
        $this->getJson("/api/loans/{$loan->id}")->assertJsonPath('data.schedule.0.principal_paid', '10000.00')
            ->assertJsonPath('data.interest_paid_through', '2026-11-15');
        $this->artisan('ledger:verify')->assertSuccessful();
    }

    public function test_penalty_is_a_percentage_of_overdue_interest_only(): void
    {
        $loan = $this->loan();
        $this->moveTo('2026-11-15');
        $this->repay($loan, '11183.56', 'repay-0003')->assertCreated();
        $this->moveTo('2026-12-20'); // due 2026-12-16 passed unpaid

        // Interest 15 Nov -> 20 Dec (35 days) on 110000 = 1265.75.
        // Overdue part 15 Nov -> 16 Dec (31 days) = 1121.10; penalty 2% = 22.42.
        $this->getJson("/api/loans/{$loan->id}/repayment-quote")->assertJsonPath('data.interest', '1265.75')
            ->assertJsonPath('data.penalty', '22.42')->assertJsonPath('data.principal_overdue', '10000.00');
        $this->repay($loan, '11288.17', 'repay-0004')->assertCreated()->assertJsonPath('data.penalty', '22.42');
        $this->assertSame('-22.42', $this->balanceOf('I2'));
    }

    public function test_payoff_closes_loan_and_overpayment_is_refused(): void
    {
        $loan = $this->loan();
        $this->moveTo('2026-11-15');

        $this->repay($loan, '121183.57', 'repay-0005')->assertUnprocessable()
            ->assertJsonPath('message', 'The payment is more than the amount needed to close the loan: Rs 121183.56.');
        $this->repay($loan, '121183.56', 'repay-0006', ['source_of_funds' => null])->assertCreated()->assertJsonPath('data.outstanding_after', '0.00');
        $this->getJson("/api/loans/{$loan->id}")->assertJsonPath('data.status', 'closed');
        $this->repay($loan, '1.00', 'repay-0007')->assertUnprocessable()->assertJsonPath('message', 'Only disbursed, open loans can be repaid.');
    }

    public function test_repayment_from_savings_and_lien_release(): void
    {
        $loan = $this->loan('savings', '100000.00'); // savings 200000 -> lien 111111.12
        $this->assertSame('111111.12', $this->inCoop(fn () => $this->savings->refresh())->hold_amount);
        $this->moveTo('2026-11-15');

        $borrowersAccount = $this->savings;
        $this->loan(); // another member with their own savings account ($this->savings now points to it)
        $this->repay($loan, '986.30', 'repay-0008', ['channel' => 'savings', 'savings_account_id' => $this->savings->id])->assertUnprocessable()
            ->assertJsonPath('message', "Repay from one of the borrower's own active savings accounts.");
        $this->savings = $borrowersAccount;
        // 100000 x 12% x 30/365 = 986.30
        $this->repay($loan, '100986.30', 'repay-0009', ['channel' => 'savings', 'savings_account_id' => $this->savings->id])->assertCreated();

        $s = $this->inCoop(fn () => $this->savings->refresh());
        $this->assertSame('0.00', $s->hold_amount);
        $this->assertSame('199013.70', $s->balance);
        $this->artisan('ledger:verify')->assertSuccessful();
    }

    public function test_idempotent_repayment(): void
    {
        $loan = $this->loan();
        $this->moveTo('2026-11-15');

        $first = $this->repay($loan, '5000.00', 'repay-0010')->assertCreated()->json('data.transaction_id');
        $this->repay($loan, '5000.00', 'repay-0010')->assertCreated()->assertJsonPath('data.transaction_id', $first);
        $this->assertSame(1, DB::table('loan_transactions')->where('type', 'repayment')->count());
    }

    public function test_classification_by_oldest_overdue_instalment(): void
    {
        $loan = $this->inCoop(fn () => $this->loan()->load('product'));
        $classify = fn (string $date) => $this->inCoop(fn () => LoanClassifier::classify($loan->refresh()->load('product'), $date));

        $this->assertSame('pass', $classify('2026-11-17'));        // 1 day overdue
        $this->assertSame('pass', $classify('2027-02-16'));        // exactly 3 months
        $this->assertSame('substandard', $classify('2027-02-17')); // more than 3 months
        $this->assertSame('doubtful', $classify('2027-05-17'));    // more than 6
        $this->assertSame('loss', $classify('2027-11-17'));        // more than 12

        $secured = $this->inCoop(fn () => $this->loan('savings', '50000.00')->load('product'));
        $this->assertSame('pass', $this->inCoop(fn () => LoanClassifier::classify($secured, '2027-11-17'))); // always pass
    }

    public function test_provisioning_posts_the_change_against_the_ledger(): void
    {
        $loan = $this->loan();
        // Pass: 1% of 120000 = 1200.00
        $this->postJson('/api/loan-provisioning')->assertOk()->assertJsonPath('data.required_provision', '1200.00')->assertJsonPath('data.change', '1200.00');
        $this->assertSame('-1200.00', $this->balanceOf('A3'));
        $this->assertSame('1200.00', $this->balanceOf('X2'));

        $this->postJson('/api/loan-provisioning')->assertOk()->assertJsonPath('data.change', '0.00'); // idempotent

        // Unpaid for more than 3 months: substandard, 25% = 30000.00; change +28800.00.
        $this->moveTo('2027-02-17');
        $this->postJson('/api/loan-provisioning')->assertOk()->assertJsonPath('data.counts.substandard', 1)
            ->assertJsonPath('data.required_provision', '30000.00')->assertJsonPath('data.change', '28800.00');
        $this->assertSame('-30000.00', $this->balanceOf('A3'));
        $this->getJson("/api/loans/{$loan->id}")->assertJsonPath('data.classification', 'substandard');
        $this->artisan('ledger:verify')->assertSuccessful();
    }
}
