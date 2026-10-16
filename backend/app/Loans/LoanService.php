<?php

namespace App\Loans;

use App\Accounting\LedgerMappings;
use App\Accounting\Money;
use App\Accounting\PostingRejected;
use App\Accounting\PostingService;
use App\Accounting\PrimaryCapital;
use App\Audit\AuditLogger;
use App\Calendar\BusinessDayService;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Notifications\SmsOutbox;
use App\Support\NumberSequence;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Loan products, applications, decisions and disbursement.
 * Rules: Cooperatives Act s.50, s.52; NCRA regulatory standard 2082 ch.3, 5, 8
 * (register rows L-01..L-18, I-01..I-08). Accounting treatments are PROPOSED:
 * disbursement = Dr loans receivable (principal) / Cr borrower's savings
 * (principal - fee) / Cr fee income (fee).
 */
class LoanService
{
    /** Std 3/1.1 and Act s.50(8): months of membership before any loan / an unsecured loan. */
    public const MIN_MEMBERSHIP_MONTHS = 3;

    public const MIN_MEMBERSHIP_MONTHS_UNSECURED = 6;

    /** Act s.52(2); Std 3/1.2: one member's loans <= 15% of primary capital. */
    public const SINGLE_MEMBER_PERCENT = 15;

    /** Std 3/1.3: unsecured loans <= lower of 5x savings and Rs 3 lakh, with 2 member guarantors. */
    public const UNSECURED_SAVINGS_MULTIPLE = 5;

    public const UNSECURED_CAP = '300000.00';

    public const UNSECURED_GUARANTORS = 2;

    /** Std 3/2.1: a loan against the member's own savings <= 90% of the savings. */
    public const SAVINGS_SECURED_PERCENT = 90;

    public function __construct(
        private TenantContext $tenant,
        private PostingService $posting,
        private LedgerMappings $mappings,
        private PrimaryCapital $primaryCapital,
        private AuditLogger $audit,
    ) {}

    public function createProduct(array $v, ?User $by = null): LoanProduct
    {
        $account = LedgerAccount::query()->where('code', $v['ledger_account_code'])->first()
            ?? throw new PostingRejected("Account {$v['ledger_account_code']} does not exist.");
        if ($account->type !== 'asset' || ! $account->is_postable) {
            throw new PostingRejected('Loans must be held in a posting asset account.');
        }
        if (BigDecimal::of($v['penalty_percent'] ?? '0')->isGreaterThan(5)) {
            throw new PostingRejected('Penalty interest may not exceed 5% of the overdue interest (Act s.50(5क)).');
        }
        if ($v['collateral_type'] === 'real_estate' && empty($v['max_ltv_percent'])) {
            throw new PostingRejected('Real-estate loans need the maximum loan-to-value % from the cooperative\'s policy (NCRA Std 3/2.2).');
        }
        if ($v['collateral_type'] !== 'real_estate' && ! empty($v['max_ltv_percent'])) {
            throw new PostingRejected('Only real-estate products have a loan-to-value limit.');
        }
        Money::parse($v['max_amount']);

        return DB::transaction(function () use ($v, $account, $by) {
            if (LoanProduct::query()->where('code', $v['code'])->exists()) {
                throw new PostingRejected("Product code {$v['code']} already exists.");
            }
            $product = LoanProduct::query()->forceCreate([
                'cooperative_id' => $this->tenant->cooperativeId(),
                'code' => $v['code'], 'name' => $v['name'], 'collateral_type' => $v['collateral_type'],
                'interest_rate' => $v['interest_rate'], 'penalty_percent' => $v['penalty_percent'] ?? '0',
                'service_fee_percent' => $v['service_fee_percent'] ?? '0', 'repayment_frequency' => $v['repayment_frequency'],
                'schedule_method' => $v['schedule_method'], 'max_term_months' => $v['max_term_months'],
                'max_grace_months' => $v['max_grace_months'] ?? 0, 'max_amount' => $v['max_amount'],
                'max_ltv_percent' => $v['max_ltv_percent'] ?? null, 'is_productive_sector' => (bool) ($v['is_productive_sector'] ?? false),
                'ledger_account_id' => $account->id,
            ]);
            $this->audit->record('loan_product.created', $product, ['code' => $product->code, 'rate' => $v['interest_rate']], $by);

            return $product->refresh();
        });
    }

    /**
     * @param  array{principal:string, term_months:int, grace_months?:int, purpose:string, pledged_savings_account_id?:?int, collateral_value?:?string, collateral_description?:?string, guarantor_member_ids?:list<int>, cic_report_reference?:?string}  $v
     */
    public function apply(Member $member, LoanProduct $product, array $v, ?User $by = null): Loan
    {
        return DB::transaction(function () use ($member, $product, $v, $by) {
            $member = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $principal = Money::parse($v['principal']);
            $draft = new Loan([]);
            $draft->forceFill([
                'member_id' => $member->id, 'principal' => (string) $principal, 'term_months' => $v['term_months'],
                'grace_months' => $v['grace_months'] ?? 0, 'pledged_savings_account_id' => $v['pledged_savings_account_id'] ?? null,
                'collateral_value' => $v['collateral_value'] ?? null,
            ]);
            $this->assertEligible($member, $product, $draft, $v['guarantor_member_ids'] ?? [], null);
            ScheduleBuilder::build((string) $principal, $product->interest_rate, $v['term_months'], $v['grace_months'] ?? 0,
                $product->repayment_frequency, $product->schedule_method, BusinessDayService::today()); // validates term shape

            $loan = Loan::query()->forceCreate([
                'cooperative_id' => $member->cooperative_id,
                'member_id' => $member->id,
                'loan_product_id' => $product->id,
                'loan_number' => NumberSequence::next($member->cooperative_id, 'loan'),
                'status' => 'applied',
                'principal' => (string) $principal,
                'interest_rate' => $product->interest_rate,
                'penalty_percent' => $product->penalty_percent,
                'term_months' => $v['term_months'],
                'grace_months' => $v['grace_months'] ?? 0,
                'purpose' => $v['purpose'],
                'pledged_savings_account_id' => $v['pledged_savings_account_id'] ?? null,
                'collateral_value' => $v['collateral_value'] ?? null,
                'collateral_description' => $v['collateral_description'] ?? null,
                'cic_report_reference' => $v['cic_report_reference'] ?? null,
                'applied_on' => BusinessDayService::today(),
                'applied_by' => $by?->id,
            ]);
            foreach (array_unique($v['guarantor_member_ids'] ?? []) as $guarantor) {
                DB::table('loan_guarantors')->insert(['cooperative_id' => $loan->cooperative_id, 'loan_id' => $loan->id,
                    'guarantor_member_id' => $guarantor, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('loan.applied', $loan, ['principal' => (string) $principal, 'product' => $product->code], $by);

            return $loan->refresh();
        });
    }

    public function approve(Loan $loan, User $by): Loan
    {
        return $this->decide($loan, $by, 'approved', null);
    }

    public function reject(Loan $loan, User $by, string $reason): Loan
    {
        if (trim($reason) === '') {
            throw new PostingRejected('A reason is required to reject a loan application.');
        }

        return $this->decide($loan, $by, 'rejected', trim($reason));
    }

    private function decide(Loan $loan, User $by, string $status, ?string $reason): Loan
    {
        return DB::transaction(function () use ($loan, $by, $status, $reason) {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->with(['product', 'member'])->firstOrFail();
            if ($loan->status !== 'applied') {
                throw new PostingRejected('This application has already been decided.');
            }
            if ($loan->applied_by !== null && $loan->applied_by === $by->id) {
                throw new PostingRejected('The person who entered a loan application cannot also decide it.');
            }
            if ($status === 'approved') {
                $this->assertEligible($loan->member, $loan->product, $loan, $this->guarantors($loan), $loan->id);
            }
            $loan->forceFill(['status' => $status, 'decided_at' => now(), 'decided_by' => $by->id, 'decision_reason' => $reason])->save();
            $this->audit->record("loan.{$status}", $loan, array_filter(['reason' => $reason]), $by);

            return $loan;
        });
    }

    /** Pay the loan into the borrower's own savings account (Std 8/10). */
    public function disburse(Loan $loan, SavingsAccount $into, ?User $by, string $key): Loan
    {
        return DB::transaction(function () use ($loan, $into, $by, $key) {
            if ($existing = JournalEntry::query()->where('idempotency_key', "loan-disburse:{$key}")->first()) {
                if ((int) $existing->source_id !== $loan->id) {
                    throw new PostingRejected('This Idempotency-Key was already used for a different transaction.');
                }

                return $loan->refresh();
            }
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->with(['product', 'member'])->firstOrFail();
            if ($loan->status !== 'approved') {
                throw new PostingRejected('Only approved loans can be disbursed.');
            }
            $savings = SavingsAccount::query()->whereKey($into->id)->lockForUpdate()->with('product')->firstOrFail();
            if ($savings->member_id !== $loan->member_id || $savings->status !== 'active') {
                throw new PostingRejected('Loans are disbursed only into the borrower\'s own active savings account (NCRA Std 8/10).');
            }
            $this->assertEligible($loan->member, $loan->product, $loan, $this->guarantors($loan), $loan->id);

            $principal = BigDecimal::of($loan->principal);
            $fee = $principal->multipliedBy($loan->product->service_fee_percent)->dividedBy(100, 2, RoundingMode::HalfUp);
            $net = $principal->minus($fee);
            $lines = [
                ['ledger_account_id' => $loan->product->ledger_account_id, 'debit' => (string) $principal],
                ['ledger_account_id' => $savings->product->ledger_account_id, 'credit' => (string) $net],
            ];
            if ($fee->isPositive()) {
                $lines[] = ['ledger_account_id' => $this->mappings->account('loan_fee_income')->id, 'credit' => (string) $fee];
            }
            $entry = $this->posting->post("Loan {$loan->loan_number} disbursed to savings account {$savings->account_number}", $lines,
                $by, "loan-disburse:{$key}", ['type' => 'loan', 'id' => $loan->id]);
            $today = $entry->entry_date->format('Y-m-d');

            $balance = BigDecimal::of($savings->balance)->plus($net);
            $savings->forceFill(['balance' => (string) $balance])->save();
            DB::table('savings_transactions')->insert([
                'cooperative_id' => $savings->cooperative_id, 'savings_account_id' => $savings->id, 'type' => 'loan_disbursement',
                'channel' => 'transfer', 'amount' => (string) $net, 'balance_after' => (string) $balance, 'journal_entry_id' => $entry->id,
                'narration' => "Loan {$loan->loan_number}".($fee->isPositive() ? " (service fee Rs {$fee} deducted)" : ''),
                'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($loan->pledged_savings_account_id) {
                // PROPOSED lien: hold enough savings that the loan stays <= 90% of them.
                $hold = $principal->multipliedBy(100)->dividedBy(self::SAVINGS_SECURED_PERCENT, 2, RoundingMode::Up);
                DB::table('savings_accounts')->where('id', $loan->pledged_savings_account_id)->increment('hold_amount', (string) $hold);
            }

            foreach (ScheduleBuilder::build($loan->principal, $loan->interest_rate, $loan->term_months, $loan->grace_months,
                $loan->product->repayment_frequency, $loan->product->schedule_method, $today) as $row) {
                DB::table('loan_schedules')->insert($row + ['cooperative_id' => $loan->cooperative_id, 'loan_id' => $loan->id, 'created_at' => now(), 'updated_at' => now()]);
            }

            $loan->forceFill(['status' => 'disbursed', 'disbursed_on' => $today, 'disbursement_savings_account_id' => $savings->id,
                'outstanding_principal' => (string) $principal, 'interest_paid_through' => $today])->save();
            DB::table('loan_transactions')->insert([
                'cooperative_id' => $loan->cooperative_id, 'loan_id' => $loan->id, 'type' => 'disbursement', 'amount' => (string) $principal,
                'principal' => (string) $principal, 'fee' => (string) $fee, 'outstanding_after' => (string) $principal,
                'journal_entry_id' => $entry->id, 'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(SmsOutbox::class)->queue($loan->member, sprintf('Loan %s of %s disbursed to savings account %s on %s. First instalment due %s.',
                $loan->loan_number, SmsOutbox::rs((string) $principal), SmsOutbox::mask($savings->account_number), $today,
                DB::table('loan_schedules')->where('loan_id', $loan->id)->orderBy('installment_number')->value('due_date')));
            $this->audit->record('loan.disbursed', $loan, ['principal' => (string) $principal, 'fee' => (string) $fee, 'savings_account' => $savings->account_number], $by);

            return $loan->refresh();
        });
    }

    /** Checks at application, approval and disbursement (limits can change in between). */
    private function assertEligible(Member $member, LoanProduct $product, Loan $loan, array $guarantorIds, ?int $excludeLoanId): void
    {
        $principal = BigDecimal::of($loan->principal);
        if ($member->status !== 'active') {
            throw new PostingRejected('Loans are for active members only (Act s.50(1)).');
        }
        if ($product->status !== 'active') {
            throw new PostingRejected('This loan product is not open for new loans.');
        }
        if ($principal->isGreaterThan($product->max_amount)) {
            throw new PostingRejected("The product allows at most Rs {$product->max_amount}.");
        }
        if ($loan->term_months > $product->max_term_months || $loan->grace_months > $product->max_grace_months) {
            throw new PostingRejected("The product allows at most {$product->max_term_months} months and {$product->max_grace_months} grace months.");
        }

        $months = $product->collateral_type === 'unsecured' ? self::MIN_MEMBERSHIP_MONTHS_UNSECURED : self::MIN_MEMBERSHIP_MONTHS;
        $since = $member->decided_at ?? throw new PostingRejected('The member\'s membership date is unknown.');
        if (CarbonImmutable::parse($since)->addMonthsNoOverflow($months)->format('Y-m-d') > BusinessDayService::today()) {
            throw new PostingRejected("This loan needs at least {$months} months of membership (NCRA Std 3/1.1; Act s.50(8)).");
        }

        // Single-member limit across all of the member's current loans.
        $existing = BigDecimal::of((string) DB::table('loans')->where('member_id', $member->id)
            ->when($excludeLoanId, fn ($q) => $q->where('id', '<>', $excludeLoanId))
            ->whereIn('status', ['approved', 'disbursed'])
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'disbursed' THEN outstanding_principal ELSE principal END), 0) AS t")->value('t'));
        $limit = $this->primaryCapital->amount()->multipliedBy(self::SINGLE_MEMBER_PERCENT)->dividedBy(100, 2, RoundingMode::Down);
        if ($existing->plus($principal)->isGreaterThan($limit)) {
            throw new PostingRejected('The member\'s loans would total Rs '.$existing->plus($principal)."; the limit is 15% of primary capital, Rs {$limit} (Act s.52(2)).");
        }

        match ($product->collateral_type) {
            'savings' => $this->assertSavingsSecured($member, $loan, $principal),
            'unsecured' => $this->assertUnsecured($member, $principal, $guarantorIds),
            'real_estate' => $this->assertRealEstate($product, $loan, $principal),
        };
    }

    private function assertSavingsSecured(Member $member, Loan $loan, BigDecimal $principal): void
    {
        $account = $loan->pledged_savings_account_id ? SavingsAccount::query()->find($loan->pledged_savings_account_id) : null;
        if (! $account || $account->member_id !== $member->id || $account->status !== 'active') {
            throw new PostingRejected('Pledge one of the member\'s own active savings accounts.');
        }
        // Before disbursement the lien is not yet placed, so the full free balance counts.
        $free = BigDecimal::of($account->balance)->minus($loan->status === 'disbursed' ? '0' : $account->hold_amount);
        $limit = $free->multipliedBy(self::SAVINGS_SECURED_PERCENT)->dividedBy(100, 2, RoundingMode::Down);
        if ($principal->isGreaterThan($limit)) {
            throw new PostingRejected("A savings-secured loan can be at most 90% of the pledged savings, Rs {$limit} (NCRA Std 3/2.1).");
        }
    }

    private function assertUnsecured(Member $member, BigDecimal $principal, array $guarantorIds): void
    {
        $regular = DB::table('savings_accounts as a')->join('savings_products as p', 'p.id', '=', 'a.savings_product_id')
            ->where('a.member_id', $member->id)->where('a.status', 'active')->where('p.type', 'regular')->where('a.balance', '>', 0)->exists();
        if (! $regular) {
            throw new PostingRejected('Unsecured loans are only for members who save regularly (an active regular savings account) (NCRA Std 3/1.3).');
        }
        $savings = BigDecimal::of((string) DB::table('savings_accounts')->where('member_id', $member->id)->where('status', 'active')->sum('balance'));
        $limit = BigDecimal::min($savings->multipliedBy(self::UNSECURED_SAVINGS_MULTIPLE), BigDecimal::of(self::UNSECURED_CAP))->toScale(2);
        if ($principal->isGreaterThan($limit)) {
            throw new PostingRejected("An unsecured loan can be at most the lower of 5 times the member's savings and Rs 3 lakh: Rs {$limit} (NCRA Std 3/1.3).");
        }
        $guarantors = Member::query()->whereIn('id', array_unique($guarantorIds))->where('status', 'active')->where('id', '<>', $member->id)->count();
        if ($guarantors < self::UNSECURED_GUARANTORS) {
            throw new PostingRejected('Unsecured loans need at least 2 active member guarantors other than the borrower (NCRA Std 3/1.3).');
        }
    }

    private function assertRealEstate(LoanProduct $product, Loan $loan, BigDecimal $principal): void
    {
        if (empty($loan->collateral_value) || ! BigDecimal::of($loan->collateral_value)->isPositive()) {
            throw new PostingRejected('Enter the valuation of the real-estate collateral.');
        }
        $limit = BigDecimal::of($loan->collateral_value)->multipliedBy($product->max_ltv_percent)->dividedBy(100, 2, RoundingMode::Down);
        if ($principal->isGreaterThan($limit)) {
            throw new PostingRejected("The loan can be at most {$product->max_ltv_percent}% of the collateral valuation, Rs {$limit}.");
        }
    }

    /** @return list<int> */
    private function guarantors(Loan $loan): array
    {
        return DB::table('loan_guarantors')->where('loan_id', $loan->id)->pluck('guarantor_member_id')->map(fn ($id) => (int) $id)->all();
    }
}
