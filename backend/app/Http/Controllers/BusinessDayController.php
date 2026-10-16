<?php

namespace App\Http\Controllers;

use App\Calendar\BsCalendar;
use App\Calendar\BusinessDayService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only for now. Opening and closing a day need role permissions, which
 * do not exist yet; until then BusinessDayService is used from code only.
 */
class BusinessDayController
{
    public function show(BusinessDayService $days, BsCalendar $calendar): JsonResponse
    {
        $day = $days->current()?->load('fiscalYear');

        if ($day === null) {
            return response()->json(['data' => null]);
        }

        $bs = $calendar->toBs($day->business_date);

        return response()->json(['data' => [
            'date' => $day->business_date->format('Y-m-d'),
            'date_bs' => (string) $bs,
            'date_bs_text' => "{$bs->day} {$bs->monthName()} {$bs->year}",
            'fiscal_year' => $day->fiscalYear->name(),
            'status' => $day->status,
        ]]);
    }
}
