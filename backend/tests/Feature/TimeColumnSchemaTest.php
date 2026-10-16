<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Time columns must be DATETIME (no 2038 limit, no connection-timezone
 * conversion) and Unix-time integers must be unsigned. Queue tables are
 * intentionally excluded for now.
 */
class TimeColumnSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const DATETIME_COLUMNS = [
        'users' => ['created_at', 'updated_at', 'email_verified_at'],
        'password_reset_tokens' => ['created_at'],
        'platform_users' => ['created_at', 'updated_at'],
        'cooperatives' => ['created_at', 'updated_at'],
        'branches' => ['created_at', 'updated_at'],
        'cooperative_user' => ['created_at', 'updated_at'],
    ];

    public function test_time_columns_are_datetime(): void
    {
        foreach (self::DATETIME_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame('datetime', Schema::getColumnType($table, $column), "{$table}.{$column}");
            }
        }
    }

    public function test_session_last_activity_is_unsigned_int(): void
    {
        $column = collect(Schema::getColumns('sessions'))->firstWhere('name', 'last_activity');

        $this->assertSame('int unsigned', $column['type']);
    }

    public function test_user_timestamps_accept_dates_after_january_2038(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => '2038-01-19 03:14:08',
            'created_at' => '2100-01-01 00:00:00',
        ]);

        $this->assertSame('2038-01-19 03:14:08', $user->fresh()->email_verified_at->format('Y-m-d H:i:s'));
        $this->assertSame('2100-01-01 00:00:00', $user->fresh()->created_at->format('Y-m-d H:i:s'));
    }

    public function test_password_reset_created_at_accepts_dates_after_january_2038(): void
    {
        DB::table('password_reset_tokens')->insert([
            'email' => 'reset@example.test', 'token' => 'synthetic', 'created_at' => '2038-01-19 03:14:08',
        ]);

        $this->assertSame('2038-01-19 03:14:08', DB::table('password_reset_tokens')->value('created_at'));
    }

    public function test_session_last_activity_accepts_unix_time_after_january_2038_and_rejects_negative(): void
    {
        DB::table('sessions')->insert(['id' => 's1', 'payload' => 'x', 'last_activity' => 2147483648]);
        $this->assertSame(2147483648, (int) DB::table('sessions')->value('last_activity'));

        try {
            DB::table('sessions')->insert(['id' => 's2', 'payload' => 'x', 'last_activity' => -1]);
            $this->fail('Negative last_activity was accepted.');
        } catch (QueryException $e) {
            $this->assertSame(1264, $e->errorInfo[1]); // out of range
        }
    }
}
