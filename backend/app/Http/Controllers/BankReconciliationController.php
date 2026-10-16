<?php

namespace App\Http\Controllers;

use App\Banking\BankReconciliation;
use App\Calendar\BusinessDayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankReconciliationController
{
    public function import(Request $request, string $code, BankReconciliation $bank): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        return response()->json(['data' => $bank->import($bank->bankAccount($code), (string) file_get_contents($request->file('file')->getRealPath()), $request->user())], 201);
    }

    public function show(Request $request, string $code, BankReconciliation $bank): JsonResponse
    {
        $v = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d'], 'statement_balance' => ['required', 'string', 'regex:/^-?\d{1,16}(\.\d{1,2})?$/']]);

        return response()->json(['data' => $bank->report($bank->bankAccount($code), $v['as_of'] ?? BusinessDayService::today(), $v['statement_balance'])]);
    }
}
