<?php

namespace App\Loans;

use App\Accounting\LedgerMappings;
use App\Accounting\Money;
use App\Accounting\PostingRejected;
use App\Accounting\PostingService;
use App\Audit\AuditLogger;
use App\Calendar\BusinessDayService;
use App\Compliance\CashMovements;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Notifications\SmsOutbox;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Loan repayment. PROPOSED (accountant to confirm):
 * - allocation: penalty, then interest, then principal; a payment must cover
 *   the penalty and interest due in full;
 * - interest and penalty are income when received (cash basis, consistent
 *   with NCRA directives D1/D2): Dr cash or savings / Cr loans receivable
 *   (principal), Cr loan interest income, Cr penalty income;
 * - principal settles the oldest unpaid instalments first;
 * - the savings lien is released when the loan is fully repaid.
 */
class LoanRepaymentService
{
    public function __construct(
        private PostingService $posting,
        private LedgerMappings $mappings,
        private CashMovements $cash,
        private AuditLogger $audit,
    ) {}

    /** Amounts due today: what the screen shows before a repayment. */
    public function quote(Loan $loan): array
    {
        $date = $this->businessDate();
        $due = LoanInterest::due($loan, $date);
        $principalOverdue = DB::table('loan_schedules')->where('loan_id', $loan->id)->where('due_date', '<=', $date)
            ->selectRaw('COALESCE(SUM(principal_due - principal_paid), 0) AS d')->value('d');

        return [
            'date' => $date,
            'penalty' => Money::format($due['penalty']),
            'interest' => Money::format($due['interest']),
            'principal_overdue' => Money::format($principalOverdue),
            'outstanding_principal' => Money::format($loan->outstanding_principal),
            'minimum_payment' => Money::format($due['penalty']->plus($due['interest'])),
            'payoff' => Money::format($due['penalty']->plus($due['interest'])->plus($loan->outstanding_principal)),
        ];
    }

    /** @param  'cash'|'savings'  $channel */
    public function repay(Loan $loan, string $amount, string $channel, ?SavingsAccount $from, ?User $by, string $key, ?string $sourceOfFunds = null): array
    {
        $value = Money::parse($amount);
        if (! $value->isPositive()) {
            throw new PostingRejected('The amount must be greater than zero.');
        }
        if ($channel === 'cash' && CashMovements::requiresSourceOfFunds($amount) && trim((string) $sourceOfFunds) === '') {
            throw new PostingRejected('Source of funds is required for repayments of Rs 10 lakh or more (AML Instruction 8).');
        }

        return DB::transaction(function () use ($loan, $value, $channel, $from, $by, $key) {
            if ($existing = JournalEntry::query()->where('idempotency_key', "loan-repay:{$key}")->first()) {
                $row = DB::table('loan_transactions')->where('journal_entry_id', $existing->id)->first();
                if (! $row || (int) $row->loan_id !== $loan->id || ! $value->isEqualTo($row->amount)) {
                    throw new PostingRejected('This Idempotency-Key was already used for a different transaction.');
                }

                return (array) $row;
            }

            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->with(['product', 'member'])->firstOrFail();
            if ($loan->status !== 'disbursed') {
                throw new PostingRejected('Only disbursed, open loans can be repaid.');
            }
            $date = $this->businessDate();
            $due = LoanInterest::due($loan, $date);
            $charges = $due['penalty']->plus($due['interest']);
            if ($value->isLessThan($charges)) {
                throw new PostingRejected("The payment must at least cover penalty and interest due: Rs {$charges}.");
            }
            $principal = $value->minus($charges);
            if ($principal->isGreaterThan($loan->outstanding_principal)) {
                throw new PostingRejected('The payment is more than the amount needed to close the loan: Rs '.$charges->plus($loan->outstanding_principal).'.');
            }

            $source = $this->debitSide($channel, $from, $loan, $value);
            $lines = [['ledger_account_id' => $source, 'debit' => (string) $value]];
            if ($principal->isPositive()) {
                $lines[] = ['ledger_account_id' => $loan->product->ledger_account_id, 'credit' => (string) $principal];
            }
            if ($due['interest']->isPositive()) {
                $lines[] = ['ledger_account_id' => $this->mappings->account('loan_interest_income')->id, 'credit' => (string) $due['interest']];
            }
            if ($due['penalty']->isPositive()) {
                $lines[] = ['ledger_account_id' => $this->mappings->account('loan_penalty_income')->id, 'credit' => (string) $due['penalty']];
            }
            $entry = $this->posting->post("Loan {$loan->loan_number} repayment ({$channel})", $lines, $by, "loan-repay:{$key}", ['type' => 'loan', 'id' => $loan->id]);

            $outstanding = BigDecimal::of($loan->outstanding_principal)->minus($principal);
            $this->settleInstalments($loan, $principal);
            $closed = $outstanding->isZero();
            $loan->forceFill([
                'outstanding_principal' => (string) $outstanding,
                'interest_paid_through' => $date,
                'status' => $closed ? 'closed' : 'disbursed',
                'closed_on' => $closed ? $date : null,
            ])->save();
            if ($closed && $loan->pledged_savings_account_id) {
                $hold = BigDecimal::of($loan->principal)->multipliedBy(100)->dividedBy(LoanService::SAVINGS_SECURED_PERCENT, 2, RoundingMode::Up);
                DB::table('savings_accounts')->where('id', $loan->pledged_savings_account_id)->decrement('hold_amount', (string) $hold);
            }

            $id = DB::table('loan_transactions')->insertGetId([
                'cooperative_id' => $loan->cooperative_id, 'loan_id' => $loan->id, 'type' => 'repayment', 'amount' => (string) $value,
                'principal' => (string) $principal, 'interest' => (string) $due['interest'], 'penalty' => (string) $due['penalty'],
                'outstanding_after' => (string) $outstanding, 'interest_to' => $date, 'journal_entry_id' => $entry->id,
                'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->recordSource($channel, $from, $loan, $entry, $value, $id, $by);
            app(SmsOutbox::class)->queue($loan->member, sprintf('%s received for loan %s on %s. Outstanding principal %s.',
                SmsOutbox::rs((string) $value), $loan->loan_number, $date, SmsOutbox::rs((string) $outstanding)));
            $this->audit->record($closed ? 'loan.closed' : 'loan.repaid', $loan, [
                'amount' => (string) $value, 'principal' => (string) $principal, 'interest' => (string) $due['interest'], 'penalty' => (string) $due['penalty'],
            ], $by);

            return (array) DB::table('loan_transactions')->find($id);
        });
    }

    private function debitSide(string $channel, ?SavingsAccount $from, Loan $loan, BigDecimal $value): int
    {
        if ($channel === 'cash') {
            return $this->mappings->account('cash')->id;
        }
        $account = $from ? SavingsAccount::query()->whereKey($from->id)->lockForUpdate()->with('product')->first() : null;
        if (! $account || $account->member_id !== $loan->member_id || $account->status !== 'active') {
            throw new PostingRejected('Repay from one of the borrower\'s own active savings accounts.');
        }
        $available = BigDecimal::of($account->balance)->minus($account->hold_amount)->minus($account->product->minimum_balance);
        if ($value->isGreaterThan($available)) {
            throw new PostingRejected('Insufficient available savings: Rs '.Money::format($available->isNegative() ? '0' : $available).'.');
        }

        return $account->product->ledger_account_id;
    }

    private function recordSource(string $channel, ?SavingsAccount $from, Loan $loan, JournalEntry $entry, BigDecimal $value, int $txId, ?User $by): void
    {
        if ($channel === 'cash') {
            $this->cash->record($loan->member, $entry, 'in', (string) $value, 'loan_repayment', $txId);

            return;
        }
        $account = SavingsAccount::query()->find($from->id);
        $balance = BigDecimal::of($account->balance)->minus($value);
        $account->forceFill(['balance' => (string) $balance])->save();
        DB::table('savings_transactions')->insert([
            'cooperative_id' => $account->cooperative_id, 'savings_account_id' => $account->id, 'type' => 'loan_repayment',
            'channel' => 'transfer', 'amount' => (string) $value, 'balance_after' => (string) $balance, 'journal_entry_id' => $entry->id,
            'narration' => "Loan {$loan->loan_number} repayment", 'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Principal settles the oldest unpaid instalments first. */
    private function settleInstalments(Loan $loan, BigDecimal $principal): void
    {
        $left = $principal;
        foreach (DB::table('loan_schedules')->where('loan_id', $loan->id)->whereColumn('principal_paid', '<', 'principal_due')->orderBy('installment_number')->lockForUpdate()->get() as $row) {
            if (! $left->isPositive()) {
                break;
            }
            $take = BigDecimal::min($left, BigDecimal::of($row->principal_due)->minus($row->principal_paid));
            DB::table('loan_schedules')->where('id', $row->id)->update(['principal_paid' => (string) BigDecimal::of($row->principal_paid)->plus($take), 'updated_at' => now()]);
            $left = $left->minus($take);
        }
    }

    private function businessDate(): string
    {
        return app(BusinessDayService::class)->current()?->business_date->format('Y-m-d')
            ?? throw new PostingRejected('No business day is open. Open the business day before posting.');
    }
}
