<?php

namespace App\Http\Controllers;

use App\Calendar\BsCalendar;
use App\Calendar\BusinessDayService;
use App\Models\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Current business day of the selected cooperative; open/close need permissions (routes). */
class BusinessDayController
{
    public function show(BusinessDayService $days, BsCalendar $calendar): JsonResponse
    {
        $day = $days->current();

        return response()->json(['data' => $day ? $this->present($day, $calendar) : null]);
    }

    public function open(Request $request, BusinessDayService $days, BsCalendar $calendar): JsonResponse
    {
        $validated = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);

        $day = $days->open($validated['date'] ?? BusinessDayService::today(), $request->user());

        return response()->json(['data' => $this->present($day, $calendar)], 201);
    }

    public function close(Request $request, BusinessDayService $days, BsCalendar $calendar): JsonResponse
    {
        return response()->json(['data' => $this->present($days->close($request->user()), $calendar)]);
    }

    private function present(BusinessDay $day, BsCalendar $calendar): array
    {
        $day->loadMissing('fiscalYear');
        $bs = $calendar->toBs($day->business_date);

        return [
            'date' => $day->business_date->format('Y-m-d'),
            'date_bs' => (string) $bs,
            'date_bs_text' => "{$bs->day} {$bs->monthName()} {$bs->year}",
            'fiscal_year' => $day->fiscalYear->name(),
            'status' => $day->status,
        ];
    }
}
