<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureTenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets a signed-in staff user see which cooperatives they may act for and
 * choose one. The choice lives in the server-side session only.
 */
class CurrentCooperativeController
{
    public function index(Request $request): JsonResponse
    {
        $memberships = $this->memberships($request->user())->orderBy('cooperatives.name')->get(self::COLUMNS);

        return response()->json(['data' => $memberships->map($this->present(...))->values()]);
    }

    public function show(Request $request): JsonResponse
    {
        $cooperativeId = $request->session()->get(EnsureTenantContext::SESSION_KEY);
        $membership = $cooperativeId === null ? null
            : $this->memberships($request->user())->where('cooperatives.id', $cooperativeId)->first(self::COLUMNS);

        return response()->json(['data' => $membership ? $this->present($membership) : null]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['cooperative_id' => ['required', 'integer']]);

        $membership = $this->memberships($request->user())
            ->where('cooperatives.id', $validated['cooperative_id'])
            ->first(self::COLUMNS);

        // Same answer for "does not exist" and "not yours": no probing of other tenants.
        if ($membership === null) {
            return response()->json(['message' => 'You do not have access to that cooperative.'], 403);
        }

        // Changing what the session may act for: rotate the session ID.
        $request->session()->migrate(true);
        $request->session()->put(EnsureTenantContext::SESSION_KEY, $membership->id);

        return response()->json(['data' => $this->present($membership)]);
    }

    private const COLUMNS = [
        'cooperatives.id', 'cooperatives.code', 'cooperatives.name', 'cooperatives.status',
        'branches.code as branch_code', 'branches.name as branch_name',
    ];

    private function memberships(User $user): BelongsToMany
    {
        return $user->eligibleCooperatives();
    }

    private function present($membership): array
    {
        return [
            'cooperative' => [
                'id' => $membership->id,
                'code' => $membership->code,
                'name' => $membership->name,
                'status' => $membership->status,
            ],
            'branch' => [
                'id' => $membership->pivot->branch_id,
                'code' => $membership->branch_code,
                'name' => $membership->branch_name,
            ],
        ];
    }
}
