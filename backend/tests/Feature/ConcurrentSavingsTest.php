<?php

namespace Tests\Feature;

use App\Accounting\PostingService;
use App\Models\SavingsAccount;
use App\Savings\SavingsService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCooperative;
use Tests\Support\RunsInParallel;
use Tests\TestCase;

/** Parallel withdrawals from one account must never overdraw it. */
class ConcurrentSavingsTest extends TestCase
{
    use BuildsCooperative, DatabaseMigrations, RunsInParallel;

    public function test_parallel_withdrawals_never_overdraw(): void
    {
        $this->buildCooperative();
        $account = $this->inCoop(function () {
            app(PostingService::class)->post('Share capital (test opening)', [['account_code' => 'A1', 'debit' => '1000000'], ['account_code' => 'E1', 'credit' => '1000000']]);
            $product = app(SavingsService::class)->createProduct(['code' => 'GEN', 'name' => 'General', 'type' => 'general', 'interest_rate' => '5', 'interest_posting' => 'yearly', 'ledger_account_code' => 'L1']);
            $account = app(SavingsService::class)->open($this->activeMember(), $product);
            app(SavingsService::class)->deposit($account, '1000.00', $this->user, 'seed-deposit');

            return $account;
        });

        $results = $this->inParallel(8, fn (int $i) => $this->inCoop(
            fn () => app(SavingsService::class)->withdraw(SavingsAccount::query()->find($account->id), '300.00', $this->user, "parallel-wd-{$i}"),
        ));

        $this->assertSame(3, count(array_filter($results, fn ($r) => $r === 'ok')), 'exactly three 300.00 withdrawals fit in 1000.00');
        $this->assertSame('100.00', DB::table('savings_accounts')->where('id', $account->id)->value('balance'));
        $this->assertSame(3, DB::table('savings_transactions')->where('type', 'withdrawal')->count());
        $this->artisan('ledger:verify')->assertSuccessful();
    }
}
