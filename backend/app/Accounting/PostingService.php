<?php

namespace App\Accounting;

use App\Audit\AuditLogger;
use App\Models\Branch;
use App\Models\BusinessDay;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only code that writes journal entries.
 *
 * A posting is one database transaction: lock the open business day, number
 * the entry, insert header + lines, write the audit entry. Either all of it
 * commits or none of it does. Posted entries are immutable; mistakes are
 * corrected with reverse().
 */
class PostingService
{
    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    /**
     * @param  list<array{ledger_account_id?: int, account_code?: string, debit?: string, credit?: string, description?: ?string, branch_id?: int}>  $lines
     * @param  ?string  $idempotencyKey  same key + same request returns the original entry; same key + different request is refused
     */
    public function post(string $description, array $lines, ?User $by = null, ?string $idempotencyKey = null, ?array $source = null): JournalEntry
    {
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 500) {
            throw new PostingRejected('A description of 1 to 500 characters is required.');
        }

        $prepared = $this->prepareLines($lines);
        $requestHash = hash('sha256', json_encode([$description, $prepared, $source], JSON_THROW_ON_ERROR));

        if ($idempotencyKey !== null && ($existing = $this->replay($idempotencyKey, $requestHash))) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->write($description, $prepared, $by, $idempotencyKey, $requestHash, $source, null));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key committed first: return its entry.
            if ($idempotencyKey !== null && ($existing = $this->replay($idempotencyKey, $requestHash))) {
                return $existing;
            }
            throw $e;
        }
    }

    /** Post the exact mirror of an entry on the current business day. */
    public function reverse(JournalEntry $original, string $reason, ?User $by = null): JournalEntry
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PostingRejected('A reason is required to reverse an entry.');
        }
        if ($original->reversal_of_id !== null) {
            throw new PostingRejected('A reversal cannot itself be reversed; post a new entry instead.');
        }

        $mirror = $original->lines->map(fn (JournalLine $line) => [
            'ledger_account_id' => $line->ledger_account_id,
            'branch_id' => $line->branch_id,
            'debit' => $line->credit,
            'credit' => $line->debit,
            'description' => $line->description,
        ])->all();

        try {
            return DB::transaction(function () use ($original, $reason, $by, $mirror) {
                if (JournalEntry::query()->where('reversal_of_id', $original->id)->lockForUpdate()->exists()) {
                    throw new PostingRejected('This entry has already been reversed.');
                }

                return $this->write(
                    mb_substr("Reversal of {$original->reference()}: {$reason}", 0, 500),
                    $this->prepareLines($mirror, allowInactive: true),
                    $by, null, null, null, $original,
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw new PostingRejected('This entry has already been reversed.');
        }
    }

    /** Validate lines and resolve accounts. Returns normalised lines; throws PostingRejected. */
    private function prepareLines(array $lines, bool $allowInactive = false): array
    {
        if (count($lines) < 2) {
            throw new PostingRejected('A journal entry needs at least two lines.');
        }
        if (count($lines) > 500) {
            throw new PostingRejected('A journal entry can have at most 500 lines.');
        }

        $debits = $credits = Money::zero();
        $prepared = [];

        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            $debit = isset($line['debit']) && $line['debit'] !== '' ? Money::parse((string) $line['debit']) : Money::zero();
            $credit = isset($line['credit']) && $line['credit'] !== '' ? Money::parse((string) $line['credit']) : Money::zero();

            if ($debit->isPositive() === $credit->isPositive()) {
                throw new PostingRejected("Line {$n}: enter either a debit or a credit greater than zero, not both.");
            }

            $account = $this->account($line, $n);
            if (! $account->is_postable) {
                throw new PostingRejected("Line {$n}: {$account->code} is a group account and cannot be posted to.");
            }
            if (! $allowInactive && $account->status !== 'active') {
                throw new PostingRejected("Line {$n}: account {$account->code} is inactive.");
            }

            $branchId = $line['branch_id'] ?? $this->tenant->branchId();
            if (! Branch::query()->whereKey($branchId)->where('status', 'active')->exists() && ! $allowInactive) {
                throw new PostingRejected("Line {$n}: branch is not an active branch of this cooperative.");
            }

            $debits = $debits->plus($debit);
            $credits = $credits->plus($credit);
            $prepared[] = [
                'ledger_account_id' => $account->id,
                'branch_id' => (int) $branchId,
                'debit' => (string) $debit,
                'credit' => (string) $credit,
                'description' => isset($line['description']) ? mb_substr((string) $line['description'], 0, 500) : null,
            ];
        }

        if (! $debits->isEqualTo($credits)) {
            throw new PostingRejected("Entry does not balance: debits {$debits}, credits {$credits}.");
        }

        return $prepared;
    }

    private function account(array $line, int $n): LedgerAccount
    {
        $query = LedgerAccount::query(); // tenant-scoped: another cooperative's account is "not found"

        $account = match (true) {
            isset($line['ledger_account_id']) => $query->whereKey($line['ledger_account_id'])->first(),
            isset($line['account_code']) => $query->where('code', $line['account_code'])->first(),
            default => throw new PostingRejected("Line {$n}: an account is required."),
        };

        return $account ?? throw new PostingRejected("Line {$n}: account not found.");
    }

    private function replay(string $key, string $requestHash): ?JournalEntry
    {
        $existing = JournalEntry::query()->where('idempotency_key', $key)->first();

        if ($existing !== null && ! hash_equals($existing->request_hash, $requestHash)) {
            throw new PostingRejected('This Idempotency-Key was already used for a different journal entry.');
        }

        return $existing;
    }

    private function write(string $description, array $lines, ?User $by, ?string $key, ?string $hash, ?array $source, ?JournalEntry $reversalOf): JournalEntry
    {
        $cooperativeId = $this->tenant->cooperativeId();

        // Lock the open day: it cannot be closed half-way through this posting.
        $day = BusinessDay::query()->where('status', 'open')->lockForUpdate()->first()
            ?? throw new PostingRejected('No business day is open. Open the business day before posting.');

        DB::insert('INSERT INTO journal_sequences (cooperative_id, fiscal_year_id, last_number) VALUES (?, ?, 0)
            ON DUPLICATE KEY UPDATE last_number = last_number', [$cooperativeId, $day->fiscal_year_id]);
        $number = DB::selectOne('SELECT last_number FROM journal_sequences WHERE cooperative_id = ? AND fiscal_year_id = ? FOR UPDATE',
            [$cooperativeId, $day->fiscal_year_id])->last_number + 1;
        DB::update('UPDATE journal_sequences SET last_number = ? WHERE cooperative_id = ? AND fiscal_year_id = ?',
            [$number, $cooperativeId, $day->fiscal_year_id]);

        $total = array_reduce($lines, fn (BigDecimal $sum, $l) => $sum->plus($l['debit']), Money::zero());

        $entry = JournalEntry::query()->forceCreate([
            'cooperative_id' => $cooperativeId,
            'fiscal_year_id' => $day->fiscal_year_id,
            'business_day_id' => $day->id,
            'entry_number' => $number,
            'entry_date' => $day->business_date->format('Y-m-d'),
            'description' => $description,
            'line_count' => count($lines),
            'total' => (string) $total,
            'reversal_of_id' => $reversalOf?->id,
            'source_type' => $source['type'] ?? null,
            'source_id' => $source['id'] ?? null,
            'idempotency_key' => $key,
            'request_hash' => $hash,
            'posted_by' => $by?->id,
            'posted_at' => now(),
        ]);

        foreach ($lines as $i => $line) {
            JournalLine::query()->forceCreate($line + [
                'cooperative_id' => $cooperativeId,
                'journal_entry_id' => $entry->id,
                'line_number' => $i + 1,
            ]);
        }

        $this->audit->record($reversalOf ? 'journal.reversed' : 'journal.posted', $entry, array_filter([
            'reference' => $entry->reference(),
            'total' => (string) $total,
            'reversal_of' => $reversalOf?->reference(),
        ]), $by);

        return $entry->load('lines');
    }
}
