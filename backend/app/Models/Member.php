<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Changed only through App\Members\MembershipService. */
class Member extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'applied_on' => 'immutable_date',
            'decided_at' => 'immutable_datetime',
            'declares_nepali_citizen' => 'boolean',
            'declares_resides_in_working_area' => 'boolean',
            'declares_no_other_same_type_membership' => 'boolean',
            'is_pep' => 'boolean',
            'kyc_reviewed_on' => 'immutable_date',
            'next_kyc_review_on' => 'immutable_date',
        ];
    }

    public function kycVersions(): HasMany
    {
        return $this->hasMany(MemberKycVersion::class)->orderBy('version');
    }

    public function latestKyc(): HasOne
    {
        return $this->hasOne(MemberKycVersion::class)->latestOfMany('version');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MemberDocument::class);
    }
}
