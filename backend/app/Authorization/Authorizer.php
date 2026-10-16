<?php

namespace App\Authorization;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Answers "may this staff user do X in the current cooperative?".
 * Permissions come only from roles assigned to the user's membership in the
 * cooperative of the tenant context. No tenant context = no permissions.
 * One small query per check, never cached, so revoking a role takes effect
 * on the very next request.
 */
class Authorizer
{
    public function __construct(private TenantContext $tenant) {}

    public function allows(User $user, Permission $permission): bool
    {
        return in_array($permission->value, $this->permissions($user), true);
    }

    /** @return list<string> */
    public function permissions(User $user): array
    {
        if (! $this->tenant->has()) {
            return [];
        }

        return DB::table('cooperative_user_roles as ur')
            ->join('role_permissions as rp', fn ($j) => $j->on('rp.role_id', '=', 'ur.role_id')->on('rp.cooperative_id', '=', 'ur.cooperative_id'))
            ->where('ur.cooperative_id', $this->tenant->cooperativeId())
            ->where('ur.user_id', $user->id)
            ->distinct()
            ->orderBy('rp.permission')
            ->pluck('rp.permission')
            ->all();
    }
}
