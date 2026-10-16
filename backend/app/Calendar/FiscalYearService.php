<?php

namespace App\Calendar;

use App\Audit\AuditLogger;
use App\Models\FiscalYear;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Fiscal years of the current cooperative. Closing a year belongs to the accounting engine (later). */
class FiscalYearService
{
    public function __construct(private BsCalendar $calendar, private TenantContext $tenant, private AuditLogger $audit) {}

    /** Open the fiscal year starting in Shrawan of $startBsYear, e.g. 2083 for 2083/84. */
    public function open(int $startBsYear): FiscalYear
    {
        $cooperativeId = $this->tenant->cooperativeId();
        $bounds = $this->calendar->fiscalYearBounds($startBsYear);

        if (FiscalYear::query()->where('start_bs_year', $startBsYear)->exists()) {
            throw new CalendarRuleViolation("Fiscal year {$startBsYear} is already open or closed.");
        }

        return DB::transaction(function () use ($cooperativeId, $startBsYear, $bounds) {
            $year = FiscalYear::query()->forceCreate([
                'cooperative_id' => $cooperativeId,
                'start_bs_year' => $startBsYear,
                'start_date' => $bounds['start']->format('Y-m-d'),
                'end_date' => $bounds['end']?->format('Y-m-d'),
                'status' => 'open',
            ]);
            $this->audit->record('fiscal_year.opened', $year, ['name' => $year->name()]);

            return $year;
        });
    }
}
