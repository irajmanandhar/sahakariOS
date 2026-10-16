<?php

namespace App\Loans;

use App\Accounting\PostingRejected;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Repayment plan. The plan fixes principal due dates and amounts; interest
 * is always charged on the actual outstanding balance per day (NCRA Std
 * 5/2.3), so "interest_projected" is an estimate shown to the member.
 * PROPOSED: due dates in AD calendar months from disbursement; during grace
 * months only interest is due; interest projection uses actual days / 365.
 */
final class ScheduleBuilder
{
    /** @return list<array{installment_number:int, due_date:string, principal_due:string, interest_projected:string}> */
    public static function build(string $principal, string $annualRate, int $termMonths, int $graceMonths, string $frequency, string $method, string $disbursedOn): array
    {
        $step = $frequency === 'quarterly' ? 3 : 1;
        if ($termMonths <= $graceMonths || ($termMonths - $graceMonths) % $step !== 0 || $graceMonths % $step !== 0) {
            throw new PostingRejected("Term and grace must be whole multiples of the repayment interval ({$step} month(s)), with repayments after the grace period.");
        }

        $p = BigDecimal::of($principal);
        $rate = BigDecimal::of($annualRate);
        $graceRows = intdiv($graceMonths, $step);
        $n = intdiv($termMonths - $graceMonths, $step);
        $start = CarbonImmutable::parse($disbursedOn);

        $periodic = $rate->multipliedBy($step)->dividedBy(1200, 12, RoundingMode::HalfUp);
        $emi = null;
        if ($method === 'equal_installment' && $periodic->isPositive()) {
            $growth = BigDecimal::one()->plus($periodic)->power($n);
            $emi = $p->multipliedBy($periodic)->multipliedBy($growth)->dividedBy($growth->minus(1), 2, RoundingMode::HalfUp);
        }

        $rows = [];
        $outstanding = $p;
        $previous = $start;
        for ($k = 1; $k <= $graceRows + $n; $k++) {
            $due = $start->addMonthsNoOverflow($k * $step);
            $days = (int) $previous->diffInDays($due);
            $interest = $outstanding->multipliedBy($rate)->multipliedBy($days)->dividedBy(36500, 2, RoundingMode::HalfUp);

            if ($k <= $graceRows) {
                $principalDue = BigDecimal::zero()->toScale(2);
            } elseif ($k === $graceRows + $n) {
                $principalDue = $outstanding; // last installment clears any rounding
            } elseif ($emi !== null) {
                $principalDue = $emi->minus($outstanding->multipliedBy($periodic)->toScale(2, RoundingMode::HalfUp));
            } else {
                $principalDue = $p->dividedBy($n, 2, RoundingMode::Down);
            }
            if ($principalDue->isGreaterThan($outstanding)) {
                $principalDue = $outstanding;
            }

            $rows[] = [
                'installment_number' => $k,
                'due_date' => $due->format('Y-m-d'),
                'principal_due' => (string) $principalDue->toScale(2),
                'interest_projected' => (string) $interest,
            ];
            $outstanding = $outstanding->minus($principalDue);
            $previous = $due;
        }

        return $rows;
    }
}
