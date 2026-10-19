<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Savings\SavingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCooperative;
use Tests\TestCase;

class ImportOpeningBalancesTest extends TestCase
{
    use BuildsCooperative, RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCooperative();
        $this->inCoop(fn () => app(SavingsService::class)->createProduct(['code' => 'GEN', 'name' => 'General', 'type' => 'general', 'interest_rate' => '4', 'interest_posting' => 'yearly', 'ledger_account_code' => 'L1']));
        $this->file = storage_path('framework/testing/import.csv');
        @mkdir(dirname($this->file), 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function csv(string ...$rows): void
    {
        file_put_contents($this->file, "member_number,full_name,date_of_birth,gender,phone,membership_date,share_units,savings_product_code,savings_balance\n".implode("\n", $rows)."\n");
    }

    private function import(bool $dryRun = false)
    {
        return $this->artisan('migration:import', ['cooperative' => $this->branch->cooperative->code, 'file' => $this->file, '--dry-run' => $dryRun]);
    }

    public function test_imports_members_shares_and_savings(): void
    {
        $this->csv('101,Ram Example,1980-02-01,male,9800000001,2015-04-01,250,GEN,45000.50', '102,Sita Example,1985-06-15,female,,2016-01-10,100,,');

        $this->import()->expectsOutputToContain('Imported 2 member(s)')->expectsOutputToContain('Opening balance account now: 80000.50 (debit)')->assertSuccessful();

        $ram = Member::query()->withoutGlobalScopes()->where('member_number', 101)->first();
        $this->assertSame(['active', '2015-04-01'], [$ram->status, substr($ram->decided_at, 0, 10)]);
        $this->assertSame('2026-10-16', $ram->next_kyc_review_on->format('Y-m-d')); // KYM due now
        $this->assertSame('-35000.00', $this->balanceOf('E1'));     // 350 shares x 100
        $this->assertSame('-45000.50', $this->balanceOf('L1'));
        $this->assertSame('80000.50', $this->balanceOf('E3'));      // debit: to be cleared by the rest of the old trial balance
        $this->artisan('ledger:verify')->assertSuccessful();

        // Next applicant numbering continues after the imported numbers.
        $this->assertSame(102, (int) DB::table('member_sequences')->where('cooperative_id', $this->branch->cooperative_id)->value('last_number'));
    }

    public function test_re_running_skips_imported_members(): void
    {
        $this->csv('101,Ram Example,1980-02-01,male,,2015-04-01,10,,');
        $this->import()->assertSuccessful();
        $this->import()->expectsOutputToContain('Imported 0 member(s), skipped 1')->assertSuccessful();

        $this->assertSame(1, DB::table('share_transactions')->count());
    }

    public function test_any_error_imports_nothing(): void
    {
        $this->csv('101,Ram Example,1980-02-01,male,,2015-04-01,10,,', '101,Duplicate,1980-02-30,unknown,,2015-04-01,1.5,NOPE,12.345');

        $this->import()
            ->expectsOutputToContain('Row 3: duplicate member_number 101.')
            ->expectsOutputToContain('Row 3: date_of_birth must be an AD date YYYY-MM-DD.')
            ->expectsOutputToContain('Row 3: gender must be female, male, other or blank.')
            ->expectsOutputToContain('Row 3: unknown savings product NOPE.')
            ->assertFailed();
        $this->assertSame(0, Member::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->csv('101,Ram Example,1980-02-01,male,,2015-04-01,10,GEN,100.00');

        $this->import(true)->expectsOutputToContain('[dry run] 1 member(s) would be imported')->assertSuccessful();

        $this->assertSame(0, Member::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('journal_entries')->where('description', 'like', 'Opening balances%')->count());
    }
}
