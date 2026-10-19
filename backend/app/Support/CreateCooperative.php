<?php

namespace App\Support;

use App\Accounting\LedgerAccountService;
use App\Accounting\LedgerMappings;
use App\Authorization\Permission;
use App\Calendar\FiscalYearService;
use App\Models\Cooperative;
use App\Models\LedgerAccount;
use App\Models\Role;
use App\Models\User;
use App\Savings\SavingsService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Sets up a new cooperative ready for a history import: head office, fiscal
 * year, a Manager role for the given staff, a DRAFT chart of accounts with every
 * ledger role mapped, and (optionally) the savings products from the
 * products.csv written by migration:convert-excel. No business day is opened,
 * so passbook history can be replayed from the start of the fiscal year.
 *
 * The chart is a starting point only (PROPOSED): the accountant adapts it.
 * Products are created as general savings with quarterly interest posting
 * (PROPOSED: the old workbooks total interest per quarter); review each one.
 */
class CreateCooperative extends Command
{
    protected $signature = 'cooperative:create {code : short code, e.g. SBK} {name : full name}
        {--admin=* : email of an existing staff user to make Manager (repeatable)}
        {--fiscal-year=2083 : BS year the first fiscal year starts in}
        {--products= : products.csv from migration:convert-excel}';

    protected $description = 'Create a cooperative with a draft chart of accounts and products, ready for importing history';

    /** code => [name, type, parent, ledger role] */
    private const CHART = [
        '1' => ['Assets', 'asset', null, null],
        '1.1' => ['Cash in hand', 'asset', '1', 'cash'],
        '1.2' => ['Bank accounts', 'asset', '1', null],
        '1.3' => ['Loans to members', 'asset', '1', null],
        '1.4' => ['Loan loss provision', 'asset', '1', 'loan_loss_provision'],
        '2' => ['Liabilities', 'liability', null, null],
        '2.1' => ['Member savings', 'liability', '2', null],
        '2.9' => ['Tax deducted from savings interest', 'liability', '2', 'interest_tax_payable'],
        '3' => ['Equity', 'equity', null, null],
        '3.1' => ['Share capital', 'equity', '3', 'share_capital'],
        '3.2' => ['Reserve fund', 'equity', '3', 'reserve_fund'],
        '3.3' => ['Opening balances (previous system)', 'equity', '3', 'opening_balance'],
        '4' => ['Income', 'income', null, null],
        '4.1' => ['Loan interest income', 'income', '4', 'loan_interest_income'],
        '4.2' => ['Penalty interest income', 'income', '4', 'loan_penalty_income'],
        '4.3' => ['Loan service fee income', 'income', '4', 'loan_fee_income'],
        '4.4' => ['Service charges on savings', 'income', '4', 'service_charge_income'],
        '5' => ['Expenses', 'expense', null, null],
        '5.1' => ['Interest on savings', 'expense', '5', 'savings_interest_expense'],
        '5.2' => ['Loan loss provision expense', 'expense', '5', 'loan_loss_provision_expense'],
    ];

    public function handle(TenantContext $tenant): int
    {
        $code = strtoupper((string) $this->argument('code'));
        try {
            if (! preg_match('/^[A-Z0-9-]{2,20}$/', $code)) {
                throw new RuntimeException('The code must be 2 to 20 letters, digits or hyphens.');
            }
            if (Cooperative::query()->where('code', $code)->exists()) {
                throw new RuntimeException("Cooperative {$code} already exists.");
            }
            $admins = array_map(fn ($email) => User::query()->where('email', $email)->first() ?? throw new RuntimeException("No user {$email}."), $this->option('admin'));
            if ($admins === []) {
                throw new RuntimeException('Give at least one --admin so someone can sign in to the cooperative.');
            }
            $products = $this->option('products') ? Csv::read((string) $this->option('products'), ['product_code', 'sheet', 'account_type', 'interest_rate_in_header', 'minimum_balance', 'accounts']) : [];

            $created = DB::transaction(function () use ($code, $admins, $products, $tenant) {
                $coop = Cooperative::query()->forceCreate(['code' => $code, 'name' => (string) $this->argument('name')]);
                $branch = $tenant->withoutTenantScope(fn () => $coop->branches()->forceCreate(['code' => 'HO', 'name' => 'Head Office', 'is_head_office' => true]));
                $coop->users()->syncWithoutDetaching(collect($admins)->mapWithKeys(fn (User $u) => [$u->id => ['branch_id' => $branch->id]])->all());

                return $tenant->runAs($coop->id, $branch->id, function () use ($admins, $products) {
                    app(FiscalYearService::class)->open((int) $this->option('fiscal-year'));
                    $manager = Role::create(['key' => 'manager', 'name' => 'Manager']);
                    $manager->syncPermissions(Permission::cases());
                    array_map($manager->assignTo(...), $admins);

                    $accounts = app(LedgerAccountService::class);
                    foreach (self::CHART as $account => [$name, $type, $parent, $role]) {
                        $accounts->create($account, $name, $type, $parent !== null && $account !== '2.1', $parent);
                        if ($role) {
                            app(LedgerMappings::class)->set($role, $account);
                        }
                    }
                    DB::table('ledger_account_tags')->insert(['cooperative_id' => app(TenantContext::class)->cooperativeId(),
                        'ledger_account_id' => LedgerAccount::query()->where('code', '1.2')->value('id'), 'tag' => 'bank_deposit', 'created_at' => now(), 'updated_at' => now()]);

                    $made = [];
                    foreach ($products as $i => $p) {
                        if (strtoupper($p['product_code']) === ImportPassbookHistory::SHARE) {
                            continue; // shares use share capital (3.1)
                        }
                        $name = $p['account_type'] !== '' ? "{$p['account_type']} ({$p['sheet']})" : $p['sheet'];
                        $ledger = '2.1.'.($i + 1);
                        $accounts->create($ledger, "Savings: {$name}", 'liability', true, '2.1');
                        app(SavingsService::class)->createProduct(['code' => $p['product_code'], 'name' => mb_substr($name, 0, 255), 'type' => 'general',
                            'interest_rate' => $p['interest_rate_in_header'] !== '' ? $p['interest_rate_in_header'] : '0', 'interest_posting' => 'quarterly',
                            'minimum_balance' => $p['minimum_balance'] !== '' ? $p['minimum_balance'] : '0', 'ledger_account_code' => $ledger]);
                        $made[] = $p['product_code'];
                    }

                    return $made;
                });
            });
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Created cooperative {$code} with a draft chart of accounts and fiscal year {$this->option('fiscal-year')}.");
        $this->info($created === [] ? 'No savings products created.' : 'Savings products (general, quarterly interest; review them): '.implode(', ', $created).'.');
        $this->info('Next: sign in, choose this cooperative, then Settings → Import. Do not open a business day before the history is imported.');

        return self::SUCCESS;
    }
}
