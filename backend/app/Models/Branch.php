<?php

namespace App\Models;

use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// cooperative_id is deliberately not fillable: the tenant is set through the
// relationship ($cooperative->branches()->create(...)), never from request input.
#[Fillable(['code', 'name', 'is_head_office', 'status'])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_head_office' => 'boolean'];
    }

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }
}
