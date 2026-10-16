<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Append-only (database triggers). The KYM payload is encrypted at rest. */
class MemberKycVersion extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'verified_in_person_at' => 'immutable_datetime'];
    }
}
