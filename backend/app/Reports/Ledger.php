<?php

namespace App\Reports;

use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only balances straight from journal lines (the authoritative record). */
class Ledger
{
    /** Tags the reports understand: [label, account type]. */
    public const TAGS = [
        'bank_deposit' => ['Deposit accounts in NRB-licensed banks and financial institutions', 'asset'],
        'cooperative_bank_deposit' => ['Deposits in a licensed cooperative bank', 'asset'],
        'government_bond' => ['Government bonds', 'asset'],
        'fixed_deposit' => ['Fixed deposits in BFIs / cooperative bank (90% counts, capped at 5% of savings)', 'asset'],
        'other_union_deposit' => ['Amounts in other unions or cooperatives', 'asset'],
        'loss_compensation_fund' => ['Loss compensation fund (core capital)', 'equity'],
        'asset_revaluation_reserve' => ['Asset revaluation reserve (supplementary capital)', 'equity'],
        'free_reserve' => ['Other unused / free reserve (supplementary capital)', 'equity'],
        'fixed_asset' => ['Fixed assets', 'asset'],
    ];

    public function __construct(private TenantContext $tenant) {}

    /** Per-account debit and credit totals; optional date range on entry_date. */
    public function accounts(?string $asOf = null, ?string $from = null): Collection
    {
        return DB::table('ledger_accounts as a')
            ->leftJoin('journal_lines as l', 'l.ledger_account_id', '=', 'a.id')
            ->leftJoin('journal_entries as e', fn ($j) => $j->on('e.id', '=', 'l.journal_entry_id'))
            ->where('a.cooperative_id', $this->tenant->cooperativeId())
            ->where('a.is_postable', true)
            ->when($asOf, fn ($q) => $q->where(fn ($w) => $w->whereNull('e.id')->orWhere('e.entry_date', '<=', $asOf)))
            ->when($from, fn ($q) => $q->where(fn ($w) => $w->whereNull('e.id')->orWhere('e.entry_date', '>=', $from)))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->selectRaw('a.id, a.code, a.name, a.type, COALESCE(SUM(l.debit), 0) AS debits, COALESCE(SUM(l.credit), 0) AS credits')
            ->get()
            ->map(function ($r) {
                $net = BigDecimal::of($r->debits)->minus($r->credits);
                $r->balance = in_array($r->type, ['asset', 'expense'], true) ? $net : $net->negated(); // natural side positive

                return $r;
            });
    }

    /** Natural-side balance of all accounts with a tag, at a date. */
    public function tagged(string $tag, ?string $asOf = null): BigDecimal
    {
        $ids = DB::table('ledger_account_tags')->where('cooperative_id', $this->tenant->cooperativeId())->where('tag', $tag)->pluck('ledger_account_id')->all();

        return $this->sum($this->accounts($asOf)->whereIn('id', $ids));
    }

    /** Natural-side balance of one mapped role (zero if not mapped). */
    public function role(string $role, ?string $asOf = null): BigDecimal
    {
        $id = DB::table('ledger_mappings')->where('cooperative_id', $this->tenant->cooperativeId())->where('key', $role)->value('ledger_account_id');

        return $id ? $this->sum($this->accounts($asOf)->where('id', $id)) : BigDecimal::zero()->toScale(2);
    }

    public function sum(Collection $rows): BigDecimal
    {
        return $rows->reduce(fn (BigDecimal $c, $r) => $c->plus($r->balance), BigDecimal::zero()->toScale(2));
    }
}
