<?php

namespace Tests\Feature;

use App\Authorization\Permission;
use App\Members\MembershipService;
use App\Models\Branch;
use App\Models\Cooperative;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Membership + KYM (NCRA AML/CFT Instructions 2082, Schedule-1). Synthetic people only. */
class MemberTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private Branch $branch;

    /** @var array<string, User> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-10-16 04:15:00');
        Storage::fake('local');
        $this->branch = Branch::factory()->headOffice()->create();

        $this->staff['clerk'] = $this->staffWith('clerk', [Permission::MemberView, Permission::MemberApply]);
        $this->staff['officer'] = $this->staffWith('officer', [Permission::MemberView, Permission::MemberDecide, Permission::MemberRiskAssess, Permission::MemberKycView, Permission::MemberKycUpdate]);
        $this->staff['manager'] = $this->staffWith('manager', [Permission::MemberView, Permission::MemberDecide, Permission::MemberDecideHighRisk, Permission::MemberRiskAssess]);
    }

    private function staffWith(string $key, array $permissions): User
    {
        $user = User::factory()->create(['email' => "{$key}@example.test", 'password' => self::PASSWORD]);
        $this->branch->cooperative->users()->attach($user, ['branch_id' => $this->branch->id]);
        app(TenantContext::class)->runAs($this->branch->cooperative_id, $this->branch->id, function () use ($key, $permissions, $user) {
            $role = Role::create(['key' => $key, 'name' => ucfirst($key)]);
            $role->syncPermissions($permissions);
            $role->assignTo($user);
        });

        return $user;
    }

    private ?string $signedInAs = null;

    /** Sign in only when switching user (each login counts against the login rate limit). */
    private function as(string $who): self
    {
        if ($this->signedInAs === $who) {
            return $this;
        }
        $this->signedInAs = $who;
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => "{$who}@example.test", 'password' => self::PASSWORD])->assertOk();
        $this->putJson('/api/auth/cooperative', ['cooperative_id' => $this->branch->cooperative_id])->assertOk();

        return $this;
    }

    /** A real 1x1 PNG (content-sniffed as image/png), without needing the GD extension. */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAwS2OUAAAAABJRU5ErkJggg=='));
    }

    /** A complete synthetic Schedule-1 application. */
    private function application(array $overrides = [], array $kym = []): array
    {
        return array_replace_recursive([
            'full_name' => 'Sita Example',
            'date_of_birth' => '1990-05-01',
            'gender' => 'female',
            'phone' => '9800000000',
            'declares_nepali_citizen' => true,
            'declares_resides_in_working_area' => true,
            'declares_no_other_same_type_membership' => true,
            'kym' => [
                'citizenship' => ['number' => '27-01-70-00001', 'issue_district' => 'Lalitpur', 'issue_date' => '2010-01-15'],
                'mother_name' => 'Gita Example',
                'father_name' => 'Ram Example',
                'marital_status' => 'unmarried',
                'family_type' => 'nuclear',
                'occupation' => ['type' => 'job', 'detail' => 'Teacher'],
                'pep' => ['is_pep' => false],
                'permanent_address' => ['province' => 'Bagmati', 'local_level' => 'Lalitpur Metropolitan City', 'ward' => 5, 'tole' => 'Pulchowk'],
                'working_area_residence' => 'permanent',
                'membership_purpose' => 'Regular savings',
                'annual_family_income_band' => '4_to_10_lakh',
                'income_by_source' => ['domestic_employment' => '600000.00'],
                'self_declaration_accepted' => true,
            ],
        ], $overrides, $kym === [] ? [] : ['kym' => $kym]);
    }

    private function applyAs(string $who = 'clerk', array $overrides = [], array $kym = [])
    {
        return $this->as($who)->postJson('/api/members', $this->application($overrides, $kym));
    }

    private function uploadRequired(int $memberId): void
    {
        foreach (['photo', 'citizenship', 'thumbprint_right', 'thumbprint_left'] as $type) {
            $this->postJson("/api/members/{$memberId}/documents", ['type' => $type, 'file' => $this->png("{$type}.png")])->assertCreated();
        }
    }

    /** Apply as clerk, upload documents, assess risk and approve as officer. */
    private function activeMember(array $kym = [], string $risk = 'low'): int
    {
        $id = $this->applyAs('clerk', [], $kym)->assertCreated()->json('data.id');
        $this->uploadRequired($id);
        $this->as('officer')->postJson("/api/members/{$id}/risk", ['risk_level' => $risk, 'reason' => 'Salaried, local'])->assertOk();
        // High risk needs the high-risk approver (Instruction 3(3)).
        $this->as($risk === 'high' ? 'manager' : 'officer')->postJson("/api/members/{$id}/approve")->assertOk();

        return $id;
    }

    // --- application ------------------------------------------------------------

    public function test_application_records_member_and_encrypted_kym(): void
    {
        $response = $this->applyAs()->assertCreated()
            ->assertJsonPath('data.status', 'applied')
            ->assertJsonPath('data.member_number', null)
            ->assertJsonPath('data.date_of_birth_bs', '2047-01-18')
            ->assertJsonPath('data.days_to_decide', 35)
            ->assertJsonMissingPath('data.kym');

        $raw = DB::table('member_kyc_versions')->where('member_id', $response->json('data.id'))->value('payload');
        $this->assertStringNotContainsString('27-01-70-00001', $raw, 'KYM payload must be encrypted at rest');
        $this->assertStringNotContainsString('27-01-70-00001', DB::table('members')->get()->toJson());
    }

    public function test_act_eligibility_rules(): void
    {
        $this->applyAs('clerk', ['date_of_birth' => '2011-01-01'])->assertUnprocessable()
            ->assertJsonPath('message', 'Applicant must be at least 16 years old (Cooperatives Act s.30).');
        $this->applyAs('clerk', ['declares_nepali_citizen' => false])->assertUnprocessable()->assertJsonPath('message', 'Membership is open to Nepali citizens only (Act s.30).');
        $this->applyAs('clerk', ['declares_no_other_same_type_membership' => false])->assertUnprocessable();
    }

    public function test_kym_form_validation(): void
    {
        $this->applyAs('clerk', [], ['mother_name' => null])->assertJsonValidationErrors('kym.mother_name');
        $this->applyAs('clerk', [], ['marital_status' => 'married'])->assertJsonValidationErrors('kym.spouse_name');
        $this->applyAs('clerk', [], ['pep' => ['is_pep' => true]])->assertJsonValidationErrors(['kym.pep.name', 'kym.pep.position']);
        $this->applyAs('clerk', [], ['self_declaration_accepted' => false])->assertJsonValidationErrors('kym.self_declaration_accepted');
        $this->applyAs('clerk', [], ['income_by_source' => ['business' => '12.345']])->assertJsonValidationErrors('kym.income_by_source.business');

        $noId = $this->application();
        unset($noId['kym']['citizenship']);
        $this->as('clerk')->postJson('/api/members', $noId)->assertUnprocessable()
            ->assertJsonPath('message', 'A citizenship certificate, national ID or passport number is required (Instruction 1).');
    }

    public function test_duplicate_identity_numbers_are_detected(): void
    {
        $this->applyAs()->assertCreated();

        $this->applyAs()->assertUnprocessable()
            ->assertJsonPath('message', 'A member or applicant with this citizenship certificate number already exists in this cooperative.');
        // Citizenship numbers are unique per issuing district, not nationally.
        $this->applyAs('clerk', [], ['citizenship' => ['issue_district' => 'Kaski']])->assertCreated();
    }

    // --- approval rules -----------------------------------------------------------------

    public function test_approval_requires_documents_risk_and_a_different_person(): void
    {
        $id = $this->applyAs()->json('data.id');

        $this->as('officer')->postJson("/api/members/{$id}/approve")->assertUnprocessable()
            ->assertJsonPath('message', 'Missing required documents: photo, thumbprint_right, thumbprint_left (Instruction 1(1)(ग), 1(4)).');

        $this->as('clerk');
        foreach (['photo', 'thumbprint_right', 'thumbprint_left'] as $type) {
            $this->postJson("/api/members/{$id}/documents", ['type' => $type, 'file' => $this->png('x.png')])->assertCreated();
        }
        $this->as('officer')->postJson("/api/members/{$id}/approve")->assertUnprocessable()
            ->assertJsonPath('message', 'An identity document copy is required (citizenship, national ID, voter ID, passport, driving licence or PAN).');

        $this->as('clerk')->postJson("/api/members/{$id}/documents", ['type' => 'national_id', 'file' => UploadedFile::fake()->create('nid.pdf', 100, 'application/pdf')])->assertCreated();
        $this->as('officer')->postJson("/api/members/{$id}/approve")->assertUnprocessable()
            ->assertJsonPath('message', 'Assess the member\'s AML/CFT risk level before approval.');

        $this->postJson("/api/members/{$id}/risk", ['risk_level' => 'medium', 'reason' => 'Cash business'])->assertOk();
        $this->postJson("/api/members/{$id}/approve")->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.member_number', 1)
            ->assertJsonPath('data.next_kyc_review_on', '2028-10-16'); // medium risk: every 2 years
    }

    public function test_person_who_entered_the_application_cannot_approve_it(): void
    {
        $user = $this->staff['officer'];
        $id = $this->as('officer')->postJson('/api/members', $this->application())->assertForbidden(); // officer lacks member.apply

        $member = app(TenantContext::class)->runAs($this->branch->cooperative_id, $this->branch->id,
            fn () => app(MembershipService::class)->apply(
                collect($this->application())->except('kym')->all(), $this->application()['kym'], $user));

        $this->as('officer')->postJson("/api/members/{$member->id}/approve")->assertUnprocessable()
            ->assertJsonPath('message', 'The person who entered an application cannot also decide it.');
    }

    public function test_review_interval_follows_risk(): void
    {
        $low = $this->activeMember([], 'low');
        $this->assertSame('2029-10-16', Member::query()->withoutGlobalScopes()->find($low)->next_kyc_review_on->format('Y-m-d'));
    }

    public function test_member_numbers_are_sequential(): void
    {
        $a = $this->activeMember();
        $b = $this->activeMember(['citizenship' => ['number' => '27-01-70-00002'], 'recommender_member_numbers' => []]);
        $numbers = Member::query()->withoutGlobalScopes()->whereIn('id', [$a, $b])->orderBy('id')->pluck('member_number')->all();

        $this->assertSame([1, 2], $numbers);
    }

    public function test_two_recommenders_required_once_there_are_two_members(): void
    {
        $this->activeMember();
        $this->activeMember(['citizenship' => ['number' => '27-01-70-00002']]);

        $id = $this->applyAs('clerk', [], ['citizenship' => ['number' => '27-01-70-00003'], 'recommender_member_numbers' => [1]])->json('data.id');
        $this->uploadRequired($id);
        $this->as('officer')->postJson("/api/members/{$id}/risk", ['risk_level' => 'low', 'reason' => 'x'])->assertOk();
        $this->postJson("/api/members/{$id}/approve")->assertUnprocessable()
            ->assertJsonPath('message', 'Two existing active members must recommend the applicant (KYM form section ञ).');

        $this->as('officer')->putJson("/api/members/{$id}/kyc", ['kym' => $this->application([], [
            'citizenship' => ['number' => '27-01-70-00003'], 'recommender_member_numbers' => [1, 2]])['kym']])->assertOk();
        $this->postJson("/api/members/{$id}/approve")->assertOk()->assertJsonPath('data.member_number', 3);
    }

    // --- PEP / high risk -----------------------------------------------------------------

    public function test_pep_is_high_risk_and_needs_high_risk_approver(): void
    {
        $id = $this->applyAs('clerk', [], ['pep' => ['is_pep' => true, 'name' => 'Hari Example', 'relation' => 'father', 'position' => 'Ward chair']])
            ->assertCreated()->assertJsonPath('data.is_pep', true)->assertJsonPath('data.risk_level', 'high')->json('data.id');
        $this->uploadRequired($id);

        $this->as('officer')->postJson("/api/members/{$id}/risk", ['risk_level' => 'low', 'reason' => 'x'])->assertUnprocessable()
            ->assertJsonPath('message', 'A politically exposed person (or family/associate) must be high risk (Instruction 3(1)(घ)).');
        $this->postJson("/api/members/{$id}/approve")->assertUnprocessable()
            ->assertJsonPath('message', 'High-risk or PEP applications need approval by someone with high-risk approval authority (Instruction 3(3)).');

        $this->as('manager')->postJson("/api/members/{$id}/approve")->assertOk()
            ->assertJsonPath('data.next_kyc_review_on', '2027-10-16'); // high risk: every year
    }

    // --- rejection ------------------------------------------------------------------------

    public function test_rejection_needs_reason_and_is_final(): void
    {
        $id = $this->applyAs()->json('data.id');

        $this->as('officer')->postJson("/api/members/{$id}/reject", [])->assertJsonValidationErrors('reason');
        $this->postJson("/api/members/{$id}/reject", ['reason' => 'Lives outside working area'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_reason', 'Lives outside working area');
        $this->postJson("/api/members/{$id}/approve")->assertUnprocessable()->assertJsonPath('message', 'This application has already been decided.');

        // A rejected applicant may apply again with the same ID.
        $this->applyAs()->assertCreated();
    }

    // --- documents and KYM access -------------------------------------------------------------

    public function test_documents_are_private_tenant_scoped_and_validated(): void
    {
        $id = $this->applyAs()->json('data.id');
        $this->postJson("/api/members/{$id}/documents", ['type' => 'photo', 'file' => UploadedFile::fake()->create('x.txt', 1, 'text/plain')])
            ->assertUnprocessable()->assertJsonPath('message', 'Documents must be JPEG, PNG or PDF.');
        $doc = $this->postJson("/api/members/{$id}/documents", ['type' => 'photo', 'file' => $this->png('face.png')])->assertCreated()->json('data.id');

        $path = DB::table('member_documents')->where('id', $doc)->value('path');
        $this->assertStringStartsWith("cooperatives/{$this->branch->cooperative_id}/members/{$id}/", $path);
        $this->assertStringNotContainsString('face', $path);
        Storage::disk('local')->assertExists($path);

        $this->getJson("/api/members/{$id}/documents/{$doc}")->assertForbidden(); // clerk lacks member.kyc_view
        $this->as('officer')->get("/api/members/{$id}/documents/{$doc}")->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'image/png');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'member.document_viewed')->count());
    }

    public function test_other_cooperatives_members_are_not_found(): void
    {
        $other = Branch::factory()->headOffice()->for(Cooperative::factory())->create();
        $theirs = app(TenantContext::class)->runAs($other->cooperative_id, $other->id,
            fn () => app(MembershipService::class)->apply(
                collect($this->application())->except('kym')->all(), $this->application()['kym']));

        $this->as('officer');
        $this->getJson("/api/members/{$theirs->id}")->assertNotFound();
        $this->getJson("/api/members/{$theirs->id}/kyc")->assertNotFound();
        $this->postJson("/api/members/{$theirs->id}/approve")->assertNotFound();
        // Same citizenship number in another cooperative is not a duplicate here (and not revealed).
        $this->as('clerk')->postJson('/api/members', $this->application())->assertCreated();
    }

    public function test_kym_view_is_permissioned_and_audited_and_updates_create_versions(): void
    {
        $id = $this->applyAs()->json('data.id');
        $this->getJson("/api/members/{$id}/kyc")->assertForbidden();

        $this->as('officer')->getJson("/api/members/{$id}/kyc")->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.kym.citizenship.number', '27-01-70-00001');
        $this->putJson("/api/members/{$id}/kyc", ['kym' => $this->application([], ['occupation' => ['type' => 'business', 'detail' => 'Grocery']])['kym']])->assertOk();
        $this->getJson("/api/members/{$id}/kyc")->assertJsonPath('data.version', 2)->assertJsonPath('data.kym.occupation.detail', 'Grocery');

        $this->assertSame(2, DB::table('audit_logs')->where('action', 'member.kyc_viewed')->count());
        $this->assertSame(2, DB::table('member_kyc_versions')->where('member_id', $id)->count());
    }

    public function test_kyc_records_and_documents_are_append_only(): void
    {
        $id = $this->applyAs()->json('data.id');
        $this->postJson("/api/members/{$id}/documents", ['type' => 'photo', 'file' => $this->png('x.png')]);

        foreach (['member_kyc_versions', 'member_documents'] as $table) {
            foreach ([fn () => DB::table($table)->update(['created_at' => now()]), fn () => DB::table($table)->delete()] as $tamper) {
                try {
                    $tamper();
                    $this->fail("{$table} was modified");
                } catch (QueryException $e) {
                    $this->assertSame(1644, $e->errorInfo[1]);
                }
            }
        }
    }

    public function test_review_due_list(): void
    {
        $id = $this->activeMember([], 'high');
        $this->as('officer')->getJson('/api/members?kyc_review_due=1')->assertOk()->assertJsonCount(0, 'data');

        Date::setTestNow('2027-10-17 04:15:00'); // one year later: high risk is due
        $this->getJson('/api/members?kyc_review_due=1')->assertOk()->assertJsonPath('data.0.id', $id);
    }
}
