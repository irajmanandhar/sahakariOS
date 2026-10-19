<?php

namespace Tests\Feature;

use App\Calendar\BusinessDayService;
use App\Models\BusinessDay;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Savings\SavingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCooperative;
use Tests\TestCase;

class ImportPassbookHistoryTest extends TestCase
{
    use BuildsCooperative, RefreshDatabase;

    private string $file;

    private string $expected;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCooperative();
        $this->inCoop(function () {
            app(SavingsService::class)->createProduct(['code' => 'GEN', 'name' => 'General', 'type' => 'general', 'interest_rate' => '3', 'interest_posting' => 'quarterly', 'ledger_account_code' => 'L1']);
            app(BusinessDayService::class)->close();
        });
        Date::setTestNow('2026-10-25 04:15:00');
        $this->activeMember(['member_number' => 7]);
        $this->file = storage_path('framework/testing/history.csv');
        $this->expected = storage_path('framework/testing/expected.csv');
        @mkdir(dirname($this->file), 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @unlink($this->expected);
        parent::tearDown();
    }

    private function csv(string ...$rows): void
    {
        file_put_contents($this->file, "\xEF\xBB\xBFdate_ad,date_bs,voucher_no,member_number,product_code,legacy_account_no,type,amount,particulars\n".implode("\n", $rows)."\n");
    }

    private function import(array $options = [])
    {
        return $this->artisan('migration:import-history', ['cooperative' => $this->branch->cooperative->code, 'file' => $this->file] + $options);
    }

    private function history(): void
    {
        $this->csv(
            '2026-10-18,,V-9,7,GEN,1001,withdrawal,300,Cash',
            ',2083-07-01,,7,GEN,1001,opening,1000,',               // BS 2083 Kartik 1 = 2026-10-18; sorted before the withdrawal
            '2026-10-20,2083-07-03,V-12,7,GEN,1001,deposit,1500.50,Cash',
            '2026-10-20,,,7,GEN,1001,interest,20,Aofh',
            '2026-10-20,,,7,GEN,1001,tax,1.20,s/',
            '2026-10-20,,,7,share,1001,opening,500,',
            '2026-10-21,,V-13,7,SHARE,1001,deposit,200,',
            '2026-10-21,,,7,GEN,1001,charge,50,Member Renew Charge',
        );
    }

    public function test_replays_history_day_by_day_into_books_and_passbook(): void
    {
        $this->history();
        file_put_contents($this->expected, "product_code,legacy_account_no,member_number,balance\nGEN,1001,7,2169.30\nSHARE,1001,7,700\n");

        $this->import(['--expected' => $this->expected])
            ->expectsOutputToContain('Replayed 8 row(s) over 3 business day(s), 2026-10-18 to 2026-10-21, for 2 account(s)')
            ->assertSuccessful();

        $account = $this->inCoop(fn () => SavingsAccount::query()->where('legacy_account_number', '1001')->firstOrFail());
        $this->assertSame(['2169.30', '2026-10-18', '2026-10-21'], [$account->balance, $account->opened_on->format('Y-m-d'), substr((string) $account->interest_accrued_through, 0, 10)]);
        $rows = DB::table('savings_transactions')->where('savings_account_id', $account->id)->orderBy('id')->get(['type', 'channel', 'amount', 'balance_after', 'reference']);
        $this->assertSame([
            ['deposit', 'system', '1000.00', '1000.00', null], ['withdrawal', 'cash', '300.00', '700.00', 'V-9'], ['deposit', 'cash', '1500.50', '2200.50', 'V-12'],
            ['interest', 'system', '20.00', '2220.50', null], ['tax', 'system', '1.20', '2219.30', null], ['charge', 'system', '50.00', '2169.30', null],
        ], $rows->map(fn ($r) => array_values((array) $r))->all());

        // Each date was a business day, opened and closed in order; the journal is dated by them.
        $this->assertSame(['2026-10-18', '2026-10-20', '2026-10-21'], $this->inCoop(fn () => BusinessDay::query()->where('business_date', '>', '2026-10-16')->orderBy('business_date')->pluck('business_date')->map->format('Y-m-d')->all()));
        $this->assertNull($this->inCoop(fn () => app(BusinessDayService::class)->current()));
        $this->assertSame(1, DB::table('journal_entries')->where('entry_date', '2026-10-18')->where('description', 'like', 'Withdrawal (previous system)%voucher V-9')->count());

        $this->assertSame('-2169.30', $this->balanceOf('L1'));
        $this->assertSame('-700.00', $this->balanceOf('E1'));
        $this->assertSame('1500.00', $this->balanceOf('E3'));      // opening balances: savings 1000 + shares 500
        $this->assertSame('1400.50', $this->balanceOf('A1'));      // cash 1500.50 + 200 - 300
        $this->assertSame('20.00', $this->balanceOf('X1'));
        $this->assertSame('-1.20', $this->balanceOf('L3'));
        $this->assertSame('-50.00', $this->balanceOf('I4'));
        $this->assertSame(7, (int) DB::table('share_transactions')->where('member_id', Member::query()->withoutGlobalScopes()->where('member_number', 7)->value('id'))->sum('units'));
        $this->assertSame(3, DB::table('member_cash_movements')->count()); // deposit, withdrawal, share purchase
        $this->artisan('ledger:verify')->assertSuccessful();
    }

    public function test_passbook_and_day_book_show_the_imported_history(): void
    {
        $this->history();
        $this->import()->assertSuccessful();
        $this->signIn();
        $account = $this->inCoop(fn () => SavingsAccount::query()->firstOrFail());

        $rows = $this->getJson("/api/savings-accounts/{$account->id}")->assertOk()->json('data.transactions');
        $this->assertSame(['2026-10-18', '2083-07-01', 'Opening balance (previous system)', null, '1000.00', '1000.00'],
            [$rows[0]['date'], $rows[0]['date_bs'], $rows[0]['particulars'], $rows[0]['debit'], $rows[0]['credit'], $rows[0]['balance']]);
        $this->assertSame(['V-9', '300.00', null, '700.00'], [$rows[1]['reference'], $rows[1]['debit'], $rows[1]['credit'], $rows[1]['can_withdraw']]);
        $this->assertSame(['Aofh', '20.00'], [$rows[3]['particulars'], $rows[3]['credit']]); // particulars kept as given in the file

        $csv = $this->get("/api/savings-accounts/{$account->id}/passbook.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('2083-07-03,2026-10-20,V-12,Cash,,1500.50,2200.50,2200.50', $csv);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'savings_account.passbook_exported')->count());

        $day = $this->getJson('/api/reports/daily-balances?from=2026-10-19&to=2026-10-21')->assertOk()->json('data');
        $this->assertSame(['GEN' => '700.00'], $day['opening']);
        $this->assertSame(['GEN', 'SHARE'], $day['products']);
        $this->assertSame(['credit' => '1520.50', 'debit' => '1.20', 'balance' => '2219.30'], $day['days'][0]['products']['GEN']);
        $this->assertSame(['credit' => '200.00', 'debit' => '0.00', 'balance' => '700.00'], $day['days'][1]['products']['SHARE']);
        $this->assertSame(['GEN' => '2169.30', 'SHARE' => '700.00'], $day['closing']);
    }

    public function test_a_difference_from_the_old_balances_imports_nothing(): void
    {
        $this->history();
        file_put_contents($this->expected, "product_code,legacy_account_no,member_number,balance\nGEN,1001,7,2169.31\n");

        $this->import(['--expected' => $this->expected])
            ->expectsOutputToContain('GEN account 1001: imported 2169.30, previous system 2169.31.')
            ->assertFailed();
        $this->assertSame(0, DB::table('savings_accounts')->count());
        $this->assertSame(0, DB::table('business_days')->where('business_date', '>', '2026-10-16')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->history();
        $this->import(['--dry-run' => true])->expectsOutputToContain('[dry run] Nothing was written.')->assertSuccessful();
        $this->assertSame(0, DB::table('savings_transactions')->count());
    }

    public function test_every_row_is_checked_before_writing(): void
    {
        $this->csv(
            '2026-10-18,,,7,GEN,1001,opening,100,',
            '2026-10-19,,,7,GEN,1001,withdrawal,100.01,',
            '2026-10-20,,,7,GEN,1001,opening,5,',
        );

        $this->import()
            ->expectsOutputToContain('Row 3: the balance of GEN:1001 would go below zero on 2026-10-19.')
            ->expectsOutputToContain('Row 4: the opening balance of GEN:1001 must be its first row.')
            ->assertFailed();

        $this->csv('2026-10-19,2083-07-01,,8,NOPE,,refund,1.234,', '2026-10-19,,,7,SHARE,,deposit,150,');
        $this->import()
            ->expectsOutputToContain('Row 2: date_bs 2083-07-01 is BS 2083-07-02 for 2026-10-19.')
            ->expectsOutputToContain('Row 2: member 8 does not exist (import members first).')
            ->expectsOutputToContain('Row 2: unknown savings product NOPE.')
            ->expectsOutputToContain('Row 2: type refund is not allowed for savings.')
            ->expectsOutputToContain('Row 2: amount must be greater than zero with at most 2 decimals.')
            ->expectsOutputToContain('Row 3: share amounts must be whole shares of Rs 100.00.')
            ->assertFailed();
        $this->assertSame(0, DB::table('journal_entries')->where('entry_date', '>', '2026-10-16')->count());
    }

    public function test_history_must_start_after_the_last_business_day(): void
    {
        $this->csv('2026-10-16,,,7,GEN,1001,opening,100,');
        $this->import()->expectsOutputToContain('History must start after the last business day (2026-10-16)')->assertFailed();
    }
}
