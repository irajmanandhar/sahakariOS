<?php

namespace App\Http\Controllers;

use App\Calendar\BusinessDayService;
use App\Compliance\CashMovements;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplianceController
{
    /** Threshold transactions to report to FIU-Nepal (goAML) within 15 days. */
    public function thresholdTransactions(Request $request, CashMovements $cash, TenantContext $tenant): JsonResponse
    {
        $today = BusinessDayService::today();
        $v = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return response()->json(['data' => $cash->thresholdTransactions(
            $tenant->cooperativeId(),
            $v['from'] ?? date('Y-m-d', strtotime("{$today} -30 days")),
            $v['to'] ?? $today,
        )]);
    }
}
