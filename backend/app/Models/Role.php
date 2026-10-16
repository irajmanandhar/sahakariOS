<?php

namespace App\Models;

use App\Authorization\Permission;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** A cooperative's own role: a named set of permissions. No roles exist by default. */
class Role extends Model
{
    use BelongsToTenant;

    protected $fillable = ['key', 'name'];

    /** @param  list<Permission>  $permissions  replaces the role's permissions */
    public function syncPermissions(array $permissions): void
    {
        DB::transaction(function () use ($permissions) {
            DB::table('role_permissions')->where('role_id', $this->id)->delete();
            DB::table('role_permissions')->insert(array_map(fn (Permission $p) => [
                'cooperative_id' => $this->cooperative_id, 'role_id' => $this->id, 'permission' => $p->value,
                'created_at' => now(), 'updated_at' => now(),
            ], $permissions));
        });
    }

    /** Give this role to a member of the same cooperative (the database refuses anyone else). */
    public function assignTo(User $user): void
    {
        $key = ['cooperative_id' => $this->cooperative_id, 'user_id' => $user->id, 'role_id' => $this->id];

        // Plain INSERT on purpose: INSERT IGNORE would also silence the foreign-key
        // error for a user outside this cooperative instead of refusing it.
        if (! DB::table('cooperative_user_roles')->where($key)->exists()) {
            DB::table('cooperative_user_roles')->insert($key + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function removeFrom(User $user): void
    {
        DB::table('cooperative_user_roles')
            ->where(['cooperative_id' => $this->cooperative_id, 'user_id' => $user->id, 'role_id' => $this->id])
            ->delete();
    }
}
