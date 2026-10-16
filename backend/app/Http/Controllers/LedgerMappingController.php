<?php

namespace App\Http\Controllers;

use App\Accounting\LedgerMappings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LedgerMappingController
{
    public function index(LedgerMappings $mappings): JsonResponse
    {
        $set = $mappings->all();

        return response()->json(['data' => collect(LedgerMappings::ROLES)->map(fn ($role, $key) => [
            'role' => $key, 'description' => $role[0], 'account_type' => $role[1], 'ledger_account_id' => $set[$key],
        ])->values()]);
    }

    public function update(Request $request, LedgerMappings $mappings): JsonResponse
    {
        $v = $request->validate([
            'role' => ['required', Rule::in(array_keys(LedgerMappings::ROLES))],
            'account_code' => ['required', 'string', 'max:32'],
        ]);
        $mappings->set($v['role'], $v['account_code']);

        return $this->index($mappings);
    }
}
