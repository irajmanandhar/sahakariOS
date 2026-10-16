<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_reports_ok_when_database_and_redis_are_reachable(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'checks' => ['database' => 'ok', 'redis' => 'ok'],
            ]);
    }

    public function test_reports_503_without_leaking_details_when_database_is_down(): void
    {
        DB::shouldReceive('select')->andThrow(new RuntimeException('secret connection detail'));

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('checks.database', 'fail')
            ->assertJsonPath('checks.redis', 'ok')
            ->assertDontSee('secret connection detail');
    }

    public function test_reports_503_when_redis_is_down(): void
    {
        Redis::shouldReceive('connection->ping')->andThrow(new RuntimeException('redis down'));

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.redis', 'fail');
    }
}
