<?php

namespace App\Http\Controllers;

use App\Accounting\LedgerAccountService;
use App\Models\LedgerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LedgerAccountController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => LedgerAccount::query()->orderBy('code')->get()->map($this->present(...))]);
    }

    public function store(Request $request, LedgerAccountService $accounts): JsonResponse
    {
        $v = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(LedgerAccountService::TYPES)],
            'is_postable' => ['required', 'boolean'],
            'parent_code' => ['nullable', 'string', 'max:32'],
        ]);

        $account = $accounts->create($v['code'], $v['name'], $v['type'], $v['is_postable'], $v['parent_code'] ?? null);

        return response()->json(['data' => $this->present($account)], 201);
    }

    private function present(LedgerAccount $a): array
    {
        return [
            'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
            'normal_side' => $a->normalSide(), 'is_postable' => $a->is_postable,
            'parent_id' => $a->parent_id, 'status' => $a->status,
        ];
    }
}
