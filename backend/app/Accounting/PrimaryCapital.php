<?php

namespace App\Accounting;

use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Primary capital fund (प्राथमिक पूँजी कोष) = share capital + reserve fund
 * (Cooperatives Act s.2(ङ)), read from the general ledger.
 * Register S-04 CONFLICT: the NCRA standard's core capital also adds the loss
 * compensation fund and accumulated profit/loss; the Act's definition is used.
 */
class PrimaryCapital
{
    public function __construct(private TenantContext $tenant, private LedgerMappings $mappings) {}

    /** Credit balance of share capital + reserve fund, optionally as of a date. */
    public function amount(?string $asOf = null): BigDecimal
    {
        $ids = [$this->mappings->account('share_capital')->id];
        $reserve = DB::table('ledger_mappings')->where('cooperative_id', $this->tenant->cooperativeId())->where('key', 'reserve_fund')->value('ledger_account_id');
        if ($reserve) {
            $ids[] = $reserve;
        }

        $row = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.cooperative_id', $this->tenant->cooperativeId())
            ->whereIn('l.ledger_account_id', $ids)
            ->when($asOf, fn ($q) => $q->where('e.entry_date', '<=', $asOf))
            ->selectRaw('COALESCE(SUM(l.credit), 0) - COALESCE(SUM(l.debit), 0) AS balance')
            ->first();

        return BigDecimal::of($row->balance)->toScale(2);
    }
}
