<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant for cooperative-scoped routes.
 *
 * The cooperative comes only from the server-side session (chosen via
 * PUT /api/auth/cooperative) and is re-verified against the user's eligible
 * memberships on every request. Any cooperative_id/branch_id in the request
 * itself is ignored. Must run after `auth:web`.
 */
class EnsureTenantContext
{
    public const SESSION_KEY = 'tenant.cooperative_id';

    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $cooperativeId = $request->session()->get(self::SESSION_KEY);

        if (! $user instanceof User) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        if ($cooperativeId === null) {
            return response()->json(['message' => 'Select a cooperative first.'], 409);
        }

        $membership = $user->eligibleCooperatives()->where('cooperatives.id', $cooperativeId)->first(['cooperatives.id']);

        if ($membership === null) {
            $request->session()->forget(self::SESSION_KEY);

            return response()->json(['message' => 'You no longer have access to the selected cooperative.'], 403);
        }

        $this->tenant->set($membership->id, $membership->pivot->branch_id);

        try {
            return $next($request);
        } finally {
            // Never let one request's tenant leak into the next (long-running workers, tests).
            $this->tenant->clear();
        }
    }
}
