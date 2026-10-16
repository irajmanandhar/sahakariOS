<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Liveness/readiness probe used by Docker healthchecks and monitoring.
 *
 * Reports only "ok"/"fail" per dependency; error details go to the log,
 * never to the response, so the endpoint can stay unauthenticated.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->probe(fn () => DB::select('SELECT 1')),
            'redis' => $this->probe(fn () => Redis::connection()->ping()),
        ];

        $healthy = ! in_array('fail', $checks, true);

        return response()->json(
            ['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks],
            $healthy ? 200 : 503,
        );
    }

    private function probe(callable $check): string
    {
        try {
            $check();

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'fail';
        }
    }
}
