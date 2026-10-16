<?php

namespace App\Models;

use Database\Factories\PlatformUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * SaaS operator staff. Not a cooperative user and has no cooperative relation.
 * Authenticates only through the `platform` guard.
 */
#[Fillable(['name', 'email', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class PlatformUser extends Authenticatable
{
    /** @use HasFactory<PlatformUserFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    /** Checked at login and on every authenticated request. */
    public function canSignIn(): bool
    {
        return $this->status === 'active';
    }
}
