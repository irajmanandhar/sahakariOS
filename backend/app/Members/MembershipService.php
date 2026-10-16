<?php

namespace App\Members;

use App\Audit\AuditLogger;
use App\Authorization\Authorizer;
use App\Authorization\Permission;
use App\Calendar\BusinessDayService;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\MemberKycVersion;
use App\Models\User;
use App\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Membership and KYM (Know Your Member) for natural persons.
 * Law: Cooperatives Act 2074 s.30-32. AML/CFT: NCRA Instructions 2082
 * (Instruction numbers in comments). Rules marked PROPOSED are system controls.
 */
class MembershipService
{
    /** Act s.30: at least 16 years old. */
    public const MINIMUM_AGE = 16;

    /** Act s.31: the committee decides within 35 days. */
    public const DECISION_DAYS = 35;

    /** Instruction 4(1): re-verify at least every 1 / 2 / 3 years. */
    public const REVIEW_YEARS = ['high' => 1, 'medium' => 2, 'low' => 3];

    public const DOCUMENT_MIMES = ['image/jpeg', 'image/png', 'application/pdf'];

    public const DOCUMENT_MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private TenantContext $tenant, private AuditLogger $audit, private Authorizer $authorizer) {}

    /**
     * @param  array  $core  full_name, full_name_ne?, date_of_birth, gender, phone?, email?, three declarations
     * @param  array  $kym  validated Schedule-1 form (KymForm::rules)
     */
    public function apply(array $core, array $kym, ?User $by = null): Member
    {
        $today = BusinessDayService::today();
        $dob = new DateTimeImmutable($core['date_of_birth']);
        if ($dob->modify('+'.self::MINIMUM_AGE.' years')->format('Y-m-d') > $today) {
            throw new MembershipRuleViolation('Applicant must be at least '.self::MINIMUM_AGE.' years old (Cooperatives Act s.30).');
        }
        foreach ([
            'declares_nepali_citizen' => 'Membership is open to Nepali citizens only (Act s.30).',
            'declares_resides_in_working_area' => 'Applicant must reside in the cooperative\'s working area (Act s.30).',
            'declares_no_other_same_type_membership' => 'A person may not be a member of more than one cooperative of the same type (Act s.32).',
        ] as $field => $message) {
            if (($core[$field] ?? false) !== true) {
                throw new MembershipRuleViolation($message);
            }
        }
        $this->requireIdentityNumber($kym);

        return DB::transaction(function () use ($core, $kym, $by, $today) {
            $member = Member::query()->forceCreate([
                'cooperative_id' => $this->tenant->cooperativeId(),
                'branch_id' => $this->tenant->branchId(),
                'status' => 'applied',
                'full_name' => $core['full_name'],
                'full_name_ne' => $core['full_name_ne'] ?? null,
                'date_of_birth' => $core['date_of_birth'],
                'gender' => $core['gender'],
                'phone' => $core['phone'] ?? null,
                'email' => $core['email'] ?? null,
                'declares_nepali_citizen' => true,
                'declares_resides_in_working_area' => true,
                'declares_no_other_same_type_membership' => true,
                'applied_on' => $today,
                'applied_by' => $by?->id,
            ]);
            $this->writeKyc($member, $kym, $by);
            $this->audit->record('member.applied', $member, [], $by);

            return $member->refresh();
        });
    }

    /** New KYM version (member reported a change, or periodic review). Counts as a KYM review. */
    public function updateKyc(Member $member, array $kym, ?User $by = null): Member
    {
        if (! in_array($member->status, ['applied', 'active'], true)) {
            throw new MembershipRuleViolation('KYM can only be updated for applicants and active members.');
        }
        $this->requireIdentityNumber($kym);

        return DB::transaction(function () use ($member, $kym, $by) {
            $member = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $this->writeKyc($member, $kym, $by);
            if ($member->status === 'active' && $member->risk_level !== null) {
                $this->scheduleReview($member, $member->risk_level);
                $member->save();
            }

            return $member->refresh();
        });
    }

    /** Instruction 7 / 3(1)(घ): risk level with reason; a PEP is always high risk. */
    public function assessRisk(Member $member, string $level, string $reason, User $by): Member
    {
        if (! array_key_exists($level, self::REVIEW_YEARS)) {
            throw new MembershipRuleViolation("Unknown risk level '{$level}'.");
        }
        if (trim($reason) === '') {
            throw new MembershipRuleViolation('A reason is required for the risk assessment.');
        }

        return DB::transaction(function () use ($member, $level, $reason, $by) {
            $member = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            if ($member->is_pep && $level !== 'high') {
                throw new MembershipRuleViolation('A politically exposed person (or family/associate) must be high risk (Instruction 3(1)(घ)).');
            }
            $previous = $member->risk_level;
            $member->forceFill(['risk_level' => $level, 'risk_reason' => trim($reason)]);
            if ($member->status === 'active') {
                $this->scheduleReview($member, $level);
            }
            $member->save();
            $this->audit->record('member.risk_assessed', $member, ['from' => $previous, 'to' => $level, 'reason' => trim($reason)], $by);

            return $member;
        });
    }

    public function uploadDocument(Member $member, string $type, UploadedFile $file, ?User $by = null): MemberDocument
    {
        if (! in_array($member->status, ['applied', 'active'], true)) {
            throw new MembershipRuleViolation('Documents can only be added for applicants and active members.');
        }
        $mime = $file->getMimeType();
        if (! in_array($mime, self::DOCUMENT_MIMES, true)) {
            throw new MembershipRuleViolation('Documents must be JPEG, PNG or PDF.');
        }
        if ($file->getSize() > self::DOCUMENT_MAX_BYTES) {
            throw new MembershipRuleViolation('Documents must be 5 MB or smaller.');
        }

        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime];
        // Random name; tenant prefix. The original name is metadata only, never part of the path.
        $path = "cooperatives/{$member->cooperative_id}/members/{$member->id}/".Str::uuid().".{$extension}";
        $sha256 = hash_file('sha256', $file->getRealPath());
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        try {
            return DB::transaction(function () use ($member, $type, $file, $by, $path, $mime, $sha256) {
                $document = MemberDocument::query()->forceCreate([
                    'cooperative_id' => $member->cooperative_id,
                    'member_id' => $member->id,
                    'type' => $type,
                    'path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => $mime,
                    'size_bytes' => $file->getSize(),
                    'sha256' => $sha256,
                    'uploaded_by' => $by?->id,
                ]);
                $this->audit->record('member.document_uploaded', $member, ['document_id' => $document->id, 'type' => $type, 'sha256' => $sha256], $by);

                return $document;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path); // no orphaned file if the record failed

            throw $e;
        }
    }

    public function approve(Member $member, User $by): Member
    {
        return $this->decide($member, $by, 'active', null);
    }

    public function reject(Member $member, User $by, string $reason): Member
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new MembershipRuleViolation('A reason is required to reject an application (Act s.31).');
        }

        return $this->decide($member, $by, 'rejected', $reason);
    }

    private function decide(Member $member, User $by, string $status, ?string $reason): Member
    {
        return DB::transaction(function () use ($member, $by, $status, $reason) {
            $member = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();

            if ($member->status !== 'applied') {
                throw new MembershipRuleViolation('This application has already been decided.');
            }
            // PROPOSED segregation of duties.
            if ($member->applied_by !== null && $member->applied_by === $by->id) {
                throw new MembershipRuleViolation('The person who entered an application cannot also decide it.');
            }

            $number = null;
            if ($status === 'active') {
                $this->assertReadyForApproval($member, $by);
                $number = $this->nextMemberNumber();
                $this->scheduleReview($member, $member->risk_level);
            }

            $member->forceFill([
                'status' => $status,
                'member_number' => $number,
                'decided_at' => now(),
                'decided_by' => $by->id,
                'decision_reason' => $reason,
            ])->save();
            $this->audit->record($status === 'active' ? 'member.approved' : 'member.rejected', $member, array_filter([
                'member_number' => $number, 'reason' => $reason,
            ]), $by);

            return $member;
        });
    }

    private function assertReadyForApproval(Member $member, User $by): void
    {
        $types = $member->documents()->pluck('type')->all();
        $missing = array_diff(KymForm::REQUIRED_DOCUMENTS, $types);
        if ($missing !== []) {
            throw new MembershipRuleViolation('Missing required documents: '.implode(', ', $missing).' (Instruction 1(1)(ग), 1(4)).');
        }
        if (array_intersect(KymForm::ID_DOCUMENTS, $types) === []) {
            throw new MembershipRuleViolation('An identity document copy is required (citizenship, national ID, voter ID, passport, driving licence or PAN).');
        }
        if ($member->risk_level === null) {
            throw new MembershipRuleViolation('Assess the member\'s AML/CFT risk level before approval.');
        }
        // Instruction 3(3): higher management approval for PEP / high-risk relationships.
        if (($member->is_pep || $member->risk_level === 'high') && ! $this->authorizer->allows($by, Permission::MemberDecideHighRisk)) {
            throw new MembershipRuleViolation('High-risk or PEP applications need approval by someone with high-risk approval authority (Instruction 3(3)).');
        }
        // Schedule-1 ञ: two existing members recommend the applicant. PROPOSED: enforced once the
        // cooperative has at least two active members (founders cannot be recommended).
        $active = Member::query()->where('status', 'active')->count();
        if ($active >= 2) {
            $numbers = $member->latestKyc->payload['recommender_member_numbers'] ?? [];
            $valid = Member::query()->where('status', 'active')->whereIn('member_number', $numbers)->count();
            if (count(array_unique($numbers)) < 2 || $valid < 2) {
                throw new MembershipRuleViolation('Two existing active members must recommend the applicant (KYM form section ञ).');
            }
        }
    }

    private function writeKyc(Member $member, array $kym, ?User $by): void
    {
        $citizenship = BlindIndex::of('citizenship', $kym['citizenship']['number'] ?? null, $kym['citizenship']['issue_district'] ?? '');
        $nationalId = BlindIndex::of('national_id', $kym['national_id']['number'] ?? null);
        $passport = BlindIndex::of('passport', $kym['passport_number'] ?? null);

        foreach (['citizenship_hash' => [$citizenship, 'citizenship certificate'], 'national_id_hash' => [$nationalId, 'national ID'], 'passport_hash' => [$passport, 'passport']] as $column => [$hash, $label]) {
            if ($hash !== null && Member::query()->where($column, $hash)->whereKeyNot($member->id)->whereIn('status', ['applied', 'active'])->exists()) {
                throw new MembershipRuleViolation("A member or applicant with this {$label} number already exists in this cooperative.");
            }
        }

        $version = (int) MemberKycVersion::query()->where('member_id', $member->id)->max('version') + 1;
        MemberKycVersion::query()->forceCreate([
            'cooperative_id' => $member->cooperative_id,
            'member_id' => $member->id,
            'version' => $version,
            'payload' => $kym,
            'verified_in_person_at' => now(),
            'recorded_by' => $by?->id,
        ]);

        $isPep = (bool) ($kym['pep']['is_pep'] ?? false);
        $member->forceFill([
            'citizenship_hash' => $citizenship,
            'national_id_hash' => $nationalId,
            'passport_hash' => $passport,
            'is_pep' => $isPep,
            // Instruction 3(1)(घ): becoming a PEP makes the member high risk immediately.
            'risk_level' => $isPep ? 'high' : $member->risk_level,
            'risk_reason' => $isPep && $member->risk_level !== 'high' ? 'Politically exposed person (self-declared on KYM form)' : $member->risk_reason,
        ])->save();
        $this->audit->record('member.kyc_recorded', $member, ['version' => $version, 'is_pep' => $isPep], $by);
    }

    private function requireIdentityNumber(array $kym): void
    {
        if (empty($kym['citizenship']['number']) && empty($kym['national_id']['number']) && empty($kym['passport_number'])) {
            throw new MembershipRuleViolation('A citizenship certificate, national ID or passport number is required (Instruction 1).');
        }
    }

    private function scheduleReview(Member $member, string $level): void
    {
        $today = new DateTimeImmutable(BusinessDayService::today());
        $member->forceFill([
            'kyc_reviewed_on' => $today->format('Y-m-d'),
            'next_kyc_review_on' => $today->modify('+'.self::REVIEW_YEARS[$level].' years')->format('Y-m-d'),
        ]);
    }

    private function nextMemberNumber(): int
    {
        $cooperativeId = $this->tenant->cooperativeId();
        DB::insert('INSERT INTO member_sequences (cooperative_id, last_number) VALUES (?, 0) ON DUPLICATE KEY UPDATE last_number = last_number', [$cooperativeId]);
        $number = DB::selectOne('SELECT last_number FROM member_sequences WHERE cooperative_id = ? FOR UPDATE', [$cooperativeId])->last_number + 1;
        DB::update('UPDATE member_sequences SET last_number = ? WHERE cooperative_id = ?', [$number, $cooperativeId]);

        return $number;
    }

    /** Days left to decide (negative = overdue), for applications still pending. */
    public static function daysToDecide(Member $member): ?int
    {
        if ($member->status !== 'applied') {
            return null;
        }
        $deadline = $member->applied_on->modify('+'.self::DECISION_DAYS.' days');
        $today = new DateTimeImmutable(BusinessDayService::today());

        return (int) $today->diff($deadline)->format('%r%a');
    }
}
