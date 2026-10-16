<?php

namespace App\Accounting;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Independent re-check of ledger invariants straight from the tables, for all cooperatives. */
class VerifyLedger extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Check every journal entry is complete and balanced, and reversals mirror their originals';

    public function handle(): int
    {
        $problems = [];

        foreach (DB::select('SELECT e.id, e.cooperative_id, e.line_count, e.total, COUNT(l.id) n, COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c
                FROM journal_entries e LEFT JOIN journal_lines l ON l.journal_entry_id = e.id
                GROUP BY e.id, e.cooperative_id, e.line_count, e.total
                HAVING n < 2 OR n <> e.line_count OR d <> c OR d <> e.total') as $row) {
            $problems[] = "entry {$row->id} (cooperative {$row->cooperative_id}): {$row->n}/{$row->line_count} lines, debits {$row->d}, credits {$row->c}, total {$row->total}";
        }

        foreach (DB::select('SELECT r.id, r.reversal_of_id FROM journal_entries r
                JOIN journal_lines rl ON rl.journal_entry_id = r.id
                LEFT JOIN journal_lines ol ON ol.journal_entry_id = r.reversal_of_id AND ol.line_number = rl.line_number
                    AND ol.ledger_account_id = rl.ledger_account_id AND ol.debit = rl.credit AND ol.credit = rl.debit
                WHERE r.reversal_of_id IS NOT NULL AND ol.id IS NULL GROUP BY r.id, r.reversal_of_id') as $row) {
            $problems[] = "reversal {$row->id} does not mirror entry {$row->reversal_of_id}";
        }

        foreach (DB::select('SELECT l.id FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id
                WHERE l.cooperative_id <> e.cooperative_id') as $row) {
            $problems[] = "line {$row->id} belongs to a different cooperative than its entry";
        }

        foreach ($problems as $p) {
            $this->error($p);
        }
        $count = DB::table('journal_entries')->count();
        if ($problems === []) {
            $this->info("Ledger OK ({$count} entries).");

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
