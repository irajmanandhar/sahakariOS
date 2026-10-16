<?php

namespace App\Audit;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Recomputes every hash chain and reports the first broken link per chain. */
class VerifyAuditLog extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the audit log hash chains (detects edited, deleted or inserted rows)';

    public function handle(): int
    {
        $broken = 0;

        if (DB::table('audit_chains')->doesntExist()) {
            $this->info('No audit entries yet.');

            return self::SUCCESS;
        }

        foreach (DB::table('audit_chains')->orderBy('chain')->get() as $chain) {
            $expectedPrev = AuditLogger::GENESIS_HASH;
            $expectedSeq = 1;
            $problem = null;

            foreach (DB::table('audit_logs')->where('chain', $chain->chain)->orderBy('sequence')->cursor() as $row) {
                $entry = (array) $row;
                $entry['occurred_at'] = substr($entry['occurred_at'], 0, 26);
                if ((int) $row->sequence !== $expectedSeq) {
                    $problem = "missing entry before sequence {$row->sequence}";
                } elseif ($row->prev_hash !== $expectedPrev) {
                    $problem = "sequence {$row->sequence} does not link to the previous entry";
                } elseif (! hash_equals($row->hash, AuditLogger::hash($entry))) {
                    $problem = "sequence {$row->sequence} was modified";
                }
                if ($problem) {
                    break;
                }
                $expectedPrev = $row->hash;
                $expectedSeq++;
            }

            if (! $problem && ($expectedSeq - 1 !== (int) $chain->last_sequence || $expectedPrev !== $chain->last_hash)) {
                $problem = 'entries missing at the end of the chain';
            }

            if ($problem) {
                $broken++;
                $this->error("{$chain->chain}: BROKEN — {$problem}");
            } else {
                $this->info("{$chain->chain}: OK (".($expectedSeq - 1).' entries)');
            }
        }

        return $broken === 0 ? self::SUCCESS : self::FAILURE;
    }
}
