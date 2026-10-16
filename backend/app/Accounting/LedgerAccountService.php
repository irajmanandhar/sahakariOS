<?php

namespace App\Accounting;

use App\Audit\AuditLogger;
use App\Models\LedgerAccount;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Chart of accounts of the current cooperative. The chart itself is the
 * cooperative's (and its accountant's) decision; SahakariOS ships none.
 */
class LedgerAccountService
{
    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    public function create(string $code, string $name, string $type, bool $isPostable, ?string $parentCode = null): LedgerAccount
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new PostingRejected("Unknown account type '{$type}'.");
        }

        return DB::transaction(function () use ($code, $name, $type, $isPostable, $parentCode) {
            $parent = null;
            if ($parentCode !== null) {
                $parent = LedgerAccount::query()->where('code', $parentCode)->first()
                    ?? throw new PostingRejected("Parent account {$parentCode} does not exist.");
                if ($parent->is_postable) {
                    throw new PostingRejected("Parent account {$parentCode} is a posting account; only group accounts can have children.");
                }
                if ($parent->type !== $type) {
                    throw new PostingRejected("Account type must match its parent ({$parent->type}).");
                }
            }
            if (LedgerAccount::query()->where('code', $code)->exists()) {
                throw new PostingRejected("Account code {$code} already exists.");
            }

            $account = LedgerAccount::query()->forceCreate([
                'cooperative_id' => $this->tenant->cooperativeId(),
                'parent_id' => $parent?->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'is_postable' => $isPostable,
            ]);
            $this->audit->record('ledger_account.created', $account, compact('code', 'name', 'type') + ['is_postable' => $isPostable]);

            return $account->refresh();
        });
    }
}
