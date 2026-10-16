<?php

namespace Tests\Feature;

use App\Accounting\PostingService;
use App\Shares\ShareService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCooperative;
use Tests\TestCase;

class ShareTest extends TestCase
{
    use BuildsCooperative, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCooperative();
        $this->signIn();
    }

    private function buy(int $memberId, int $units, string $key, array $extra = [])
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson("/api/members/{$memberId}/shares", ['units' => $units] + $extra);
    }

    /** Spread shares over five members first so one member can stay within 20%. */
    private function seedHolders(int $count = 5, int $units = 10): void
    {
        for ($i = 0; $i < $count; $i++) {
            $m = $this->activeMember();
            $this->inCoop(fn () => DB::table('share_transactions')->insert([
                'cooperative_id' => $m->cooperative_id, 'member_id' => $m->id, 'type' => 'purchase', 'units' => $units,
                'amount' => bcmul((string) $units, ShareService::FACE_VALUE, 2),
                'journal_entry_id' => app(PostingService::class)->post('Opening shares (test)', [
                    ['account_code' => 'A1', 'debit' => bcmul((string) $units, '100', 2)],
                    ['account_code' => 'E1', 'credit' => bcmul((string) $units, '100', 2)],
                ])->id,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }

    public function test_purchase_posts_cash_and_share_capital(): void
    {
        $this->seedHolders();
        $m = $this->activeMember();

        $this->buy($m->id, 10, 'share-0001')->assertCreated()
            ->assertJsonPath('data.amount', '1000.00')->assertJsonPath('data.member_total_units', 10);

        $this->assertSame('6000.00', $this->balanceOf('A1'));
        $this->assertSame('-6000.00', $this->balanceOf('E1'));
        $this->getJson("/api/members/{$m->id}/shares")->assertJsonPath('data.units', 10)->assertJsonPath('data.amount', '1000.00');
    }

    public function test_twenty_percent_holding_limit(): void
    {
        $this->seedHolders(4, 10); // 40 shares held by others
        $m = $this->activeMember();

        $this->buy($m->id, 11, 'share-0002')->assertUnprocessable()
            ->assertJsonPath('message', 'After this purchase the member would hold 21.57% of all shares; the limit is 20% (Act s.63(2)).');
        $this->buy($m->id, 10, 'share-0003')->assertCreated(); // 10 of 50 = 20%
    }

    public function test_only_active_members(): void
    {
        $this->seedHolders();
        $applicant = $this->activeMember(['status' => 'applied', 'member_number' => null]);

        $this->buy($applicant->id, 1, 'share-0004')->assertUnprocessable()
            ->assertJsonPath('message', 'Shares can be sold to active members only (Act s.63(1)).');
    }

    public function test_source_of_funds_from_ten_lakh(): void
    {
        $this->seedHolders(5, 10000); // 10,000 more of 60,000 = 16.7%
        $m = $this->activeMember();

        $this->buy($m->id, 10000, 'share-0005')->assertUnprocessable()
            ->assertJsonPath('message', 'Source of funds is required for Rs 10 lakh or more (AML Instruction 8).');
        $this->buy($m->id, 10000, 'share-0006', ['source_of_funds' => 'Sale of land (deed attached)'])->assertCreated();
    }

    public function test_idempotent_retry_is_not_double_counted(): void
    {
        $this->seedHolders(4, 10);
        $m = $this->activeMember();

        $first = $this->buy($m->id, 10, 'share-0007')->assertCreated()->json('data.id');
        // A retry must not be refused by the 20% check (it would count its own shares twice).
        $this->buy($m->id, 10, 'share-0007')->assertCreated()->assertJsonPath('data.id', $first);
        $this->assertSame(1, DB::table('share_transactions')->where('member_id', $m->id)->count());

        $this->buy($m->id, 5, 'share-0007')->assertUnprocessable()
            ->assertJsonPath('message', 'This Idempotency-Key was already used for a different transaction.');
    }

    public function test_requires_mapping_and_key(): void
    {
        $this->seedHolders();
        $m = $this->activeMember();
        $this->postJson("/api/members/{$m->id}/shares", ['units' => 1])->assertUnprocessable();

        DB::table('ledger_mappings')->where('key', 'share_capital')->delete();
        $this->buy($m->id, 1, 'share-0008')->assertUnprocessable()
            ->assertJsonPath('message', "Configure the ledger account for 'share_capital' (Share capital (Act s.63)) first.");
    }

    public function test_threshold_report_aggregates_cash_per_member_per_day(): void
    {
        $this->seedHolders(5, 20000);
        $big = $this->activeMember();
        $small = $this->activeMember();
        $this->buy($big->id, 6000, 'share-0009')->assertCreated();                                     // 6 lakh
        $this->buy($big->id, 4000, 'share-0010', ['source_of_funds' => 'Salary'])->assertCreated();     // +4 lakh = 10 lakh
        $this->buy($small->id, 9999, 'share-0011')->assertCreated();                                    // 9,99,900

        $this->getJson('/api/reports/threshold-transactions')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.member_id', $big->id)
            ->assertJsonPath('data.0.total', '1000000.00')
            ->assertJsonPath('data.0.transactions', 2)
            ->assertJsonPath('data.0.direction', 'cash_deposit')
            ->assertJsonPath('data.0.report_by', '2026-10-31');
    }

    public function test_share_register_is_append_only(): void
    {
        $this->seedHolders(1);

        foreach (['share_transactions', 'member_cash_movements'] as $table) {
            if (DB::table($table)->doesntExist()) {
                continue;
            }
            try {
                DB::table($table)->update(['updated_at' => now()]);
                $this->fail("{$table} was modified");
            } catch (QueryException $e) {
                $this->assertSame(1644, $e->errorInfo[1]);
            }
        }
    }
}
