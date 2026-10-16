<?php

namespace App\Loans;

use App\Models\Loan;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Interest and penalty due on a loan at a date (NCRA Std 5/2.3: daily, on the
 * declining balance; Act s.50(5): never added to principal).
 * PROPOSED: actual days / 365; interest for (interest_paid_through, date];
 * "overdue interest" = the part for days up to the latest due date already
 * passed; penalty = product % (<= 5) of that overdue interest (Act s.50(5क)).
 */
final class LoanInterest
{
    /** @return array{interest: BigDecimal, overdue_interest: BigDecimal, penalty: BigDecimal, days: int} */
    public static function due(Loan $loan, string $date): array
    {
        $from = CarbonImmutable::parse($loan->interest_paid_through ?? $loan->disbursed_on);
        $to = CarbonImmutable::parse($date);
        $days = max(0, (int) $from->diffInDays($to, false));
        $outstanding = BigDecimal::of($loan->outstanding_principal);
        $interest = self::interestFor($outstanding, $loan->interest_rate, $days);

        $lastPassedDue = DB::table('loan_schedules')->where('loan_id', $loan->id)
            ->where('due_date', '>', $from->format('Y-m-d'))->where('due_date', '<', $date)->max('due_date');
        $overdueDays = $lastPassedDue ? (int) $from->diffInDays(CarbonImmutable::parse($lastPassedDue)) : 0;
        $overdue = self::interestFor($outstanding, $loan->interest_rate, $overdueDays);
        $penalty = $overdue->multipliedBy($loan->penalty_percent)->dividedBy(100, 2, RoundingMode::HalfUp);

        return ['interest' => $interest, 'overdue_interest' => $overdue, 'penalty' => $penalty, 'days' => $days];
    }

    private static function interestFor(BigDecimal $outstanding, string $rate, int $days): BigDecimal
    {
        return $outstanding->multipliedBy($rate)->multipliedBy($days)->dividedBy(36500, 2, RoundingMode::HalfUp);
    }
}
