<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/** Forks OS processes (own MySQL connections) that start together. Needs committed data (DatabaseMigrations). */
trait RunsInParallel
{
    /** @return list<'ok'|'failed'> */
    protected function inParallel(int $workers, callable $work): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }
        DB::disconnect();
        $start = microtime(true) + 0.5;
        $pids = [];

        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                time_sleep_until($start);
                try {
                    $work($i);
                    $ok = true;
                } catch (\Throwable) {
                    $ok = false;
                }
                posix_kill(getmypid(), $ok ? SIGKILL : SIGTERM); // skip PHPUnit shutdown in the child
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
}
