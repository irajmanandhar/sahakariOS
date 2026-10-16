<?php

namespace App\Members;

use App\Accounting\Money;
use Illuminate\Validation\Rule;

/**
 * Know-Your-Member form for natural persons: NCRA AML/CFT Instructions 2082,
 * Schedule-1 (sections क-ञ). Field names follow the form's order.
 * "required" choices beyond the form's own wording are PROPOSED.
 */
final class KymForm
{
    public const OCCUPATIONS = ['agriculture', 'business', 'job', 'foreign_employment', 'other'];

    public const INCOME_BANDS = ['up_to_4_lakh', '4_to_10_lakh', '10_to_25_lakh', '25_to_50_lakh', 'above_50_lakh'];

    /** Instruction 1, Schedule-1 झ: at least one of these identity documents. */
    public const ID_DOCUMENTS = ['citizenship', 'national_id', 'voter_id', 'passport', 'driving_licence', 'pan'];

    /** Documents required before a member can be approved (Instruction 1(1)(ग), 1(4), Schedule-1 ज/झ). */
    public const REQUIRED_DOCUMENTS = ['photo', 'thumbprint_right', 'thumbprint_left'];

    public static function rules(string $p = 'kym.'): array
    {
        $money = ['nullable', 'string', 'regex:'.Money::PATTERN];
        $address = fn (string $key, bool $required) => [
            "{$p}{$key}" => [$required ? 'required' : 'nullable', 'array'],
            "{$p}{$key}.province" => [$required ? 'required' : "required_with:{$p}{$key}", 'string', 'max:50'],
            "{$p}{$key}.local_level" => [$required ? 'required' : "required_with:{$p}{$key}", 'string', 'max:100'],
            "{$p}{$key}.ward" => [$required ? 'required' : "required_with:{$p}{$key}", 'integer', 'between:1,40'],
            "{$p}{$key}.tole" => ['nullable', 'string', 'max:100'],
            "{$p}{$key}.house_number" => ['nullable', 'string', 'max:30'],
        ];

        return [
            'kym' => ['required', 'array'],
            // क. Personal
            "{$p}citizenship" => ['nullable', 'array'],
            "{$p}citizenship.number" => ['required_with:'.$p.'citizenship', 'string', 'max:50'],
            "{$p}citizenship.issue_district" => ['required_with:'.$p.'citizenship', 'string', 'max:50'],
            "{$p}citizenship.issue_date" => ['required_with:'.$p.'citizenship', 'date_format:Y-m-d', 'before_or_equal:today'],
            "{$p}national_id" => ['nullable', 'array'],
            "{$p}national_id.number" => ['required_with:'.$p.'national_id', 'string', 'max:50'],
            "{$p}national_id.issue_date" => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            // ख. Family
            "{$p}mother_name" => ['required', 'string', 'max:150'],
            "{$p}father_name" => ['required', 'string', 'max:150'],
            "{$p}marital_status" => ['required', Rule::in(['married', 'unmarried', 'single'])],
            "{$p}spouse_name" => ['required_if:'.$p.'marital_status,married', 'nullable', 'string', 'max:150'],
            "{$p}family_type" => ['required', Rule::in(['joint_one_kitchen', 'joint_separate_kitchen', 'nuclear'])],
            // ग. Occupation
            "{$p}occupation.type" => ['required', Rule::in(self::OCCUPATIONS)],
            "{$p}occupation.detail" => ['required_unless:'.$p.'occupation.type,agriculture', 'nullable', 'string', 'max:150'],
            "{$p}pan_number" => ['nullable', 'string', 'max:20'],
            "{$p}spouse_occupation.type" => ['nullable', Rule::in(self::OCCUPATIONS)],
            "{$p}spouse_occupation.detail" => ['nullable', 'string', 'max:150'],
            "{$p}other_earner.relation" => ['nullable', 'string', 'max:50'],
            "{$p}other_earner.occupation" => ['nullable', 'string', 'max:150'],
            "{$p}pep.is_pep" => ['required', 'boolean'],
            "{$p}pep.name" => ['required_if_accepted:'.$p.'pep.is_pep', 'nullable', 'string', 'max:150'],
            "{$p}pep.relation" => ['required_if_accepted:'.$p.'pep.is_pep', 'nullable', 'string', 'max:50'],
            "{$p}pep.position" => ['required_if_accepted:'.$p.'pep.is_pep', 'nullable', 'string', 'max:150'],
            // घ. Residence
            ...$address('permanent_address', true),
            ...$address('temporary_address', false),
            "{$p}working_area_residence" => ['required', Rule::in(['permanent', 'temporary'])],
            "{$p}voter_id_number" => ['nullable', 'string', 'max:50'],
            "{$p}polling_station" => ['nullable', 'string', 'max:150'],
            "{$p}years_in_working_area" => ['nullable', 'integer', 'between:0,120'],
            "{$p}passport_number" => ['nullable', 'string', 'max:30'],
            // ङ. Cooperative membership
            "{$p}membership_purpose" => ['required', 'string', 'max:300'],
            "{$p}other_cooperative_memberships" => ['nullable', 'array', 'max:20'],
            "{$p}other_cooperative_memberships.*.cooperative" => ['required', 'string', 'max:150'],
            "{$p}other_cooperative_memberships.*.membership_number" => ['nullable', 'string', 'max:30'],
            "{$p}other_cooperative_memberships.*.purpose" => ['required', 'string', 'max:300'],
            "{$p}family_other_memberships" => ['nullable', 'array', 'max:20'],
            "{$p}family_other_memberships.*.name" => ['required', 'string', 'max:150'],
            "{$p}family_other_memberships.*.relation" => ['required', 'string', 'max:50'],
            "{$p}family_other_memberships.*.cooperative" => ['required', 'string', 'max:150'],
            "{$p}family_members_here" => ['nullable', 'array', 'max:20'],
            "{$p}family_members_here.*.name" => ['required', 'string', 'max:150'],
            "{$p}family_members_here.*.membership_number" => ['nullable', 'integer'],
            // च. Income
            "{$p}annual_family_income_band" => ['required', Rule::in(self::INCOME_BANDS)],
            ...collect(['agriculture', 'business', 'domestic_employment', 'foreign_employment', 'other'])
                ->mapWithKeys(fn ($s) => ["{$p}income_by_source.{$s}" => $money])->all(),
            // छ. Expected transactions
            "{$p}initial_deposits.share" => $money,
            "{$p}initial_deposits.savings" => $money,
            "{$p}initial_deposits.other" => $money,
            "{$p}expected_transactions_per_year" => ['nullable', 'integer', 'min:0'],
            "{$p}expected_annual_deposit" => $money,
            "{$p}expected_loan_amount" => $money,
            "{$p}notes" => ['nullable', 'string', 'max:1000'],
            // ज. Self-declaration; ञ. Recommendation by existing members
            "{$p}self_declaration_accepted" => ['accepted'],
            "{$p}recommender_member_numbers" => ['nullable', 'array', 'max:2'],
            "{$p}recommender_member_numbers.*" => ['integer', 'distinct'],
        ];
    }
}
