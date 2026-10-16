<?php

namespace App\Accounting;

use App\Audit\AuditLogger;
use App\Models\LedgerAccount;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Which of the cooperative's own ledger accounts plays each role. Roles are
 * fixed here; the accounts are the cooperative's (accountant's) choice.
 */
class LedgerMappings
{
    /** role => [description, required account type] */
    public const ROLES = [
        'cash' => ['Cash in hand', 'asset'],
        'share_capital' => ['Share capital (Act s.63)', 'equity'],
        'reserve_fund' => ['Reserve fund (Act s.68), part of primary capital', 'equity'],
        'savings_interest_expense' => ['Interest expense on savings', 'expense'],
        'loan_interest_income' => ['Interest income on loans', 'income'],
        'loan_penalty_income' => ['Penalty interest income on loans', 'income'],
        'loan_fee_income' => ['Loan service fee income', 'income'],
        'loan_loss_provision' => ['Loan loss provision (contra asset)', 'asset'],
        'loan_loss_provision_expense' => ['Loan loss provision expense', 'expense'],
    ];

    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    public function account(string $role): LedgerAccount
    {
        $id = DB::table('ledger_mappings')->where('cooperative_id', $this->tenant->cooperativeId())->where('key', $role)->value('ledger_account_id');

        return ($id ? LedgerAccount::query()->find($id) : null)
            ?? throw new PostingRejected("Configure the ledger account for '{$role}' ({$this->label($role)}) first.");
    }

    /** @return array<string, ?int> */
    public function all(): array
    {
        $set = DB::table('ledger_mappings')->where('cooperative_id', $this->tenant->cooperativeId())->pluck('ledger_account_id', 'key')->all();

        return array_map(fn ($role) => $set[$role] ?? null, array_combine(array_keys(self::ROLES), array_keys(self::ROLES)));
    }

    public function set(string $role, string $accountCode): void
    {
        if (! isset(self::ROLES[$role])) {
            throw new PostingRejected("Unknown ledger role '{$role}'.");
        }
        $account = LedgerAccount::query()->where('code', $accountCode)->first()
            ?? throw new PostingRejected("Account {$accountCode} does not exist.");
        if (! $account->is_postable) {
            throw new PostingRejected("Account {$accountCode} is a group account.");
        }
        $expected = self::ROLES[$role][1];
        if ($account->type !== $expected) {
            throw new PostingRejected("'{$role}' must be an {$expected} account; {$accountCode} is {$account->type}.");
        }

        DB::transaction(function () use ($role, $account) {
            DB::table('ledger_mappings')->upsert([[
                'cooperative_id' => $this->tenant->cooperativeId(), 'key' => $role, 'ledger_account_id' => $account->id,
                'created_at' => now(), 'updated_at' => now(),
            ]], ['cooperative_id', 'key'], ['ledger_account_id', 'updated_at']);
            $this->audit->record('ledger_mapping.set', $account, ['role' => $role, 'account' => $account->code]);
        });
    }

    private function label(string $role): string
    {
        return self::ROLES[$role][0] ?? $role;
    }
}
