<?php

namespace App\Models;

use Database\Factories\CooperativeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A tenant: one savings and credit cooperative. */
#[Fillable(['code', 'name', 'name_en', 'registration_number', 'status'])]
class Cooperative extends Model
{
    /** @use HasFactory<CooperativeFactory> */
    use HasFactory;

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['branch_id', 'status'])
            ->withTimestamps();
    }
}
