<?php

namespace App\Shares;

use App\Accounting\LedgerMappings;
use App\Accounting\Money;
use App\Accounting\PostingRejected;
use App\Accounting\PostingService;
use App\Compliance\CashMovements;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Membership shares (Cooperatives Act s.63). PROPOSED posting:
 * purchase in cash = Dr cash / Cr share capital.
 */
class ShareService
{
    /** Act s.63(4): face value of one share. */
    public const FACE_VALUE = '100.00';

    /** Act s.63(2): one member may hold at most 20% of total share capital. */
    public const MAX_HOLDING_PERCENT = 20;

    public function __construct(private PostingService $posting, private LedgerMappings $mappings, private CashMovements $cash) {}

    public function purchase(Member $member, int $units, ?User $by, string $idempotencyKey, ?string $sourceOfFunds = null): array
    {
        if ($units < 1) {
            throw new PostingRejected('Buy at least one share.');
        }
        $amount = BigDecimal::of(self::FACE_VALUE)->multipliedBy($units)->toScale(2);
        if (CashMovements::requiresSourceOfFunds((string) $amount) && trim((string) $sourceOfFunds) === '') {
            throw new PostingRejected('Source of funds is required for Rs 10 lakh or more (AML Instruction 8).');
        }

        return DB::transaction(function () use ($member, $units, $by, $idempotencyKey, $sourceOfFunds, $amount) {
            // A retry returns the original sale before any limit is re-checked.
            if ($existing = JournalEntry::query()->where('idempotency_key', "share:{$idempotencyKey}")->first()) {
                $row = DB::table('share_transactions')->where('journal_entry_id', $existing->id)->first();
                if ($row === null || (int) $row->member_id !== $member->id || (int) $row->units !== $units) {
                    throw new PostingRejected('This Idempotency-Key was already used for a different transaction.');
                }

                return $this->present($row, $member);
            }
            $member = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            if ($member->status !== 'active') {
                throw new PostingRejected('Shares can be sold to active members only (Act s.63(1)).');
            }

            // Serialise share sales per cooperative so the 20% check sees every sale.
            $total = (int) DB::table('share_transactions')->where('cooperative_id', $member->cooperative_id)->lockForUpdate()
                ->selectRaw("COALESCE(SUM(CASE WHEN type = 'purchase' THEN units ELSE -units END), 0) AS u")->value('u');
            $held = $this->units($member);
            if (($held + $units) * 100 > ($total + $units) * self::MAX_HOLDING_PERCENT) {
                throw new PostingRejected(sprintf(
                    'After this purchase the member would hold %s%% of all shares; the limit is %d%% (Act s.63(2)).',
                    number_format(($held + $units) * 100 / ($total + $units), 2), self::MAX_HOLDING_PERCENT,
                ));
            }

            $entry = $this->posting->post(
                "Share purchase: member {$member->member_number}, {$units} share(s)",
                [
                    ['ledger_account_id' => $this->mappings->account('cash')->id, 'debit' => (string) $amount],
                    ['ledger_account_id' => $this->mappings->account('share_capital')->id, 'credit' => (string) $amount],
                ],
                $by, "share:{$idempotencyKey}", ['type' => 'share_purchase', 'id' => $member->id],
            );

            if (! $entry->wasRecentlyCreated) { // idempotent replay
                return $this->present(DB::table('share_transactions')->where('journal_entry_id', $entry->id)->first(), $member);
            }

            $id = DB::table('share_transactions')->insertGetId([
                'cooperative_id' => $member->cooperative_id, 'member_id' => $member->id, 'type' => 'purchase',
                'units' => $units, 'amount' => (string) $amount, 'journal_entry_id' => $entry->id,
                'source_of_funds' => $sourceOfFunds, 'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->cash->record($member, $entry, 'in', (string) $amount, 'share_purchase', $id);

            return $this->present(DB::table('share_transactions')->find($id), $member);
        });
    }

    public function units(Member $member): int
    {
        return (int) DB::table('share_transactions')->where('member_id', $member->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'purchase' THEN units ELSE -units END), 0) AS u")->value('u');
    }

    private function present(object $row, Member $member): array
    {
        return [
            'id' => $row->id, 'member_id' => $member->id, 'type' => $row->type, 'units' => (int) $row->units,
            'amount' => Money::format($row->amount), 'journal_entry_id' => $row->journal_entry_id,
            'member_total_units' => $this->units($member),
        ];
    }
}
