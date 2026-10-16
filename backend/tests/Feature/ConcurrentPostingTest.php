<?php

namespace Tests\Feature;

use App\Accounting\LedgerAccountService;
use App\Accounting\PostingService;
use App\Calendar\BusinessDayService;
use App\Calendar\FiscalYearService;
use App\Models\Branch;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real parallelism: forks OS processes, each with its own MySQL connection,
 * posting at the same moment. Uses committed data (DatabaseMigrations), so it
 * is slower than the other tests.
 */
class ConcurrentPostingTest extends TestCase
{
    use DatabaseMigrations;

    private const WORKERS = 8;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        Date::setTestNow('2026-10-16 04:15:00');
        $this->branch = Branch::factory()->headOffice()->create();
        $this->asCoop(function () {
            app(FiscalYearService::class)->open(2083);
            app(BusinessDayService::class)->open('2026-10-16');
            $accounts = app(LedgerAccountService::class);
            $accounts->create('1', 'Assets (test)', 'asset', false);
            $accounts->create('1.1', 'Cash (test)', 'asset', true, '1');
            $accounts->create('2', 'Liabilities (test)', 'liability', false);
            $accounts->create('2.1', 'Savings (test)', 'liability', true, '2');
        });
    }

    private function asCoop(callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($this->branch->cooperative_id, $this->branch->id, $callback);
    }

    /** Run $work in WORKERS child processes started together; returns their exit codes. */
    private function inParallel(callable $work): array
    {
        DB::disconnect(); // children must not share the parent's connection
        $start = microtime(true) + 0.5;
        $pids = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                time_sleep_until($start); // line up all workers
                try {
                    $work($i);
                    $code = 0;
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e->getMessage()."\n");
                    $code = 1;
                }
                posix_kill(getmypid(), $code === 0 ? SIGKILL : SIGTERM); // skip PHPUnit shutdown in the child
            }
            $pids[] = $pid;
        }

        $results = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $results[] = pcntl_wtermsig($status) === SIGKILL ? 'ok' : 'failed';
        }
        DB::purge();

        return $results;
    }

    private function body(): array
    {
        return [['account_code' => '1.1', 'debit' => '100.00'], ['account_code' => '2.1', 'credit' => '100.00']];
    }

    public function test_same_idempotency_key_in_parallel_posts_exactly_once(): void
    {
        $results = $this->inParallel(fn () => $this->asCoop(
            fn () => app(PostingService::class)->post('Parallel deposit', $this->body(), null, 'parallel-same-key'),
        ));

        $this->assertSame(array_fill(0, self::WORKERS, 'ok'), $results, 'every request should get the same entry back');
        $this->assertSame(1, DB::table('journal_entries')->where('idempotency_key', 'parallel-same-key')->count());
        $this->assertSame(1, DB::table('journal_entries')->count());
    }

    public function test_parallel_postings_get_unique_consecutive_numbers(): void
    {
        $results = $this->inParallel(fn (int $i) => $this->asCoop(
            fn () => app(PostingService::class)->post("Parallel deposit {$i}", $this->body(), null, "parallel-key-{$i}"),
        ));

        $this->assertSame(array_fill(0, self::WORKERS, 'ok'), $results);
        $this->assertSame(range(1, self::WORKERS), DB::table('journal_entries')->orderBy('entry_number')->pluck('entry_number')->map(fn ($n) => (int) $n)->all());
        $this->artisan('ledger:verify')->assertSuccessful();
        $this->artisan('audit:verify')->assertSuccessful();
    }
}
