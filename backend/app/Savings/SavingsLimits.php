<?php

namespace App\Savings;

use App\Accounting\PostingRejected;
use App\Accounting\PrimaryCapital;
use App\Calendar\BsCalendar;
use App\Models\Member;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Savings limits in terms of the primary capital fund (Act s.2(ङ)):
 * - one member's savings <= 10% (Act s.52(1); Std 2/2.1), with primary capital
 *   counted on Shrawan 1 and Magh 1;
 * - total savings <= 15x (Act s.50(7); Std 2/1.2).
 * PROPOSED: when the ledger has no history before the last count date (the
 * cooperative started using SahakariOS after it), the current amount is used.
 */
class SavingsLimits
{
    public const MEMBER_PERCENT = 10;

    public const TOTAL_MULTIPLE = 15;

    public function __construct(private PrimaryCapital $primaryCapital, private BsCalendar $calendar, private TenantContext $tenant) {}

    public function assertDepositAllowed(Member $member, BigDecimal $amount, string $businessDate): void
    {
        $base = $this->memberLimitBase($businessDate);
        $memberTotal = BigDecimal::of($this->memberSavings($member))->plus($amount);
        $memberLimit = $base->multipliedBy(self::MEMBER_PERCENT)->dividedBy(100, 2, RoundingMode::Down);
        if ($memberTotal->isGreaterThan($memberLimit)) {
            throw new PostingRejected("This deposit would bring the member's savings to Rs {$memberTotal}; the limit is 10% of primary capital, Rs {$memberLimit} (Act s.52(1)).");
        }

        $current = $this->primaryCapital->amount();
        $total = BigDecimal::of($this->totalSavings())->plus($amount);
        $totalLimit = $current->multipliedBy(self::TOTAL_MULTIPLE);
        if ($total->isGreaterThan($totalLimit)) {
            throw new PostingRejected("Total savings would reach Rs {$total}; the limit is 15 times primary capital, Rs {$totalLimit} (Act s.50(7)).");
        }
    }

    /** Primary capital at the last count date (Shrawan 1 or Magh 1), or now if there is no earlier history. */
    public function memberLimitBase(string $businessDate): BigDecimal
    {
        $count = $this->lastCountDate($businessDate);
        $before = date('Y-m-d', strtotime("{$count} -1 day"));
        $hasHistory = DB::table('journal_entries')->where('cooperative_id', $this->tenant->cooperativeId())->where('entry_date', '<=', $before)->exists();

        return $hasHistory ? $this->primaryCapital->amount($before) : $this->primaryCapital->amount();
    }

    /** AD date of the latest Shrawan 1 or Magh 1 on or before the business date. */
    public function lastCountDate(string $businessDate): string
    {
        $bs = $this->calendar->toBs($businessDate);
        [$year, $month] = match (true) {
            $bs->month >= 10 => [$bs->year, 10],     // Magh..Chaitra: Magh 1 this year
            $bs->month >= 4 => [$bs->year, 4],       // Shrawan..Poush: Shrawan 1 this year
            default => [$bs->year - 1, 10],          // Baisakh..Asar: Magh 1 of the previous BS year
        };

        return $this->calendar->toAd($year, $month, 1)->format('Y-m-d');
    }

    private function memberSavings(Member $member): string
    {
        return (string) DB::table('savings_accounts')->where('member_id', $member->id)->where('status', '<>', 'closed')->sum('balance');
    }

    private function totalSavings(): string
    {
        return (string) DB::table('savings_accounts')->where('cooperative_id', $this->tenant->cooperativeId())->sum('balance');
    }
}
