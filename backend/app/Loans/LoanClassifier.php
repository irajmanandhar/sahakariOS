<?php

namespace App\Loans;

use App\Accounting\LedgerMappings;
use App\Accounting\Money;
use App\Accounting\PostingService;
use App\Audit\AuditLogger;
use App\Models\Loan;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Loan classification and loan-loss provisioning (NCRA regulatory standard
 * 2082 ch.3, points 3-4, register P-01..P-04):
 * - pass: not overdue, or overdue up to 3 months; loans secured by the
 *   member's savings are always pass;
 * - substandard: 3-6 months; doubtful: 6-12 months; loss: more than 12;
 * - the whole loan is classified by its oldest overdue instalment (principal
 *   or interest);
 * - minimum provision on outstanding principal: 1 / 25 / 50 / 100 %.
 * PROPOSED: provision changes are posted against the provision balance in
 * the ledger: Dr provision expense / Cr loan loss provision (or the reverse
 * for a write-back).
 */
class LoanClassifier
{
    public const PROVISION_PERCENT = ['pass' => 1, 'substandard' => 25, 'doubtful' => 50, 'loss' => 100];

    public function __construct(private TenantContext $tenant, private PostingService $posting, private LedgerMappings $mappings, private AuditLogger $audit) {}

    public static function classify(Loan $loan, string $date): string
    {
        if ($loan->product->collateral_type === 'savings') {
            return 'pass';
        }
        $oldest = self::oldestOverdueDate($loan, $date);
        if ($oldest === null) {
            return 'pass';
        }
        $since = CarbonImmutable::parse($oldest);
        $d = CarbonImmutable::parse($date);

        return match (true) {
            $since->addMonthsNoOverflow(3)->greaterThanOrEqualTo($d) => 'pass',
            $since->addMonthsNoOverflow(6)->greaterThanOrEqualTo($d) => 'substandard',
            $since->addMonthsNoOverflow(12)->greaterThanOrEqualTo($d) => 'doubtful',
            default => 'loss',
        };
    }

    /** Earliest due date (before $date) whose principal is unpaid or whose interest has not been paid through it. */
    public static function oldestOverdueDate(Loan $loan, string $date): ?string
    {
        $principal = DB::table('loan_schedules')->where('loan_id', $loan->id)->where('due_date', '<', $date)
            ->whereColumn('principal_paid', '<', 'principal_due')->min('due_date');
        $interest = DB::table('loan_schedules')->where('loan_id', $loan->id)->where('due_date', '<', $date)
            ->where('due_date', '>', $loan->interest_paid_through?->format('Y-m-d') ?? '0000-00-00')->min('due_date');

        return collect([$principal, $interest])->filter()->min();
    }

    /** Re-classify every open loan and post the provision change. Run inside a transaction on an open business day. */
    public function run(string $date, bool $postProvision = true): array
    {
        $required = Money::zero();
        $counts = [];
        Loan::query()->where('status', 'disbursed')->with('product')->lockForUpdate()->each(function (Loan $loan) use ($date, &$required, &$counts) {
            $class = self::classify($loan, $date);
            if ($class !== $loan->classification) {
                $this->audit->record('loan.classified', $loan, ['from' => $loan->classification, 'to' => $class]);
                $loan->forceFill(['classification' => $class])->save();
            }
            $required = $required->plus(BigDecimal::of($loan->outstanding_principal)->multipliedBy(self::PROVISION_PERCENT[$class])->dividedBy(100, 2, RoundingMode::HalfUp));
            $counts[$class] = ($counts[$class] ?? 0) + 1;
        });

        $hasMapping = DB::table('ledger_mappings')->where('cooperative_id', $this->tenant->cooperativeId())->where('key', 'loan_loss_provision')->exists();
        if ($counts === [] && ! $hasMapping) {
            return ['date' => $date, 'counts' => [], 'required_provision' => '0.00', 'previous_provision' => '0.00', 'change' => '0.00'];
        }
        $current = $this->provisionBalance();
        $change = $required->minus($current);
        if ($postProvision && ! $change->isZero()) {
            $provision = $this->mappings->account('loan_loss_provision')->id;
            $expense = $this->mappings->account('loan_loss_provision_expense')->id;
            $amount = (string) $change->abs();
            $this->posting->post(
                $change->isPositive() ? "Loan loss provision increase to Rs {$required} ({$date})" : "Loan loss provision write-back to Rs {$required} ({$date})",
                $change->isPositive()
                    ? [['ledger_account_id' => $expense, 'debit' => $amount], ['ledger_account_id' => $provision, 'credit' => $amount]]
                    : [['ledger_account_id' => $provision, 'debit' => $amount], ['ledger_account_id' => $expense, 'credit' => $amount]],
                null, "loan-provision:{$date}:{$required}", ['type' => 'loan_provision', 'id' => 0],
            );
        }

        return ['date' => $date, 'counts' => $counts, 'required_provision' => Money::format($required), 'previous_provision' => Money::format($current), 'change' => Money::format($change)];
    }

    /** Credit balance of the provision account. */
    public function provisionBalance(): BigDecimal
    {
        $id = $this->mappings->account('loan_loss_provision')->id;
        $row = DB::table('journal_lines')->where('cooperative_id', $this->tenant->cooperativeId())->where('ledger_account_id', $id)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS b')->first();

        return BigDecimal::of($row->b)->toScale(2);
    }
}
