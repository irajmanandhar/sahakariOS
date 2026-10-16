<?php

namespace App\Compliance;

use App\Accounting\Money;
use App\Models\JournalEntry;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Cash received from / paid to members, per business date. Feeds the
 * threshold transaction report (TTR): AML Instruction 12 — cash deposits or
 * withdrawals by one member of Rs 10 lakh or more in one day, reported to
 * FIU-Nepal within 15 days. Exemptions (Instruction 12(2)) do not apply to
 * member transactions.
 */
class CashMovements
{
    public const TTR_THRESHOLD = '1000000.00';

    public const TTR_DEADLINE_DAYS = 15;

    /** AML Instruction 8 / Act s.52क: declare source of funds at Rs 10 lakh or more (stricter reading). */
    public const SOURCE_OF_FUNDS_THRESHOLD = '1000000.00';

    public function record(Member $member, JournalEntry $entry, string $direction, string $amount, string $sourceType, int $sourceId): void
    {
        DB::table('member_cash_movements')->insert([
            'cooperative_id' => $member->cooperative_id,
            'member_id' => $member->id,
            'business_date' => $entry->entry_date->format('Y-m-d'),
            'direction' => $direction,
            'amount' => $amount,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'journal_entry_id' => $entry->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function requiresSourceOfFunds(string $amount): bool
    {
        return Money::parse($amount)->isGreaterThanOrEqualTo(self::SOURCE_OF_FUNDS_THRESHOLD);
    }

    /** Members whose total cash in (or out) on one business date reached the threshold. */
    public function thresholdTransactions(int $cooperativeId, string $from, string $to): array
    {
        return DB::table('member_cash_movements as c')
            ->join('members as m', 'm.id', '=', 'c.member_id')
            ->where('c.cooperative_id', $cooperativeId)
            ->whereBetween('c.business_date', [$from, $to])
            ->groupBy('c.business_date', 'c.member_id', 'm.member_number', 'm.full_name', 'c.direction')
            ->havingRaw('SUM(c.amount) >= ?', [self::TTR_THRESHOLD])
            ->orderBy('c.business_date')->orderBy('m.member_number')
            ->selectRaw('c.business_date, c.member_id, m.member_number, m.full_name, c.direction, SUM(c.amount) AS total, COUNT(*) AS transactions')
            ->get()
            ->map(fn ($r) => [
                'business_date' => $r->business_date,
                'report_by' => date('Y-m-d', strtotime($r->business_date.' +'.self::TTR_DEADLINE_DAYS.' days')),
                'member_id' => $r->member_id,
                'member_number' => $r->member_number,
                'full_name' => $r->full_name,
                'direction' => $r->direction === 'in' ? 'cash_deposit' : 'cash_withdrawal',
                'total' => Money::format($r->total),
                'transactions' => (int) $r->transactions,
            ])->all();
    }
}
