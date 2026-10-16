<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Cooperatives this staff user works for, with home branch and membership status. */
    public function cooperatives(): BelongsToMany
    {
        return $this->belongsToMany(Cooperative::class)
            ->withPivot(['branch_id', 'status'])
            ->withTimestamps();
    }

    /**
     * Checked at login and on every authenticated request. A staff user may
     * sign in only while they hold at least one eligible membership (below). A licence-suspended
     * cooperative still allows sign-in (records stay viewable); blocking
     * transactions there is a later, separate rule.
     *
     * Signing in proves identity only. It does not select or authorize a
     * cooperative; that is the tenant-context step.
     */
    public function canSignIn(): bool
    {
        return $this->eligibleCooperatives()->exists();
    }

    /**
     * Cooperatives this user may act for right now: active membership, active
     * home branch, cooperative not closed. Used for sign-in and for selecting
     * (and re-verifying) the tenant context.
     */
    public function eligibleCooperatives(): BelongsToMany
    {
        return $this->cooperatives()
            ->wherePivot('status', 'active')
            ->whereIn('cooperatives.status', ['active', 'licence_suspended'])
            ->join('branches', 'branches.id', '=', 'cooperative_user.branch_id')
            ->where('branches.status', 'active');
    }
}
