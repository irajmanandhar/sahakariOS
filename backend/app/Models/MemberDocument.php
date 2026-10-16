<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Append-only (database triggers). Files live on the private disk only. */
class MemberDocument extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];
}
