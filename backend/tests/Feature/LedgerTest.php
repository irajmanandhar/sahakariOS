<?php

namespace Tests\Feature;

use App\Accounting\LedgerAccountService;
use App\Accounting\PostingRejected;
use App\Accounting\PostingService;
use App\Accounting\TrialBalance;
use App\Authorization\Permission;
use App\Calendar\BusinessDayService;
use App\Calendar\FiscalYearService;
use App\Models\Branch;
use App\Models\Cooperative;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\MissingTenantContext;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Double-entry ledger. The chart of accounts here is synthetic test data,
 * not a recommended chart. Amounts are NPR strings.
 */
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private Branch $branch;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-10-16 04:15:00');
        $this->branch = Branch::factory()->headOffice()->create();
        $this->user = User::factory()->create(['email' => 'staff@example.test', 'password' => self::PASSWORD]);
        $this->branch->cooperative->users()->attach($this->user, ['branch_id' => $this->branch->id]);
        $this->bootLedger($this->branch);
    }

    /** Fiscal year, open business day and a small synthetic chart for one cooperative. */
    private function bootLedger(Branch $branch): void
    {
        $this->as(function () {
            app(FiscalYearService::class)->open(2083);
            app(BusinessDayService::class)->open('2026-10-16');
            $accounts = app(LedgerAccountService::class);
            $accounts->create('1', 'Assets (test)', 'asset', false);
            $accounts->create('1.1', 'Cash (test)', 'asset', true, '1');
            $accounts->create('1.2', 'Bank (test)', 'asset', true, '1');
            $accounts->create('2', 'Liabilities (test)', 'liability', false);
            $accounts->create('2.1', 'Member savings (test)', 'liability', true, '2');
        }, $branch);
    }

    private function as(callable $callback, ?Branch $branch = null): mixed
    {
        $branch ??= $this->branch;

        return app(TenantContext::class)->runAs($branch->cooperative_id, $branch->id, $callback);
    }

    private function postEntry(array $lines, string $description = 'Test entry', ?string $key = null): JournalEntry
    {
        return $this->as(fn () => app(PostingService::class)->post($description, $lines, $this->user, $key));
    }

    private function deposit(string $amount = '1000.00', ?string $key = null): JournalEntry
    {
        return $this->postEntry([
            ['account_code' => '1.1', 'debit' => $amount],
            ['account_code' => '2.1', 'credit' => $amount],
        ], 'Test deposit', $key);
    }

    private function assertRejected(string $message, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected rejection: {$message}");
        } catch (PostingRejected $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function assertMysqlError(int $code, callable $statement): void
    {
        try {
            $statement();
            $this->fail("Expected MySQL error {$code}");
        } catch (QueryException $e) {
            $this->assertSame($code, $e->errorInfo[1], $e->getMessage());
        }
    }

    // --- posting rules ---------------------------------------------------------------

    public function test_posts_a_balanced_entry_on_the_business_day(): void
    {
        $entry = $this->deposit('1500.50');

        $this->assertSame(1, $entry->entry_number);
        $this->assertSame('2083/84-000001', $this->as(fn () => $entry->reference()));
        $this->assertSame('2026-10-16', $entry->entry_date->format('Y-m-d'));
        $this->assertSame(['1500.50', '0.00'], [$entry->lines[0]->debit, $entry->lines[0]->credit]);
        $this->assertSame(['0.00', '1500.50'], [$entry->lines[1]->debit, $entry->lines[1]->credit]);
        $this->assertSame($this->branch->id, $entry->lines[0]->branch_id);
    }

    public function test_entry_numbers_are_sequential(): void
    {
        $this->assertSame([1, 2, 3], [$this->deposit()->entry_number, $this->deposit()->entry_number, $this->deposit()->entry_number]);
    }

    public function test_rejects_unbalanced_entries(): void
    {
        $this->assertRejected('does not balance: debits 100.00, credits 99.99', fn () => $this->postEntry([
            ['account_code' => '1.1', 'debit' => '100.00'],
            ['account_code' => '2.1', 'credit' => '99.99'],
        ]));
        $this->assertSame(0, JournalEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_rejects_bad_lines(): void
    {
        $cases = [
            'at least two lines' => [['account_code' => '1.1', 'debit' => '1']],
            'not both' => [['account_code' => '1.1', 'debit' => '1', 'credit' => '1'], ['account_code' => '2.1', 'credit' => '0']],
            'greater than zero' => [['account_code' => '1.1', 'debit' => '0'], ['account_code' => '2.1', 'credit' => '0']],
            'at most 2 decimal places' => [['account_code' => '1.1', 'debit' => '10.005'], ['account_code' => '2.1', 'credit' => '10.005']],
            "Invalid amount '-5'" => [['account_code' => '1.1', 'debit' => '-5'], ['account_code' => '2.1', 'credit' => '-5']],
            'group account' => [['account_code' => '1', 'debit' => '5'], ['account_code' => '2.1', 'credit' => '5']],
            'account not found' => [['account_code' => '9.9', 'debit' => '5'], ['account_code' => '2.1', 'credit' => '5']],
        ];
        foreach ($cases as $message => $lines) {
            $this->assertRejected($message, fn () => $this->postEntry($lines));
        }
    }

    public function test_exact_decimal_arithmetic(): void
    {
        // 0.1 + 0.2 must equal 0.3 exactly (it does not in floating point).
        $entry = $this->postEntry([
            ['account_code' => '1.1', 'debit' => '0.10'],
            ['account_code' => '1.2', 'debit' => '0.20'],
            ['account_code' => '2.1', 'credit' => '0.30'],
        ]);

        $this->assertSame('0.30', $entry->total);
    }

    public function test_requires_an_open_business_day(): void
    {
        $this->as(fn () => app(BusinessDayService::class)->close());

        $this->assertRejected('No business day is open', fn () => $this->deposit());
    }

    public function test_another_cooperatives_account_is_not_found(): void
    {
        $other = Branch::factory()->headOffice()->for(Cooperative::factory())->create();
        $this->bootLedger($other);
        $theirCash = $this->as(fn () => DB::table('ledger_accounts')->where('cooperative_id', $other->cooperative_id)->where('code', '1.1')->value('id'));

        $this->assertRejected('account not found', fn () => $this->postEntry([
            ['ledger_account_id' => $theirCash, 'debit' => '5'],
            ['account_code' => '2.1', 'credit' => '5'],
        ]));
    }

    public function test_account_rules(): void
    {
        $accounts = fn () => app(LedgerAccountService::class);
        $this->assertRejected('already exists', fn () => $this->as(fn () => $accounts()->create('1.1', 'Dup', 'asset', true, '1')));
        $this->assertRejected('type must match', fn () => $this->as(fn () => $accounts()->create('1.9', 'Wrong', 'expense', true, '1')));
        $this->assertRejected('only group accounts', fn () => $this->as(fn () => $accounts()->create('1.1.1', 'Child', 'asset', true, '1.1')));
    }

    // --- idempotency -----------------------------------------------------------------

    public function test_same_key_same_request_returns_the_original_entry(): void
    {
        $first = $this->deposit('250.00', 'deposit-0001');
        $again = $this->deposit('250.00', 'deposit-0001');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, $this->as(fn () => JournalEntry::query()->count()));
    }

    public function test_same_key_different_request_is_refused(): void
    {
        $this->deposit('250.00', 'deposit-0001');

        $this->assertRejected('already used for a different', fn () => $this->deposit('251.00', 'deposit-0001'));
    }

    // --- reversals --------------------------------------------------------------------

    public function test_reversal_mirrors_and_nets_to_zero(): void
    {
        $entry = $this->deposit('700.00');
        $reversal = $this->as(fn () => app(PostingService::class)->reverse($entry->load('lines'), 'Keyed twice', $this->user));

        $this->assertSame($entry->id, $reversal->reversal_of_id);
        $this->assertSame(['0.00', '700.00'], [$reversal->lines[0]->debit, $reversal->lines[0]->credit]);
        $this->assertStringContainsString('Reversal of 2083/84-000001: Keyed twice', $reversal->description);

        $tb = $this->as(fn () => app(TrialBalance::class)->compute());
        $this->assertSame(['0.00', '0.00'], [$tb['total_debit'], $tb['total_credit']]);
        // The original stays visible and unchanged.
        $this->assertSame('700.00', $this->as(fn () => JournalEntry::query()->find($entry->id))->total);
    }

    public function test_an_entry_can_be_reversed_only_once_and_reversals_are_final(): void
    {
        $entry = $this->deposit();
        $reversal = $this->as(fn () => app(PostingService::class)->reverse($entry, 'mistake'));

        $this->assertRejected('already been reversed', fn () => $this->as(fn () => app(PostingService::class)->reverse($entry, 'again')));
        $this->assertRejected('cannot itself be reversed', fn () => $this->as(fn () => app(PostingService::class)->reverse($reversal->load('lines'), 'undo')));
        // Loading tenant data outside a tenant context fails closed:
        $this->expectException(MissingTenantContext::class);
        $reversal->load('lines');
    }

    // --- database guarantees (bypassing the application) -----------------------------

    public function test_database_refuses_editing_or_deleting_posted_journals(): void
    {
        $entry = $this->deposit();

        $this->assertMysqlError(1644, fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->update(['debit' => '1.00']));
        $this->assertMysqlError(1644, fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->delete());
        $this->assertMysqlError(1644, fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['description' => 'edited']));
        $this->assertMysqlError(1644, fn () => DB::table('journal_entries')->where('id', $entry->id)->delete());
    }

    public function test_database_refuses_adding_a_line_to_a_posted_entry(): void
    {
        $entry = $this->deposit();
        $cash = $entry->lines[0];

        $this->assertMysqlError(1644, fn () => DB::table('journal_lines')->insert([
            'cooperative_id' => $entry->cooperative_id, 'journal_entry_id' => $entry->id, 'line_number' => 3,
            'ledger_account_id' => $cash->ledger_account_id, 'branch_id' => $cash->branch_id, 'debit' => '1.00', 'credit' => '0.00',
        ]));
    }

    /** A raw journal header (as if written around the application) expecting 2 lines totalling 10.00. */
    private function rawHeader(JournalEntry $like): int
    {
        return DB::table('journal_entries')->insertGetId([
            'cooperative_id' => $like->cooperative_id, 'fiscal_year_id' => $like->fiscal_year_id,
            'business_day_id' => $like->business_day_id, 'entry_number' => 99, 'entry_date' => '2026-10-16',
            'description' => 'forged', 'line_count' => 2, 'total' => '10.00', 'posted_at' => now(),
        ]);
    }

    public function test_database_refuses_an_unbalanced_entry_written_directly(): void
    {
        $entry = $this->deposit();
        $cash = $entry->lines[0];
        $headerId = $this->rawHeader($entry);
        $line = fn (int $n, string $debit, string $credit) => fn () => DB::table('journal_lines')->insert([
            'cooperative_id' => $entry->cooperative_id, 'journal_entry_id' => $headerId, 'line_number' => $n,
            'ledger_account_id' => $cash->ledger_account_id, 'branch_id' => $cash->branch_id, 'debit' => $debit, 'credit' => $credit,
        ]);

        $this->assertMysqlError(3819, $line(1, '5.00', '5.00'));       // CHECK: one-sided
        $this->assertMysqlError(3819, $line(1, '0.00', '0.00'));       // CHECK: positive
        $line(1, '10.00', '0.00')();
        $this->assertMysqlError(1644, $line(2, '0.00', '9.99'));       // final line must balance
        $this->assertMysqlError(1644, $line(2, '0.00', '10.01'));      // cannot exceed the total
        $line(2, '0.00', '10.00')();
        $this->assertMysqlError(1644, $line(3, '0.00', '1.00'));       // complete: no more lines
        $this->assertMysqlError(3819, fn () => DB::table('journal_entries')->insert([
            'cooperative_id' => $entry->cooperative_id, 'fiscal_year_id' => $entry->fiscal_year_id,
            'business_day_id' => $entry->business_day_id, 'entry_number' => 100, 'entry_date' => '2026-10-16',
            'description' => 'one line', 'line_count' => 1, 'total' => '10.00', 'posted_at' => now(),
        ]));                                                           // CHECK: at least 2 lines
    }

    public function test_database_refuses_cross_cooperative_lines(): void
    {
        $other = Branch::factory()->headOffice()->for(Cooperative::factory())->create();
        $entry = $this->deposit();
        $headerId = $this->rawHeader($entry);

        $this->assertMysqlError(1452, fn () => DB::table('journal_lines')->insert([
            'cooperative_id' => $entry->cooperative_id, 'journal_entry_id' => $headerId, 'line_number' => 1,
            'ledger_account_id' => $entry->lines[0]->ledger_account_id, 'branch_id' => $other->id,
            'debit' => '1.00', 'credit' => '0.00',
        ]));
    }

    public function test_ledger_verify(): void
    {
        $this->deposit();
        $this->as(fn () => app(PostingService::class)->reverse(JournalEntry::query()->first()->load('lines'), 'test'));

        $this->artisan('ledger:verify')->expectsOutputToContain('Ledger OK (2 entries)')->assertSuccessful();
    }

    // --- trial balance ----------------------------------------------------------------

    public function test_trial_balance_balances_and_respects_as_of(): void
    {
        $this->deposit('1000.00');
        $this->postEntry([['account_code' => '1.2', 'debit' => '400.00'], ['account_code' => '1.1', 'credit' => '400.00']], 'Cash to bank');

        $tb = $this->as(fn () => app(TrialBalance::class)->compute());
        $this->assertTrue($tb['balanced']);
        $this->assertSame(['1000.00', '1000.00'], [$tb['total_debit'], $tb['total_credit']]);
        $this->assertSame(
            [['1.1', '600.00', '0.00'], ['1.2', '400.00', '0.00'], ['2.1', '0.00', '1000.00']],
            array_map(fn ($a) => [$a['code'], $a['debit_balance'], $a['credit_balance']], $tb['accounts']),
        );

        $this->assertSame([], $this->as(fn () => app(TrialBalance::class)->compute('2026-10-15'))['accounts']);
    }

    // --- HTTP ---------------------------------------------------------------------------

    private function signIn(array $permissions): void
    {
        $this->as(function () use ($permissions) {
            $role = Role::create(['key' => 'accountant', 'name' => 'Accountant']);
            $role->syncPermissions($permissions);
            $role->assignTo($this->user);
        });
        $this->postJson('/api/auth/login', ['email' => 'staff@example.test', 'password' => self::PASSWORD])->assertOk();
        $this->putJson('/api/auth/cooperative', ['cooperative_id' => $this->branch->cooperative_id])->assertOk();
    }

    private function journalBody(string $amount = '125.00'): array
    {
        return ['description' => 'Opening cash (test)', 'lines' => [
            ['account_code' => '1.1', 'debit' => $amount],
            ['account_code' => '2.1', 'credit' => $amount],
        ]];
    }

    public function test_http_posting_with_idempotency(): void
    {
        $this->signIn([Permission::JournalPost, Permission::LedgerView]);

        $first = $this->withHeader('Idempotency-Key', 'web-entry-001')->postJson('/api/journal-entries', $this->journalBody())
            ->assertCreated()->assertJsonPath('data.reference', '2083/84-000001')
            ->assertJsonPath('data.entry_date_bs', '2083-06-30')->assertJsonPath('data.lines.0.debit', '125.00');
        $this->withHeader('Idempotency-Key', 'web-entry-001')->postJson('/api/journal-entries', $this->journalBody())
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->withHeader('Idempotency-Key', 'web-entry-001')->postJson('/api/journal-entries', $this->journalBody('126.00'))
            ->assertUnprocessable();

        $this->assertSame(1, $this->as(fn () => JournalEntry::query()->count()));
    }

    public function test_http_requires_idempotency_key_and_string_amounts(): void
    {
        $this->signIn([Permission::JournalPost]);

        $this->postJson('/api/journal-entries', $this->journalBody())->assertUnprocessable()
            ->assertJsonPath('message', 'An Idempotency-Key header (8-100 characters: letters, digits, - _ : .) is required.');

        $body = $this->journalBody();
        $body['lines'][0]['debit'] = 125.0; // a JSON number (float) is refused
        $body['lines'][1]['credit'] = 125.0;
        $this->withHeader('Idempotency-Key', 'web-entry-002')->postJson('/api/journal-entries', $body)
            ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.debit']);
    }

    public function test_http_permissions(): void
    {
        $this->signIn([Permission::LedgerView]);

        $this->withHeader('Idempotency-Key', 'web-entry-003')->postJson('/api/journal-entries', $this->journalBody())->assertForbidden();
        $this->postJson('/api/ledger-accounts', ['code' => '3', 'name' => 'x', 'type' => 'equity', 'is_postable' => false])->assertForbidden();
        $this->getJson('/api/reports/trial-balance')->assertOk()->assertJsonPath('data.balanced', true);
        $this->getJson('/api/ledger-accounts')->assertOk()->assertJsonPath('data.1.code', '1.1');
    }

    public function test_http_reverse_and_cross_tenant_404(): void
    {
        $other = Branch::factory()->headOffice()->for(Cooperative::factory())->create();
        $this->bootLedger($other);
        $theirs = app(TenantContext::class)->runAs($other->cooperative_id, $other->id, fn () => app(PostingService::class)->post('Theirs',
            [['account_code' => '1.1', 'debit' => '5'], ['account_code' => '2.1', 'credit' => '5']]));
        $mine = $this->deposit();
        $this->signIn([Permission::JournalReverse, Permission::LedgerView]);

        $this->getJson("/api/journal-entries/{$theirs->id}")->assertNotFound();
        $this->postJson("/api/journal-entries/{$theirs->id}/reverse", ['reason' => 'x'])->assertNotFound();

        $reversalId = $this->postJson("/api/journal-entries/{$mine->id}/reverse", ['reason' => 'Wrong account'])->assertCreated()
            ->assertJsonPath('data.reversal_of_id', $mine->id)->json('data.id');
        $this->postJson("/api/journal-entries/{$mine->id}/reverse", ['reason' => 'Again'])->assertUnprocessable()
            ->assertJsonPath('message', 'This entry has already been reversed.');
        $this->getJson("/api/journal-entries/{$mine->id}")->assertJsonPath('data.reversed_by_id', $reversalId);
    }
}
