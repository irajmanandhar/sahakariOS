<?php

namespace Tests\Feature;

use App\Audit\AuditLogger;
use App\Authorization\Permission;
use App\Calendar\BusinessDayService;
use App\Calendar\FiscalYearService;
use App\Models\Branch;
use App\Models\Cooperative;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** Append-only, hash-chained audit log. Synthetic data only. */
class AuditLogTest extends TestCase
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
    }

    private function asCoop(callable $callback, ?Branch $branch = null): mixed
    {
        $branch ??= $this->branch;

        return app(TenantContext::class)->runAs($branch->cooperative_id, $branch->id, $callback);
    }

    private function entries(string $action)
    {
        return DB::table('audit_logs')->where('action', $action)->get();
    }

    public function test_login_success_and_failure_are_recorded_without_passwords(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'staff@example.test', 'password' => 'wrong-password-x']);
        $this->postJson('/api/auth/login', ['email' => 'staff@example.test', 'password' => self::PASSWORD])->assertOk();

        $failed = $this->entries('auth.login_failed')->sole();
        $this->assertSame('global', $failed->chain);
        $this->assertSame('system', $failed->actor_type);
        $this->assertSame(['guard' => 'web', 'email' => 'staff@example.test'], json_decode($failed->data, true));

        $login = $this->entries('auth.login')->sole();
        $this->assertSame(['user', $this->user->id], [$login->actor_type, (int) $login->actor_id]);

        $all = DB::table('audit_logs')->get()->toJson();
        $this->assertStringNotContainsString('wrong-password-x', $all);
        $this->assertStringNotContainsString(self::PASSWORD, $all);
    }

    public function test_business_day_changes_are_recorded_in_the_cooperative_chain_with_actor(): void
    {
        $this->asCoop(fn () => app(FiscalYearService::class)->open(2083));
        $this->asCoop(fn () => app(BusinessDayService::class)->open('2026-10-16', $this->user));

        $entry = $this->entries('business_day.opened')->sole();
        $this->assertSame('cooperative:'.$this->branch->cooperative_id, $entry->chain);
        $this->assertSame($this->branch->cooperative_id, (int) $entry->cooperative_id);
        $this->assertSame(['user', $this->user->id], [$entry->actor_type, (int) $entry->actor_id]);
        $this->assertSame('BusinessDay', $entry->subject_type);
    }

    public function test_audit_entry_rolls_back_with_the_change(): void
    {
        $this->asCoop(fn () => app(FiscalYearService::class)->open(2083));

        try {
            DB::transaction(function () {
                $this->asCoop(fn () => app(BusinessDayService::class)->open('2026-10-16', $this->user));
                throw new RuntimeException('later step failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertCount(0, $this->entries('business_day.opened'));
        $this->assertSame(0, DB::table('business_days')->count());
    }

    public function test_entries_are_chained_in_sequence(): void
    {
        $logger = app(AuditLogger::class);
        foreach (['a', 'b', 'c'] as $action) {
            $logger->record("test.{$action}", cooperativeId: $this->branch->cooperative_id);
        }

        $rows = DB::table('audit_logs')->orderBy('sequence')->get();
        $this->assertSame([1, 2, 3], $rows->pluck('sequence')->map(fn ($s) => (int) $s)->all());
        $this->assertSame(AuditLogger::GENESIS_HASH, $rows[0]->prev_hash);
        $this->assertSame($rows[0]->hash, $rows[1]->prev_hash);
        $this->assertSame($rows[1]->hash, $rows[2]->prev_hash);
    }

    public function test_database_refuses_update_and_delete(): void
    {
        app(AuditLogger::class)->record('test.entry', cooperativeId: false);

        foreach ([
            fn () => DB::table('audit_logs')->update(['action' => 'forged']),
            fn () => DB::table('audit_logs')->delete(),
        ] as $tamper) {
            try {
                $tamper();
                $this->fail('Audit log was modified');
            } catch (QueryException $e) {
                $this->assertSame(1644, $e->errorInfo[1]); // SIGNAL from trigger
            }
        }
    }

    public function test_verify_passes_on_an_intact_log(): void
    {
        app(AuditLogger::class)->record('test.one', cooperativeId: false);
        app(AuditLogger::class)->record('test.two', cooperativeId: $this->branch->cooperative_id, data: ['note' => 'नमस्ते']);

        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_verify_detects_an_edit_made_around_the_triggers(): void
    {
        app(AuditLogger::class)->record('test.one', cooperativeId: false);
        app(AuditLogger::class)->record('test.two', cooperativeId: false);

        // Simulate someone with schema rights bypassing the protection.
        DB::unprepared('DROP TRIGGER audit_logs_no_update');
        DB::table('audit_logs')->where('sequence', 1)->update(['action' => 'forged']);

        $this->artisan('audit:verify')->expectsOutputToContain('sequence 1 was modified')->assertFailed();
    }

    public function test_verify_detects_a_deleted_entry(): void
    {
        foreach (['one', 'two', 'three'] as $a) {
            app(AuditLogger::class)->record("test.{$a}", cooperativeId: false);
        }

        DB::unprepared('DROP TRIGGER audit_logs_no_delete');
        DB::table('audit_logs')->where('sequence', 2)->delete();

        $this->artisan('audit:verify')->expectsOutputToContain('missing entry before sequence 3')->assertFailed();
    }

    public function test_endpoint_lists_only_the_current_cooperatives_entries(): void
    {
        $other = Branch::factory()->headOffice()->for(Cooperative::factory())->create();
        app(AuditLogger::class)->record('test.mine', cooperativeId: $this->branch->cooperative_id);
        app(AuditLogger::class)->record('test.theirs', cooperativeId: $other->cooperative_id);
        $this->asCoop(function () {
            $role = Role::create(['key' => 'auditor', 'name' => 'Auditor']);
            $role->syncPermissions([Permission::AuditLogView]);
            $role->assignTo($this->user);
        });

        $this->postJson('/api/auth/login', ['email' => 'staff@example.test', 'password' => self::PASSWORD])->assertOk();
        $this->putJson('/api/auth/cooperative', ['cooperative_id' => $this->branch->cooperative_id])->assertOk();

        $actions = collect($this->getJson('/api/audit-logs')->assertOk()->json('data'))->pluck('action');
        $this->assertContains('test.mine', $actions);
        $this->assertContains('tenant.selected', $actions);
        $this->assertNotContains('test.theirs', $actions);
        $this->assertNotContains('auth.login', $actions); // global chain, not cooperative data
    }
}
