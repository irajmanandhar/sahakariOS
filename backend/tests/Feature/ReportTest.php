<?php

namespace Tests\Feature;

use App\Accounting\LedgerMappings;
use App\Accounting\PostingService;
use App\Loans\LoanService;
use App\Savings\SavingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCooperative;
use Tests\TestCase;

/** Reports must tie to hand-computed figures and to the ledger. Synthetic data only. */
class ReportTest extends TestCase
{
    use BuildsCooperative, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCooperative();
        $this->signIn();
        $this->inCoop(function () {
            $post = fn (string $d, array $lines) => app(PostingService::class)->post($d, $lines);
            // Share capital 10 lakh in cash; bank deposit 2 lakh moved from cash.
            $post('Shares (test)', [['account_code' => 'A1', 'debit' => '1000000'], ['account_code' => 'E1', 'credit' => '1000000']]);
            $post('Cash to bank (test)', [['account_code' => 'A4', 'debit' => '200000'], ['account_code' => 'A1', 'credit' => '200000']]);
            $savings = app(SavingsService::class);
            $regular = $savings->createProduct(['code' => 'R', 'name' => 'Regular', 'type' => 'regular', 'interest_rate' => '6', 'interest_posting' => 'yearly', 'ledger_account_code' => 'L1']);
            $general = $savings->createProduct(['code' => 'G', 'name' => 'General', 'type' => 'general', 'interest_rate' => '4', 'interest_posting' => 'yearly', 'ledger_account_code' => 'L1']);
            $m = $this->activeMember();
            $savings->deposit($savings->open($m, $regular), '30000.00', $this->user, 'r1');
            $savings->deposit($savings->open($m, $general), '70000.00', $this->user, 'g1');
            app(LedgerMappings::class)->set('cash', 'A1');
            $loans = app(LoanService::class);
            $product = $loans->createProduct(['code' => 'L', 'name' => 'Property', 'collateral_type' => 'real_estate', 'interest_rate' => '11', 'repayment_frequency' => 'monthly',
                'schedule_method' => 'equal_principal', 'max_term_months' => 24, 'max_amount' => '1000000', 'max_ltv_percent' => '60', 'ledger_account_code' => 'A2', 'is_productive_sector' => true]);
            $loan = $loans->apply($this->activeMember(), $product, ['principal' => '100000.00', 'term_months' => 12, 'purpose' => 'x', 'collateral_value' => '500000'], $this->user);
            $approver = $this->secondUser();
            $loans->approve($loan, $approver);
            $borrowerSavings = $savings->open($loan->member, $general);
            $loans->disburse($loan, $borrowerSavings, $approver, 'd1');
        });
        $this->putJson('/api/ledger-account-tags', ['account_code' => 'A4', 'tags' => ['bank_deposit']])->assertOk();
    }

    public function test_balance_sheet_balances(): void
    {
        $r = $this->getJson('/api/reports/statements/balance_sheet')->assertOk()->json('data');

        $this->assertTrue($r['checks'][0]['ok']);
        // Assets: cash 1000000 - 200000 + 30000 + 70000 = 900000; bank 200000; loan 100000.
        $this->assertSame('1200000.00', $r['sections'][0]['total']);
        // Liabilities: savings 30000 + 70000 + 100000 (loan paid into the borrower's savings).
        $this->assertSame('200000.00', $r['sections'][1]['total']);
        $this->assertSame('1000000.00', $r['sections'][2]['total']);
    }

    public function test_interest_spread(): void
    {
        // Savings: (30000 x 6 + 70000 x 4 + 100000 x 4) / 200000 = 4.3%; loans 11% -> spread 6.7% > 6%: flagged.
        $r = $this->getJson('/api/reports/statements/interest_spread')->assertOk()->json('data');

        $this->assertSame('4.3000%', $r['sections'][0]['rows'][0]['amount']);
        $this->assertSame('6.7000%', $r['sections'][0]['rows'][2]['amount']);
        $this->assertSame('11.0000%', $r['sections'][0]['rows'][1]['amount']);
        $this->assertFalse($r['checks'][0]['ok']);
    }

    public function test_liquidity_uses_cash_and_tagged_bank_deposits(): void
    {
        $r = $this->getJson('/api/reports/statements/liquidity?as_of=2026-10-16')->assertOk()->json('data');

        // BS month Asoj 2083: weekly dates 7/14/21/28 Asoj = 2026-09-23 / 30 / 10-07 / 10-14, all before the postings (2026-10-16).
        $this->assertSame('2083-06', $r['bs_month']);
        $this->assertSame('0.00', $r['sections'][1]['rows'][0]['amount']);
        $this->assertTrue($r['checks'][0]['ok']); // no savings yet in those weeks
    }

    public function test_capital_adequacy_and_loan_classification(): void
    {
        $cap = $this->getJson('/api/reports/statements/capital_adequacy')->assertOk()->json('data');
        // Core capital = share capital 10 lakh (no profit yet: the loan product has no fee).
        $this->assertSame('1000000.00', collect($cap['sections'][0]['rows'])->firstWhere('label', 'Core capital')['amount']);
        // RWA = 20% of bank 200000 (40000) + 100% of the loan 100000 (cash is 0%) = 140000.00
        $this->assertSame('140000.00', collect($cap['sections'][1]['rows'])->firstWhere('label', 'Risk-weighted assets')['amount']);
        $this->assertTrue($cap['checks'][0]['ok']);

        $cls = $this->getJson('/api/reports/statements/loan_classification')->assertOk()->json('data');
        $this->assertSame('100000.00', $cls['sections'][0]['total']);
        $this->assertSame('1000.00', $cls['sections'][1]['total']); // 1% pass
        $this->assertFalse($cls['checks'][0]['ok']);                 // no provision posted yet
        $this->postJson('/api/loan-provisioning')->assertOk();
        $this->assertTrue($this->getJson('/api/reports/statements/loan_classification')->json('data.checks.0.ok'));
    }

    public function test_portfolio_ratios_and_profit_and_loss(): void
    {
        $r = $this->getJson('/api/reports/statements/portfolio_ratios')->assertOk()->json('data');
        $this->assertSame('15.00%', $r['checks'][0]['value']); // regular 30000 of 200000 (< 25%)
        $this->assertFalse($r['checks'][0]['ok']);

        $pl = $this->getJson('/api/reports/statements/profit_and_loss')->assertOk()->json('data');
        $this->assertSame('2026-07-17', $pl['from']); // fiscal year 2083/84 start
        $this->assertSame('0.00', $pl['sections'][2]['rows'][0]['amount']);
    }

    public function test_tag_types_are_checked_and_reports_need_permission(): void
    {
        $this->putJson('/api/ledger-account-tags', ['account_code' => 'E1', 'tags' => ['bank_deposit']])->assertUnprocessable()
            ->assertJsonPath('message', 'Tag bank_deposit needs an account of type asset.');
        $this->getJson('/api/reports/statements/unknown')->assertNotFound();
    }
}
