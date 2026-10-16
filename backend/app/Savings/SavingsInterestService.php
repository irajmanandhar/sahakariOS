<?php

namespace App\Savings;

use App\Accounting\LedgerMappings;
use App\Accounting\PostingService;
use App\Calendar\BsCalendar;
use App\Models\SavingsAccount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Savings interest, run at day close for the business date being closed.
 *
 * PROPOSED treatments (need the accountant's approval):
 * - daily accrual: balance x annual rate / 365 for each calendar day, at 6
 *   decimals; days without a business day use the balance carried into the
 *   next business day; the closing business date uses its closing balance;
 * - interest is expensed when posted to the account (not accrued in the GL):
 *   Dr savings interest expense / Cr product savings account;
 * - posting dates (BS calendar): monthly = month end; quarterly = end of Asoj,
 *   Poush, Chaitra, Asar; half_yearly = Poush, Asar; yearly = Asar (fiscal
 *   year end); at_maturity = maturity date;
 * - rounding half-up to paisa when posted; the remainder carries forward;
 * - no tax deducted at source (needs tax advice).
 */
class SavingsInterestService
{
    public const DAYS_IN_YEAR = 365;

    private const BOUNDARY_MONTHS = [
        'monthly' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
        'quarterly' => [3, 6, 9, 12],
        'half_yearly' => [3, 9],
        'yearly' => [3],
    ];

    public function __construct(private BsCalendar $calendar, private PostingService $posting, private LedgerMappings $mappings) {}

    /** Accrue (and where due, post) interest for every active account up to $closingDate. Run inside the day-close transaction. */
    public function runForDate(string $closingDate): int
    {
        $posted = 0;
        // ponytail: loops every account in one transaction; batch or queue it when a cooperative has many thousands of accounts.
        SavingsAccount::query()->where('status', 'active')->with('product')->lockForUpdate()->orderBy('id')
            ->each(function (SavingsAccount $account) use ($closingDate, &$posted) {
                $posted += $this->runForAccount($account, $closingDate);
            });

        return $posted;
    }

    private function runForAccount(SavingsAccount $account, string $closingDate): int
    {
        $start = $account->interest_accrued_through
            ? CarbonImmutable::parse($account->interest_accrued_through)->addDay()
            : CarbonImmutable::parse($account->opened_on);
        $end = CarbonImmutable::parse($closingDate);
        if ($start->greaterThan($end)) {
            return 0;
        }

        $rate = BigDecimal::of($account->product->interest_rate);
        $closing = BigDecimal::of($account->balance);
        $opening = $closing->minus($this->netMovementsOn($account, $closingDate)); // balance carried into the closing day
        $accrued = BigDecimal::of($account->accrued_interest);
        $postings = 0;

        $segmentStart = $start;
        $segmentBalance = null;
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $balance = $day->equalTo($end) ? $closing : $opening;
            $boundary = $this->isPostingDate($account, $day->format('Y-m-d'));
            $lastDay = $day->equalTo($end);
            $nextBalance = $day->addDay()->equalTo($end) ? $closing : $opening;

            if ($boundary || $lastDay || ! $nextBalance->isEqualTo($balance)) {
                $accrued = $accrued->plus($this->accrue($account, $segmentStart, $day, $balance, $rate));
                $segmentStart = $day->addDay();
                if ($boundary) {
                    [$accrued, $done] = $this->post($account, $accrued, $day->format('Y-m-d'));
                    $postings += $done;
                    if ($done) {
                        $closing = BigDecimal::of($account->balance);
                        $opening = $day->lessThan($end) ? $opening->plus($closing->minus($balance)) : $opening;
                    }
                }
            }
        }

        $account->forceFill(['accrued_interest' => (string) $accrued, 'interest_accrued_through' => $closingDate])->save();

        return $postings;
    }

    private function accrue(SavingsAccount $account, CarbonImmutable $from, CarbonImmutable $to, BigDecimal $balance, BigDecimal $rate): BigDecimal
    {
        $days = (int) $from->diffInDays($to) + 1;
        $amount = $balance->multipliedBy($rate)->multipliedBy($days)->dividedBy(100 * self::DAYS_IN_YEAR, 6, RoundingMode::HalfUp);

        DB::table('savings_interest_accruals')->insert([
            'cooperative_id' => $account->cooperative_id, 'savings_account_id' => $account->id,
            'from_date' => $from->format('Y-m-d'), 'to_date' => $to->format('Y-m-d'), 'days' => $days,
            'balance' => (string) $balance, 'rate' => (string) $rate, 'amount' => (string) $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $amount;
    }

    /** @return array{0: BigDecimal, 1: int} remaining accrued interest, postings made */
    private function post(SavingsAccount $account, BigDecimal $accrued, string $periodEnd): array
    {
        $amount = $accrued->toScale(2, RoundingMode::HalfUp);
        if (! $amount->isPositive()) {
            return [$accrued, 0];
        }

        $entry = $this->posting->post(
            "Savings interest to {$periodEnd}: account {$account->account_number}",
            [
                ['ledger_account_id' => $this->mappings->account('savings_interest_expense')->id, 'debit' => (string) $amount],
                ['ledger_account_id' => $account->product->ledger_account_id, 'credit' => (string) $amount],
            ],
            null, "savings-interest:{$account->id}:{$periodEnd}", ['type' => 'savings_interest', 'id' => $account->id],
        );
        if (! $entry->wasRecentlyCreated) {
            return [$accrued->minus($amount), 0]; // already posted by an earlier attempt
        }

        $balance = BigDecimal::of($account->balance)->plus($amount);
        $account->forceFill(['balance' => (string) $balance])->save();
        DB::table('savings_transactions')->insert([
            'cooperative_id' => $account->cooperative_id, 'savings_account_id' => $account->id,
            'type' => 'interest', 'channel' => 'system', 'amount' => (string) $amount, 'balance_after' => (string) $balance,
            'journal_entry_id' => $entry->id, 'narration' => "Interest to {$periodEnd}",
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$accrued->minus($amount), 1];
    }

    public function isPostingDate(SavingsAccount $account, string $date): bool
    {
        $posting = $account->product->interest_posting;
        if ($posting === 'at_maturity') {
            return $account->maturity_date !== null && $account->maturity_date->format('Y-m-d') === $date;
        }
        $bs = $this->calendar->toBs($date);

        return in_array($bs->month, self::BOUNDARY_MONTHS[$posting], true)
            && $bs->day === $this->calendar->daysInMonth($bs->year, $bs->month);
    }

    /** Net cash/transfer movement (excluding interest) posted on the business date. */
    private function netMovementsOn(SavingsAccount $account, string $date): BigDecimal
    {
        $row = DB::table('savings_transactions as t')->join('journal_entries as e', 'e.id', '=', 't.journal_entry_id')
            ->where('t.savings_account_id', $account->id)->where('e.entry_date', $date)->where('t.type', '<>', 'interest')
            ->selectRaw("COALESCE(SUM(CASE WHEN t.type IN ('deposit', 'transfer_in') THEN t.amount ELSE -t.amount END), 0) AS net")
            ->first();

        return BigDecimal::of($row->net);
    }
}
