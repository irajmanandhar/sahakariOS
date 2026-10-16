<?php

namespace Tests\Feature;

use App\Calendar\BusinessDayService;
use App\Calendar\CalendarRuleViolation;
use App\Calendar\FiscalYearService;
use App\Calendar\OutOfCalendarRange;
use App\Models\Branch;
use App\Models\BusinessDay;
use App\Models\Cooperative;
use App\Models\User;
use App\Tenancy\MissingTenantContext;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Fiscal years and business days. Synthetic data; "now" is fixed. */
class BusinessDayTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-10-16 10:00 in Kathmandu (04:15 UTC) = BS 2083 Asoj 30.
        Date::setTestNow('2026-10-16 04:15:00');
        $this->branch = Branch::factory()->headOffice()->create();
    }

    private function asCoop(callable $callback, ?Branch $branch = null): mixed
    {
        $branch ??= $this->branch;

        return app(TenantContext::class)->runAs($branch->cooperative_id, $branch->id, $callback);
    }

    private function days(): BusinessDayService
    {
        return app(BusinessDayService::class);
    }

    private function openFiscalYear(int $year = 2083): void
    {
        $this->asCoop(fn () => app(FiscalYearService::class)->open($year));
    }

    private function assertViolation(string $message, callable $callback): void
    {
        try {
            $this->asCoop($callback);
            $this->fail("Expected rule violation: {$message}");
        } catch (CalendarRuleViolation $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_fiscal_year_dates_come_from_the_calendar(): void
    {
        $fy = $this->asCoop(fn () => app(FiscalYearService::class)->open(2082));

        $this->assertSame('2082/83', $fy->name());
        $this->assertSame('2025-07-17', $fy->start_date->format('Y-m-d'));
        $this->assertSame('2026-07-16', $fy->end_date->format('Y-m-d'));
    }

    public function test_current_fiscal_year_end_stays_unknown_until_official_calendar(): void
    {
        $fy = $this->asCoop(fn () => app(FiscalYearService::class)->open(2083));

        $this->assertSame('2026-07-17', $fy->start_date->format('Y-m-d'));
        $this->assertNull($fy->end_date);
    }

    public function test_fiscal_year_cannot_be_opened_twice(): void
    {
        $this->openFiscalYear();

        $this->assertViolation('already open', fn () => app(FiscalYearService::class)->open(2083));
    }

    public function test_opens_and_closes_a_business_day(): void
    {
        $this->openFiscalYear();
        $user = User::factory()->create();

        $day = $this->asCoop(fn () => $this->days()->open('2026-10-16', $user));
        $this->assertSame('open', $day->status);
        $this->assertSame($user->id, $day->opened_by);

        $closed = $this->asCoop(fn () => $this->days()->close($user));
        $this->assertSame('closed', $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertNull($this->asCoop(fn () => $this->days()->current()));
    }

    public function test_only_one_open_day_at_a_time(): void
    {
        $this->openFiscalYear();
        $this->asCoop(fn () => $this->days()->open('2026-10-15'));

        $this->assertViolation('Close the current business day', fn () => $this->days()->open('2026-10-16'));
    }

    public function test_database_also_refuses_a_second_open_day(): void
    {
        $this->openFiscalYear();
        $day = $this->asCoop(fn () => $this->days()->open('2026-10-15'));

        try {
            DB::table('business_days')->insert([
                'cooperative_id' => $day->cooperative_id, 'fiscal_year_id' => $day->fiscal_year_id,
                'business_date' => '2026-10-16', 'status' => 'open', 'opened_at' => now(),
            ]);
            $this->fail('Second open day was accepted');
        } catch (QueryException $e) {
            $this->assertSame(1062, $e->errorInfo[1]);
        }
    }

    public function test_days_only_move_forward(): void
    {
        $this->openFiscalYear();
        $this->asCoop(fn () => $this->days()->open('2026-10-15'));
        $this->asCoop(fn () => $this->days()->close());

        $this->assertViolation('must be after 2026-10-15', fn () => $this->days()->open('2026-10-15'));
        $this->assertViolation('must be after 2026-10-15', fn () => $this->days()->open('2026-10-14'));
    }

    public function test_cannot_open_a_future_day_in_nepal_time(): void
    {
        $this->openFiscalYear();
        // 18:00 UTC on the 16th is already 23:45 in Kathmandu; the 17th is still the future.
        Date::setTestNow('2026-10-16 18:00:00');

        $this->assertViolation('future', fn () => $this->days()->open('2026-10-17'));
    }

    public function test_nepal_date_is_used_not_utc(): void
    {
        // 19:00 UTC on the 16th = 00:45 on the 17th in Kathmandu.
        Date::setTestNow('2026-10-16 19:00:00');

        $this->assertSame('2026-10-17', BusinessDayService::today());
    }

    public function test_requires_an_open_fiscal_year_covering_the_date(): void
    {
        $this->assertViolation('No open fiscal year', fn () => $this->days()->open('2026-10-16'));

        $this->openFiscalYear(2082); // ends 2026-07-16
        $this->assertViolation('No open fiscal year', fn () => $this->days()->open('2026-10-16'));
    }

    public function test_closed_fiscal_year_does_not_accept_days(): void
    {
        $this->openFiscalYear();
        DB::table('fiscal_years')->update(['status' => 'closed']);

        $this->assertViolation('No open fiscal year', fn () => $this->days()->open('2026-10-16'));
    }

    public function test_cannot_close_when_nothing_is_open(): void
    {
        $this->assertViolation('no open business day', fn () => $this->days()->close());
    }

    public function test_dates_outside_verified_calendar_are_refused(): void
    {
        $this->openFiscalYear();
        Date::setTestNow('2027-05-01 04:15:00');

        $this->expectException(OutOfCalendarRange::class);
        $this->asCoop(fn () => $this->days()->open('2027-04-14')); // BS 2084: not verified yet
    }

    public function test_business_days_are_per_cooperative(): void
    {
        $other = Branch::factory()->headOffice()->for(Cooperative::factory())->create();
        $this->openFiscalYear();
        $this->asCoop(fn () => app(FiscalYearService::class)->open(2083), $other);

        $this->asCoop(fn () => $this->days()->open('2026-10-16'));
        $this->asCoop(fn () => $this->days()->open('2026-10-16'), $other); // independent

        $this->assertSame(1, $this->asCoop(fn () => BusinessDay::query()->count()));
        $this->assertSame(1, $this->asCoop(fn () => BusinessDay::query()->count(), $other));
    }

    public function test_service_refuses_to_run_without_a_tenant(): void
    {
        $this->expectException(MissingTenantContext::class);
        $this->days()->open('2026-10-16');
    }

    // --- HTTP ---------------------------------------------------------------------

    private function loginAndSelect(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.test', 'password' => 'correct-horse-battery-staple']);
        $this->branch->cooperative->users()->attach($user, ['branch_id' => $this->branch->id]);
        $this->postJson('/api/auth/login', ['email' => 'staff@example.test', 'password' => 'correct-horse-battery-staple'])->assertOk();
        $this->putJson('/api/auth/cooperative', ['cooperative_id' => $this->branch->cooperative_id])->assertOk();
    }

    public function test_endpoint_reports_current_business_day_in_ad_and_bs(): void
    {
        $this->openFiscalYear();
        $this->asCoop(fn () => $this->days()->open('2026-10-16'));
        $this->loginAndSelect();

        $this->getJson('/api/business-day')->assertOk()->assertExactJson(['data' => [
            'date' => '2026-10-16',
            'date_bs' => '2083-06-30',
            'date_bs_text' => '30 Asoj 2083',
            'fiscal_year' => '2083/84',
            'status' => 'open',
        ]]);
    }

    public function test_endpoint_returns_null_when_no_day_is_open(): void
    {
        $this->loginAndSelect();

        $this->getJson('/api/business-day')->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_endpoint_requires_tenant_context(): void
    {
        $this->getJson('/api/business-day')->assertUnauthorized();
    }
}
