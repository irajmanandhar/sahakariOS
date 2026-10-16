<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\JsonResponse;

/**
 * Branches of the current cooperative. Read-only. Isolation comes from the
 * BelongsToTenant scope: another cooperative's branch is simply not found.
 */
class BranchController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Branch::query()->orderBy('code')->get()->map($this->present(...))]);
    }

    public function show(Branch $branch): JsonResponse
    {
        return response()->json(['data' => $this->present($branch)]);
    }

    private function present(Branch $branch): array
    {
        return $branch->only(['id', 'code', 'name', 'is_head_office', 'status']);
    }
}
