<?php

namespace App\Savings;

use App\Accounting\LedgerMappings;
use App\Accounting\Money;
use App\Accounting\PostingRejected;
use App\Accounting\PostingService;
use App\Audit\AuditLogger;
use App\Authorization\Authorizer;
use App\Authorization\Permission;
use App\Calendar\BusinessDayService;
use App\Compliance\CashMovements;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Notifications\SmsOutbox;
use App\Support\NumberSequence;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Savings products, accounts, cash deposits and withdrawals.
 * PROPOSED postings: cash deposit = Dr cash / Cr product savings account;
 * cash withdrawal = Dr product savings account / Cr cash.
 * Every money movement: one transaction that locks the account, posts the
 * journal, writes the sub-ledger row, updates the balance and records cash.
 */
class SavingsService
{
    /** NCRA AML/CFT Guidance (Nepal Gazette, from 15 Jan 2026): cash withdrawal limit per transaction. */
    public const CASH_WITHDRAWAL_LIMIT = '500000.00';

    /** NCRA Std 2/3.1: term savings of up to 3 years. */
    public const MAX_TERM_MONTHS = 36;

    /** Transaction types that add to the balance; every other type takes away. */
    public const CREDIT_TYPES = ['deposit', 'interest', 'transfer_in', 'loan_disbursement'];

    public function __construct(
        private TenantContext $tenant,
        private PostingService $posting,
        private LedgerMappings $mappings,
        private SavingsLimits $limits,
        private CashMovements $cash,
        private AuditLogger $audit,
        private Authorizer $authorizer,
    ) {}

    public function createProduct(array $v, ?User $by = null): SavingsProduct
    {
        $account = LedgerAccount::query()->where('code', $v['ledger_account_code'])->first()
            ?? throw new PostingRejected("Account {$v['ledger_account_code']} does not exist.");
        if ($account->type !== 'liability' || ! $account->is_postable) {
            throw new PostingRejected('Savings must be held in a posting liability account.');
        }
        if ($v['type'] === 'term' && (empty($v['term_months']) || $v['term_months'] > self::MAX_TERM_MONTHS)) {
            throw new PostingRejected('Term savings need a term of 1 to 36 months (NCRA Std 2/3.1).');
        }
        if ($v['type'] !== 'term' && ! empty($v['term_months'])) {
            throw new PostingRejected('Only term products have a term.');
        }
        Money::parse($v['minimum_balance'] ?? '0');

        return DB::transaction(function () use ($v, $account, $by) {
            if (SavingsProduct::query()->where('code', $v['code'])->exists()) {
                throw new PostingRejected("Product code {$v['code']} already exists.");
            }
            $product = SavingsProduct::query()->forceCreate([
                'cooperative_id' => $this->tenant->cooperativeId(),
                'code' => $v['code'], 'name' => $v['name'], 'type' => $v['type'],
                'interest_rate' => $v['interest_rate'], 'interest_posting' => $v['interest_posting'],
                'minimum_balance' => $v['minimum_balance'] ?? '0', 'term_months' => $v['term_months'] ?? null,
                'ledger_account_id' => $account->id,
            ]);
            $this->audit->record('savings_product.created', $product, ['code' => $product->code, 'rate' => $v['interest_rate']], $by);

            return $product->refresh();
        });
    }

    public function open(Member $member, SavingsProduct $product, ?User $by = null): SavingsAccount
    {
        if ($member->status !== 'active') {
            throw new PostingRejected('Savings accounts are for active members only (Act s.50(1)).');
        }
        if ($product->status !== 'active') {
            throw new PostingRejected('This savings product is not open for new accounts.');
        }

        return DB::transaction(function () use ($member, $product, $by) {
            $today = BusinessDayService::today();
            $account = SavingsAccount::query()->forceCreate([
                'cooperative_id' => $member->cooperative_id,
                'member_id' => $member->id,
                'savings_product_id' => $product->id,
                'account_number' => NumberSequence::next($member->cooperative_id, 'savings_account'),
                'opened_on' => $today,
                // PROPOSED: term counted in AD calendar months (no overflow: 31 Jan + 1 month = 28/29 Feb).
                // BS months would fail for maturities beyond the verified BS calendar.
                'maturity_date' => $product->term_months ? CarbonImmutable::parse($today)->addMonthsNoOverflow($product->term_months)->format('Y-m-d') : null,
                'opened_by' => $by?->id,
            ]);
            $this->audit->record('savings_account.opened', $account, ['product' => $product->code], $by);

            return $account->refresh(); // load database defaults (balance, hold, status)
        });
    }

    public function deposit(SavingsAccount $account, string $amount, ?User $by, string $key, ?string $sourceOfFunds = null, ?string $narration = null): array
    {
        $value = Money::parse($amount);
        $this->assertPositive($value);
        if (CashMovements::requiresSourceOfFunds($amount) && trim((string) $sourceOfFunds) === '') {
            throw new PostingRejected('Source of funds is required for deposits of Rs 10 lakh or more (AML Instruction 8; Act s.52क).');
        }

        return $this->move($account, 'deposit', $value, $by, $key, function (SavingsAccount $locked, string $businessDate) use ($value) {
            if ($locked->member->status !== 'active') {
                throw new PostingRejected('Deposits are accepted from active members only (Act s.50(1)).');
            }
            $this->limits->assertDepositAllowed($locked->member, $value, $businessDate);
        }, compact('sourceOfFunds', 'narration'));
    }

    public function withdraw(SavingsAccount $account, string $amount, ?User $by, string $key, ?string $largeCashReason = null, ?string $narration = null): array
    {
        $value = Money::parse($amount);
        $this->assertPositive($value);
        if ($value->isGreaterThan(self::CASH_WITHDRAWAL_LIMIT)) {
            if (trim((string) $largeCashReason) === '') {
                throw new PostingRejected('Cash withdrawals above Rs 5 lakh need a written reason (Nepal Gazette, from 15 Jan 2026).');
            }
            if (! $by || ! $this->authorizer->allows($by, Permission::SavingsLargeCashWithdrawal)) {
                throw new PostingRejected('Cash withdrawals above Rs 5 lakh need approval by someone with large-withdrawal authority.');
            }
        }

        return $this->move($account, 'withdrawal', $value, $by, $key, function (SavingsAccount $locked, string $businessDate) use ($value) {
            if ($locked->product->type === 'term' && $locked->maturity_date && $businessDate < $locked->maturity_date->format('Y-m-d')) {
                throw new PostingRejected("Term savings cannot be withdrawn before maturity ({$locked->maturity_date->format('Y-m-d')}).");
            }
            $available = BigDecimal::of($locked->balance)->minus($locked->hold_amount)->minus($locked->product->minimum_balance);
            if ($value->isGreaterThan($available)) {
                throw new PostingRejected('Insufficient available balance: Rs '.Money::format($available->isNegative() ? '0' : $available).' can be withdrawn.');
            }
        }, ['largeCashReason' => $largeCashReason, 'narration' => $narration]);
    }

    /** @return array{transaction_id:int, balance:string, journal_entry_id:int} */
    private function move(SavingsAccount $account, string $type, BigDecimal $value, ?User $by, string $key, callable $check, array $extra): array
    {
        $journalKey = "savings:{$key}";

        return DB::transaction(function () use ($account, $type, $value, $by, $journalKey, $check, $extra) {
            if ($existing = JournalEntry::query()->where('idempotency_key', $journalKey)->first()) {
                $row = DB::table('savings_transactions')->where('journal_entry_id', $existing->id)->first();
                if (! $row || (int) $row->savings_account_id !== $account->id || $row->type !== $type || ! $value->isEqualTo($row->amount)) {
                    throw new PostingRejected('This Idempotency-Key was already used for a different transaction.');
                }

                return $this->result($row, $account->refresh());
            }

            $locked = SavingsAccount::query()->whereKey($account->id)->lockForUpdate()->with(['product', 'member'])->firstOrFail();
            if ($locked->status !== 'active') {
                throw new PostingRejected("Account {$locked->account_number} is {$locked->status}.");
            }
            $businessDate = app(BusinessDayService::class)->current()?->business_date->format('Y-m-d')
                ?? throw new PostingRejected('No business day is open. Open the business day before posting.');
            $check($locked, $businessDate);

            $liability = $locked->product->ledger_account_id;
            $cash = $this->mappings->account('cash')->id;
            $lines = $type === 'deposit'
                ? [['ledger_account_id' => $cash, 'debit' => (string) $value], ['ledger_account_id' => $liability, 'credit' => (string) $value]]
                : [['ledger_account_id' => $liability, 'debit' => (string) $value], ['ledger_account_id' => $cash, 'credit' => (string) $value]];
            $entry = $this->posting->post(
                ucfirst($type)." (cash): account {$locked->account_number}, member {$locked->member->member_number}",
                $lines, $by, $journalKey, ['type' => 'savings_'.$type, 'id' => $locked->id],
            );

            $balance = $type === 'deposit' ? BigDecimal::of($locked->balance)->plus($value) : BigDecimal::of($locked->balance)->minus($value);
            $locked->forceFill(['balance' => (string) $balance])->save();
            $id = DB::table('savings_transactions')->insertGetId([
                'cooperative_id' => $locked->cooperative_id, 'savings_account_id' => $locked->id,
                'type' => $type, 'channel' => 'cash', 'amount' => (string) $value, 'balance_after' => (string) $balance,
                'journal_entry_id' => $entry->id, 'narration' => $extra['narration'] ?? null,
                'source_of_funds' => $extra['sourceOfFunds'] ?? null, 'large_cash_reason' => $extra['largeCashReason'] ?? null,
                'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->cash->record($locked->member, $entry, $type === 'deposit' ? 'in' : 'out', (string) $value, 'savings_'.$type, $id);
            app(SmsOutbox::class)->queue($locked->member, sprintf('%s %s %s account %s on %s. Balance %s.',
                SmsOutbox::rs((string) $value), $type === 'deposit' ? 'deposited to' : 'withdrawn from', $locked->product->name,
                SmsOutbox::mask($locked->account_number), $businessDate, SmsOutbox::rs((string) $balance)));

            return $this->result(DB::table('savings_transactions')->find($id), $locked);
        });
    }

    private function result(object $row, SavingsAccount $account): array
    {
        return [
            'transaction_id' => $row->id, 'type' => $row->type, 'amount' => Money::format($row->amount),
            'balance_after' => Money::format($row->balance_after), 'balance' => Money::format($account->balance),
            'journal_entry_id' => $row->journal_entry_id,
        ];
    }

    private function assertPositive(BigDecimal $value): void
    {
        if (! $value->isPositive()) {
            throw new PostingRejected('The amount must be greater than zero.');
        }
    }
}
