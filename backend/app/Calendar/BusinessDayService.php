<?php

namespace App\Calendar;

use App\Audit\AuditLogger;
use App\Loans\LoanClassifier;
use App\Models\BusinessDay;
use App\Models\Cooperative;
use App\Models\FiscalYear;
use App\Models\User;
use App\Savings\SavingsInterestService;
use App\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * The cooperative's business date: the date financial transactions will be
 * posted to. Rules (PROPOSED, to confirm with the customer):
 * - at most one open business day per cooperative (also a DB constraint);
 * - a new day must be later than every previous day and not in the future
 *   (Nepal time);
 * - the day must fall inside an open fiscal year and the verified calendar.
 * Closing a day runs end-of-day processing first (savings interest, loan
 * classification, and provisioning at BS month ends).
 */
class BusinessDayService
{
    public const TIMEZONE = 'Asia/Kathmandu';

    public function __construct(private BsCalendar $calendar, private TenantContext $tenant, private AuditLogger $audit) {}

    public function current(): ?BusinessDay
    {
        return BusinessDay::query()->where('status', 'open')->first();
    }

    public function open(DateTimeInterface|string $date, ?User $by = null): BusinessDay
    {
        $date = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date;
        $this->calendar->toBs($date); // throws outside the verified calendar

        return DB::transaction(function () use ($date, $by) {
            $this->lockCooperative();

            if ($this->current()) {
                throw new CalendarRuleViolation('Close the current business day before opening another.');
            }
            if ($date > self::today()) {
                throw new CalendarRuleViolation('A business day cannot be opened in the future.');
            }
            $last = BusinessDay::query()->max('business_date');
            if ($last !== null && $date <= $last) {
                throw new CalendarRuleViolation("The business date must be after {$last}.");
            }

            $fiscalYear = FiscalYear::query()
                ->where('status', 'open')
                ->where('start_date', '<=', $date)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date))
                ->orderByDesc('start_date')
                ->first()
                ?? throw new CalendarRuleViolation('No open fiscal year covers this date.');

            $day = BusinessDay::query()->forceCreate([
                'cooperative_id' => $this->tenant->cooperativeId(),
                'fiscal_year_id' => $fiscalYear->id,
                'business_date' => $date,
                'status' => 'open',
                'opened_by' => $by?->id,
                'opened_at' => now(),
            ]);
            $this->audit->record('business_day.opened', $day, ['business_date' => $date], $by);

            return $day;
        });
    }

    public function close(?User $by = null): BusinessDay
    {
        return DB::transaction(function () use ($by) {
            $this->lockCooperative();

            $day = $this->current() ?? throw new CalendarRuleViolation('There is no open business day.');

            // End-of-day processing runs while the day is still open, so its postings
            // land on this business date; it commits or rolls back with the close.
            $date = $day->business_date->format('Y-m-d');
            app(SavingsInterestService::class)->runForDate($date);
            // Loans re-classified daily; provision posted at each BS month end (PROPOSED frequency).
            $bs = $this->calendar->toBs($date);
            app(LoanClassifier::class)->run($date, $bs->day === $this->calendar->daysInMonth($bs->year, $bs->month));

            $day->forceFill(['status' => 'closed', 'closed_by' => $by?->id, 'closed_at' => now()])->save();
            $this->audit->record('business_day.closed', $day, ['business_date' => $day->business_date->format('Y-m-d')], $by);

            return $day;
        });
    }

    /** Today's date in Nepal, as Y-m-d. */
    public static function today(): string
    {
        return (new DateTimeImmutable('@'.now()->getTimestamp()))
            ->setTimezone(new DateTimeZone(self::TIMEZONE))
            ->format('Y-m-d');
    }

    /** Serialises day open/close per cooperative (row lock held until commit). */
    private function lockCooperative(): void
    {
        Cooperative::query()->whereKey($this->tenant->cooperativeId())->lockForUpdate()->firstOrFail();
    }
}
