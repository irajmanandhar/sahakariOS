<?php

namespace Tests\Feature;

use App\Accounting\PostingService;
use App\Notifications\SmsOutbox;
use App\Savings\SavingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\BuildsCooperative;
use Tests\TestCase;

class BankAndSmsTest extends TestCase
{
    use BuildsCooperative, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCooperative();
        $this->signIn();
        $this->putJson('/api/ledger-account-tags', ['account_code' => 'A4', 'tags' => ['bank_deposit']])->assertOk();
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('statement.csv', $content);
    }

    public function test_statement_import_auto_matches_and_reconciles(): void
    {
        $this->inCoop(function () {
            $post = fn (string $d, string $dr, string $cr, string $amt) => app(PostingService::class)->post($d, [['account_code' => $dr, 'debit' => $amt], ['account_code' => $cr, 'credit' => $amt]]);
            $post('Shares (test)', 'A1', 'E1', '100000');
            $post('Cash to bank (test)', 'A4', 'A1', '50000');       // on the statement
            $post('Cash to bank, in transit (test)', 'A4', 'A1', '7000'); // not yet on the statement
        });

        $this->postJson('/api/bank-accounts/A4/statements', ['file' => $this->csv("date,description,amount,reference\n2026-10-17,Deposit,50000.00,DEP1\n2026-10-17,Bank charges,-150.00,\n")])
            ->assertCreated()->assertJsonPath('data.imported', 2)->assertJsonPath('data.matched', 1);

        $this->getJson('/api/bank-accounts/A4/reconciliation?as_of=2026-10-17&statement_balance=49850.00')->assertOk()
            ->assertJsonPath('data.ledger_balance', '57000.00')
            ->assertJsonPath('data.adjusted_ledger_balance', '49850.00') // 57000 - 7000 in transit - 150 charges
            ->assertJsonPath('data.reconciled', true)
            ->assertJsonCount(1, 'data.unmatched_ledger')->assertJsonCount(1, 'data.unmatched_statement');

        $this->getJson('/api/bank-accounts/A4/reconciliation?as_of=2026-10-17&statement_balance=49000.00')->assertJsonPath('data.reconciled', false);
    }

    public function test_statement_validation_and_bank_tag(): void
    {
        $this->postJson('/api/bank-accounts/A4/statements', ['file' => $this->csv("when,what,how much\n")])->assertUnprocessable()
            ->assertJsonPath('message', 'The first row must be: date,description,amount[,reference].');
        $this->postJson('/api/bank-accounts/A4/statements', ['file' => $this->csv("date,description,amount\n2026-02-30,x,10\n")])->assertUnprocessable()
            ->assertJsonPath('message', 'Row 2: invalid date.');
        $this->postJson('/api/bank-accounts/A4/statements', ['file' => $this->csv("date,description,amount\n2026-10-17,x,10.555\n")])->assertUnprocessable();
        $this->postJson('/api/bank-accounts/A1/statements', ['file' => $this->csv("date,description,amount\n2026-10-17,x,10\n")])->assertUnprocessable()
            ->assertJsonPath('message', 'Account A1 is not tagged as a bank account.');
    }

    public function test_sms_is_queued_with_the_transaction_and_sent_by_the_command(): void
    {
        $account = $this->inCoop(function () {
            app(PostingService::class)->post('Shares (test)', [['account_code' => 'A1', 'debit' => '10000000'], ['account_code' => 'E1', 'credit' => '10000000']]);
            $product = app(SavingsService::class)->createProduct(['code' => 'G', 'name' => 'General savings', 'type' => 'general', 'interest_rate' => '1', 'interest_posting' => 'yearly', 'ledger_account_code' => 'L1']);

            return app(SavingsService::class)->open($this->activeMember(['phone' => '9800000012']), $product);
        });

        $this->withHeader('Idempotency-Key', 'sms-dep-1')->postJson("/api/savings-accounts/{$account->id}/deposits", ['amount' => '125000.00'])->assertCreated();
        $body = DB::table('sms_messages')->value('body');
        $this->assertSame("Rs 1,25,000.00 deposited to General savings account …{$this->lastTwo($account->account_number)} on 2026-10-16. Balance Rs 1,25,000.00.", $body);

        // A rolled-back deposit leaves no message.
        try {
            DB::transaction(function () use ($account) {
                $this->inCoop(fn () => app(SavingsService::class)->deposit($account->refresh(), '1.00', $this->user, 'sms-dep-2'));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(1, DB::table('sms_messages')->count());

        Log::spy();
        $this->artisan('sms:send')->expectsOutputToContain('Sent 1, failed 0.')->assertSuccessful();
        $this->assertSame('sent', DB::table('sms_messages')->value('status'));
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => $ctx['to'] === '980*****12');
        $this->getJson('/api/sms-messages')->assertOk()->assertJsonPath('data.0.status', 'sent');
    }

    public function test_unknown_driver_fails_after_retries(): void
    {
        DB::table('sms_messages')->insert(['cooperative_id' => $this->branch->cooperative_id, 'phone' => '9800000000', 'body' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        config(['services.sms.driver' => 'sparrow']);

        for ($i = 0; $i < 5; $i++) {
            $this->artisan('sms:send')->assertSuccessful();
        }
        $row = DB::table('sms_messages')->first();
        $this->assertSame(['failed', 5], [$row->status, (int) $row->attempts]);
        $this->assertSame("SMS driver 'sparrow' is not implemented.", $row->last_error);
    }

    public function test_amount_formatting_is_exact(): void
    {
        $this->assertSame('Rs 12,34,56,78,90,12,345.67', SmsOutbox::rs('123456789012345.67'));
        $this->assertSame('Rs 999.00', SmsOutbox::rs('999'));
    }

    private function lastTwo(int $n): string
    {
        return substr((string) $n, -2);
    }
}
