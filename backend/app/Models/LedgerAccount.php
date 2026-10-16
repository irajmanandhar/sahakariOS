<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Created through App\Accounting\LedgerAccountService. */
class LedgerAccount extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_postable' => 'boolean'];
    }

    /** Assets and expenses normally carry debit balances; the rest credit. */
    public function normalSide(): string
    {
        return in_array($this->type, ['asset', 'expense'], true) ? 'debit' : 'credit';
    }
}
