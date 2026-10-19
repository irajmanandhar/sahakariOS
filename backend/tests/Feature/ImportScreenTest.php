<?php

namespace Tests\Feature;

use App\Calendar\BusinessDayService;
use App\Models\Cooperative;
use App\Models\LedgerAccount;
use App\Models\Member;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** cooperative:create followed by the Settings → Import upload endpoints. */
class ImportScreenTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private Cooperative $coop;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-10-19 04:15:00');
        User::factory()->create(['email' => 'manager@example.test', 'password' => self::PASSWORD]);
        $products = storage_path('framework/testing/products.csv');
        @mkdir(dirname($products), 0777, true);
        file_put_contents($products, "product_code,sheet,account_type,interest_rate_in_header,minimum_balance,accounts\nACHHIK,Achhik,ऐच्छिक बचत,3,500,2\nSHARE,Share Khhata,व्यक्तिगत शेयर खाता,0,500,2\n");

        $this->artisan('cooperative:create', ['code' => 'sbk', 'name' => 'Synthetic Bachat Sahakari', '--admin' => ['manager@example.test'], '--products' => $products])
            ->expectsOutputToContain('Savings products (general, quarterly interest; review them): ACHHIK.')
            ->assertSuccessful();
        @unlink($products);
        $this->coop = Cooperative::query()->where('code', 'SBK')->firstOrFail();
    }

    private function signIn(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'manager@example.test', 'password' => self::PASSWORD])->assertOk();
        $this->putJson('/api/auth/cooperative', ['cooperative_id' => $this->coop->id])->assertOk();
    }

    private function upload(string $kind, string $csv, bool $dryRun, ?string $expected = null)
    {
        $files = ['file' => UploadedFile::fake()->createWithContent("{$kind}.csv", $csv), 'dry_run' => $dryRun ? '1' : '0'];
        if ($expected !== null) {
            $files['expected'] = UploadedFile::fake()->createWithContent('expected.csv', $expected);
        }

        return $this->post("/api/imports/{$kind}", $files, ['Accept' => 'application/json']);
    }

    public function test_new_cooperative_is_ready_for_history(): void
    {
        app(TenantContext::class)->runAs($this->coop->id, (int) DB::table('branches')->where('cooperative_id', $this->coop->id)->value('id'), function () {
            $product = SavingsProduct::query()->where('code', 'ACHHIK')->firstOrFail();
            $this->assertSame(['ऐच्छिक बचत (Achhik)', 'general', '3.000000', '500.00', '2.1.1'],
                [$product->name, $product->type, $product->interest_rate, $product->minimum_balance, LedgerAccount::query()->find($product->ledger_account_id)->code]);
            $this->assertNull(app(BusinessDayService::class)->current());
        });
        $this->assertSame(0, DB::table('business_days')->where('cooperative_id', $this->coop->id)->count());
        $this->artisan('cooperative:create', ['code' => 'SBK', 'name' => 'Again', '--admin' => ['manager@example.test']])
            ->expectsOutputToContain('Cooperative SBK already exists.')->assertFailed();
    }

    public function test_members_then_history_upload_with_check_first(): void
    {
        $this->signIn();
        $members = "member_number,full_name,date_of_birth,gender,phone,membership_date,share_units,savings_product_code,savings_balance\n7,Test Member Seven,,,,2018-04-28,,,\n";
        $history = "date_ad,date_bs,voucher_no,member_number,product_code,legacy_account_no,type,amount,particulars\n2026-07-17,,,7,ACHHIK,1001,opening,1000,\n2026-07-20,,V-1,7,ACHHIK,1001,deposit,250.50,Cash\n2026-07-20,,,7,SHARE,001,opening,300,\n";

        $this->upload('members', $members, true)->assertOk()->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.lines.0', '[dry run] 1 member(s) would be imported, 0 skipped. Nothing was written.');
        $this->assertSame(0, Member::query()->withoutGlobalScopes()->count());
        $this->upload('members', $members, false)->assertOk()->assertJsonPath('data.ok', true);

        $this->upload('history', $history, true, "product_code,legacy_account_no,member_number,balance\nACHHIK,1001,7,1250.40\n")
            ->assertOk()->assertJsonPath('data.ok', false)
            ->assertJsonFragment(['ACHHIK account 1001: imported 1250.50, previous system 1250.40.']);
        $this->upload('history', $history, false, "product_code,legacy_account_no,member_number,balance\nACHHIK,1001,7,1250.50\nSHARE,001,7,300\n")
            ->assertOk()->assertJsonPath('data.ok', true);

        $this->assertSame('1250.50', DB::table('savings_accounts')->where('legacy_account_number', '1001')->value('balance'));
        $this->assertSame(['migration.members_checked', 'migration.members_imported', 'migration.history_checked', 'migration.history_imported'],
            DB::table('audit_logs')->where('action', 'like', 'migration.%')->orderBy('id')->pluck('action')->all());
        $this->assertSame([], Storage::disk('local')->allFiles('imports')); // uploads deleted
    }

    public function test_upload_needs_permission_and_a_csv(): void
    {
        $this->signIn();
        $this->upload('loans', 'x', true)->assertNotFound();
        $this->post('/api/imports/members', ['file' => UploadedFile::fake()->create('book.xlsx', 10), 'dry_run' => '1'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        app(TenantContext::class)->runAs($this->coop->id, (int) DB::table('branches')->where('cooperative_id', $this->coop->id)->value('id'), fn () => DB::table('role_permissions')->where('permission', 'migration.import')->delete());
        $this->upload('members', "member_number\n", true)->assertForbidden();
    }
}
