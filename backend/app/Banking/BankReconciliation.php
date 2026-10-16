<?php

namespace App\Banking;

use App\Accounting\Money;
use App\Accounting\PostingRejected;
use App\Audit\AuditLogger;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bank reconciliation for ledger accounts tagged "bank_deposit" or
 * "cooperative_bank_deposit". Statement lines are matched one-to-one to
 * journal lines on that account with the same signed amount, within
 * MATCH_DAYS days (same day preferred). PROPOSED matching rule.
 */
class BankReconciliation
{
    public const MATCH_DAYS = 3;

    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    public function bankAccount(string $code): LedgerAccount
    {
        $account = LedgerAccount::query()->where('code', $code)->first() ?? throw new PostingRejected("Account {$code} does not exist.");
        $tagged = DB::table('ledger_account_tags')->where('ledger_account_id', $account->id)->whereIn('tag', ['bank_deposit', 'cooperative_bank_deposit'])->exists();
        if (! $tagged) {
            throw new PostingRejected("Account {$code} is not tagged as a bank account.");
        }

        return $account;
    }

    /** CSV columns: date (YYYY-MM-DD), description, amount (+ in / - out), reference (optional). Header row required. */
    public function import(LedgerAccount $account, string $csv, ?User $by): array
    {
        $rows = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim($csv)));
        $header = array_map(fn ($h) => strtolower(trim($h)), array_shift($rows) ?? []);
        if (array_slice($header, 0, 3) !== ['date', 'description', 'amount']) {
            throw new PostingRejected('The first row must be: date,description,amount[,reference].');
        }
        $lines = [];
        foreach ($rows as $i => $r) {
            $n = $i + 2;
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $r[0] ?? '') || ! checkdate((int) substr($r[0], 5, 2), (int) substr($r[0], 8, 2), (int) substr($r[0], 0, 4))) {
                throw new PostingRejected("Row {$n}: invalid date.");
            }
            if (! preg_match('/^-?\d{1,16}(\.\d{1,2})?$/', trim($r[2] ?? '')) || Money::parseSigned(trim($r[2]))->isZero()) {
                throw new PostingRejected("Row {$n}: amount must be a non-zero number with at most 2 decimals.");
            }
            $lines[] = ['statement_date' => $r[0], 'description' => mb_substr(trim($r[1] ?? ''), 0, 300), 'amount' => trim($r[2]), 'reference' => isset($r[3]) ? mb_substr(trim($r[3]), 0, 100) : null];
        }

        $batch = (string) Str::uuid();
        DB::transaction(function () use ($account, $lines, $batch, $by) {
            foreach ($lines as $line) {
                DB::table('bank_statement_lines')->insert($line + ['cooperative_id' => $this->tenant->cooperativeId(), 'ledger_account_id' => $account->id,
                    'import_batch' => $batch, 'imported_by' => $by?->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('bank_statement.imported', $account, ['batch' => $batch, 'lines' => count($lines)], $by);
        });

        return ['batch' => $batch, 'imported' => count($lines), 'matched' => $this->autoMatch($account)];
    }

    public function autoMatch(LedgerAccount $account): int
    {
        $matched = 0;
        DB::transaction(function () use ($account, &$matched) {
            $unmatched = DB::table('bank_statement_lines')->where('ledger_account_id', $account->id)->whereNull('matched_journal_line_id')->orderBy('statement_date')->orderBy('id')->lockForUpdate()->get();
            foreach ($unmatched as $s) {
                $line = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                    ->where('l.ledger_account_id', $account->id)
                    ->whereRaw('(l.debit - l.credit) = ?', [$s->amount])
                    ->whereBetween('e.entry_date', [date('Y-m-d', strtotime("{$s->statement_date} -".self::MATCH_DAYS.' days')), date('Y-m-d', strtotime("{$s->statement_date} +".self::MATCH_DAYS.' days'))])
                    ->whereNotExists(fn ($q) => $q->from('bank_statement_lines as b')->whereColumn('b.matched_journal_line_id', 'l.id'))
                    ->orderByRaw('ABS(DATEDIFF(e.entry_date, ?))', [$s->statement_date])->orderBy('l.id')
                    ->value('l.id');
                if ($line) {
                    DB::table('bank_statement_lines')->where('id', $s->id)->update(['matched_journal_line_id' => $line, 'updated_at' => now()]);
                    $matched++;
                }
            }
        });

        return $matched;
    }

    /** Ledger vs statement at a date, with the unmatched items on each side. */
    public function report(LedgerAccount $account, string $asOf, string $statementClosingBalance): array
    {
        $ledger = BigDecimal::of((string) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.ledger_account_id', $account->id)->where('e.entry_date', '<=', $asOf)->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) b')->value('b'));
        $unmatchedLedger = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.ledger_account_id', $account->id)->where('e.entry_date', '<=', $asOf)
            ->whereNotExists(fn ($q) => $q->from('bank_statement_lines as b')->whereColumn('b.matched_journal_line_id', 'l.id'))
            ->orderBy('e.entry_date')->get(['l.id', 'e.entry_date', 'e.description', DB::raw('(l.debit - l.credit) AS amount')]);
        $unmatchedBank = DB::table('bank_statement_lines')->where('ledger_account_id', $account->id)->where('statement_date', '<=', $asOf)
            ->whereNull('matched_journal_line_id')->orderBy('statement_date')->get(['id', 'statement_date', 'description', 'amount']);

        // Ledger - items not yet on the statement + items on the statement not yet in the ledger = statement balance.
        $adjusted = $ledger;
        foreach ($unmatchedLedger as $r) {
            $adjusted = $adjusted->minus($r->amount);
        }
        foreach ($unmatchedBank as $r) {
            $adjusted = $adjusted->plus($r->amount);
        }
        $statement = Money::parseSigned($statementClosingBalance);

        return [
            'account' => $account->code, 'as_of' => $asOf,
            'ledger_balance' => Money::format($ledger), 'statement_balance' => Money::format($statement),
            'adjusted_ledger_balance' => Money::format($adjusted), 'reconciled' => $adjusted->isEqualTo($statement),
            'unmatched_ledger' => $unmatchedLedger->map(fn ($r) => ['date' => $r->entry_date, 'description' => $r->description, 'amount' => Money::format($r->amount)])->all(),
            'unmatched_statement' => $unmatchedBank->map(fn ($r) => ['date' => $r->statement_date, 'description' => $r->description, 'amount' => Money::format($r->amount)])->all(),
        ];
    }
}
