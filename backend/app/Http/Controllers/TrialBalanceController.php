<?php

namespace App\Http\Controllers;

use App\Accounting\TrialBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrialBalanceController
{
    public function show(Request $request, TrialBalance $trialBalance): JsonResponse
    {
        $v = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $trialBalance->compute($v['as_of'] ?? null)]);
    }
}
