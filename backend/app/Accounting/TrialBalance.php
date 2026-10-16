<?php

namespace App\Accounting;

use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Trial balance of the current cooperative, computed directly from journal lines. */
class TrialBalance
{
    public function __construct(private TenantContext $tenant) {}

    /** @return array{as_of: ?string, accounts: list<array>, total_debit: string, total_credit: string, balanced: bool} */
    public function compute(?string $asOf = null): array
    {
        // ponytail: sums every line on each call; add per-day balance snapshots when volume needs it.
        $rows = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('l.cooperative_id', $this->tenant->cooperativeId())
            ->when($asOf, fn ($q) => $q->where('e.entry_date', '<=', $asOf))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->selectRaw('a.id, a.code, a.name, a.type, SUM(l.debit) AS debits, SUM(l.credit) AS credits')
            ->get();

        $totalDebit = $totalCredit = Money::zero();
        $accounts = [];

        foreach ($rows as $row) {
            $net = BigDecimal::of($row->debits)->minus($row->credits);
            $debitBalance = $net->isPositive() ? $net : Money::zero();
            $creditBalance = $net->isNegative() ? $net->negated() : Money::zero();
            $totalDebit = $totalDebit->plus($debitBalance);
            $totalCredit = $totalCredit->plus($creditBalance);

            $accounts[] = [
                'account_id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'debit_balance' => Money::format($debitBalance),
                'credit_balance' => Money::format($creditBalance),
            ];
        }

        return [
            'as_of' => $asOf,
            'accounts' => $accounts,
            'total_debit' => Money::format($totalDebit),
            'total_credit' => Money::format($totalCredit),
            'balanced' => $totalDebit->isEqualTo($totalCredit),
        ];
    }
}
