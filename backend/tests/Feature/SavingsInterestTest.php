<?php

namespace Tests\Feature;

use App\Accounting\PostingService;
use App\Calendar\BusinessDayService;
use App\Models\SavingsAccount;
use App\Savings\SavingsInterestService;
use App\Savings\SavingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCooperative;
use Tests\TestCase;

/**
 * Interest arithmetic: Rs 1,00,000 at 6% = 100000 x 6 / 36500 = 16.438356 per day.
 * 2026-10-16 = 2083 Asoj 30; Asoj 2083 has 31 days, so 2026-10-17 (Asoj 31) is a quarter end.
 */
class SavingsInterestTest extends TestCase
{
    use BuildsCooperative, RefreshDatabase;

    private SavingsAccount $account;

    private int $products = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCooperative();
        $this->account = $this->openAccount('quarterly');
    }

    private function openAccount(string $posting, string $deposit = '100000.00'): SavingsAccount
    {
        $n = ++$this->products;

        return $this->inCoop(function () use ($posting, $deposit, $n) {
            app(PostingService::class)->post('Share capital (test opening)', [['account_code' => 'A1', 'debit' => '10000000'], ['account_code' => 'E1', 'credit' => '10000000']]);
            $product = app(SavingsService::class)->createProduct(['code' => "P{$n}", 'name' => 'Test', 'type' => 'general', 'interest_rate' => '6.000000', 'interest_posting' => $posting, 'ledger_account_code' => 'L1']);
            $account = app(SavingsService::class)->open($this->activeMember(), $product);
            app(SavingsService::class)->deposit($account, $deposit, $this->user, "seed-{$n}");

            return $account->refresh();
        });
    }

    private function closeAndOpen(string $nextDay, string $now): void
    {
        $this->inCoop(fn () => app(BusinessDayService::class)->close($this->user));
        Date::setTestNow($now);
        $this->inCoop(fn () => app(BusinessDayService::class)->open($nextDay));
    }

    private function fresh(): SavingsAccount
    {
        return $this->inCoop(fn () => $this->account->refresh());
    }

    public function test_daily_accrual_at_close(): void
    {
        $this->inCoop(fn () => app(BusinessDayService::class)->close($this->user));

        $a = $this->fresh();
        $this->assertSame('16.438356', $a->accrued_interest);
        $this->assertSame('2026-10-16', $a->interest_accrued_through);
        $this->assertSame('100000.00', $a->balance); // not yet posted
    }

    public function test_interest_is_posted_at_quarter_end_with_remainder_carried(): void
    {
        $this->closeAndOpen('2026-10-17', '2026-10-17 04:15:00');
        $this->inCoop(fn () => app(BusinessDayService::class)->close($this->user)); // Asoj 31: quarter end

        $a = $this->fresh();
        // 2 days x 16.438356 = 32.876712 -> posted 32.88, remainder -0.003288 carried forward.
        $this->assertSame('100032.88', $a->balance);
        $this->assertSame('-0.003288', $a->accrued_interest);
        $this->assertSame('32.88', DB::table('savings_transactions')->where('type', 'interest')->value('amount'));
        $this->assertSame('32.88', $this->balanceOf('X1'));
        $this->artisan('ledger:verify')->assertSuccessful();
    }

    public function test_days_without_business_day_accrue_at_carried_balance(): void
    {
        // Close Asoj 30, skip to Kartik 3 (2026-10-20): 17,18,19 carried + the 20th = 4 days, crossing the quarter end.
        $this->closeAndOpen('2026-10-20', '2026-10-20 04:15:00');
        $this->inCoop(fn () => app(BusinessDayService::class)->close($this->user));

        $rows = DB::table('savings_interest_accruals')->where('savings_account_id', $this->account->id)->orderBy('id')->get(['from_date', 'to_date', 'days', 'amount']);
        $this->assertSame([['2026-10-16', '2026-10-16', 1], ['2026-10-17', '2026-10-17', 1], ['2026-10-18', '2026-10-20', 3]],
            $rows->map(fn ($r) => [$r->from_date, $r->to_date, (int) $r->days])->all());
        // The quarter end (2026-10-17) fell inside the gap and was still posted: 32.88.
        $this->assertSame('32.88', DB::table('savings_transactions')->where('type', 'interest')->value('amount'));
    }

    public function test_balance_change_on_the_closing_day_uses_closing_balance(): void
    {
        // The 17th (quarter end) has no business day: it accrues at the carried balance and its
        // interest is posted at this close; the 18th then accrues on its closing balance.
        $this->closeAndOpen('2026-10-18', '2026-10-18 04:15:00');
        $this->inCoop(fn () => app(SavingsService::class)->deposit($this->account->refresh(), '100000.00', $this->user, 'dep-day18'));
        $this->inCoop(fn () => app(BusinessDayService::class)->close($this->user));

        $last = DB::table('savings_interest_accruals')->where('savings_account_id', $this->account->id)->orderByDesc('id')->first();
        $this->assertSame('2026-10-18', $last->to_date);
        $this->assertSame('200032.88', $last->balance); // closing balance of the 18th incl. posted interest
    }

    public function test_retrying_close_does_not_double_post(): void
    {
        $this->closeAndOpen('2026-10-17', '2026-10-17 04:15:00');
        $svc = $this->inCoop(fn () => app(SavingsInterestService::class));
        $this->inCoop(fn () => $svc->runForDate('2026-10-17'));
        $this->inCoop(fn () => $svc->runForDate('2026-10-17')); // second run: nothing new

        $this->assertSame(1, DB::table('savings_transactions')->where('type', 'interest')->count());
        $this->assertSame('100032.88', $this->fresh()->balance);
    }

    public function test_monthly_posting(): void
    {
        $monthly = $this->openAccount('monthly');
        $this->closeAndOpen('2026-10-17', '2026-10-17 04:15:00');
        $this->inCoop(fn () => app(BusinessDayService::class)->close($this->user)); // Asoj 31 is also a month end

        $this->assertSame('100032.88', $this->inCoop(fn () => $monthly->refresh())->balance);
    }
}
