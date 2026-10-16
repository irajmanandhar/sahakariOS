<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Created only through App\Calendar\FiscalYearService. */
class FiscalYear extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['start_date' => 'immutable_date', 'end_date' => 'immutable_date'];
    }

    /** "2083/84" */
    public function name(): string
    {
        return sprintf('%d/%02d', $this->start_bs_year, ($this->start_bs_year + 1) % 100);
    }
}
