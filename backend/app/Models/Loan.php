<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Changed only through App\Loans services. */
class Loan extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'applied_on' => 'immutable_date', 'disbursed_on' => 'immutable_date', 'closed_on' => 'immutable_date',
            'interest_paid_through' => 'immutable_date', 'decided_at' => 'immutable_datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function schedule(): HasMany
    {
        return $this->hasMany(LoanSchedule::class)->orderBy('installment_number');
    }
}
