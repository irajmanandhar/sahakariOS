<?php

namespace App\Support;

use App\Accounting\LedgerMappings;
use App\Accounting\Money;
use App\Accounting\PostingService;
use App\Calendar\BusinessDayService;
use App\Models\Cooperative;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Shares\ShareService;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pilot migration: members with their opening share and savings balances.
 *
 * CSV header: member_number,full_name,date_of_birth,gender,phone,membership_date,share_units,savings_product_code,savings_balance
 * (dates AD YYYY-MM-DD; date_of_birth and gender may be blank; one savings account per row, optional).
 * Members only (no balances) need no open business day, so passbook history can be
 * replayed afterwards with migration:import-history.
 *
 * - All rows are validated before anything is written; any error aborts.
 * - One journal entry per member, dated the open business day:
 *   Dr opening balance / Cr share capital, Cr savings product account (PROPOSED).
 * - Historical balances are imported as they are: the 20% share and savings
 *   limits apply to new transactions only.
 * - Imported members have no KYM yet: their KYM review is due immediately.
 * - Re-running skips members already imported (idempotent per member).
 */
class ImportOpeningBalances extends Command
{
    protected $signature = 'migration:import {cooperative : cooperative code} {file : CSV path} {--dry-run : validate only}';

    protected $description = 'Import members and opening share/savings balances from a CSV (pilot migration)';

    private const HEADER = ['member_number', 'full_name', 'date_of_birth', 'gender', 'phone', 'membership_date', 'share_units', 'savings_product_code', 'savings_balance'];

    public function handle(TenantContext $tenant): int
    {
        $coop = Cooperative::query()->where('code', $this->argument('cooperative'))->first();
        if (! $coop) {
            $this->error('Unknown cooperative code.');

            return self::FAILURE;
        }
        $branch = DB::table('branches')->where('cooperative_id', $coop->id)->where('is_head_office', true)->first();

        try {
            $rows = Csv::read((string) $this->argument('file'), self::HEADER);

            return $tenant->runAs($coop->id, $branch->id, fn () => $this->import($rows));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function import(array $rows): int
    {
        $errors = $this->validate($rows);
        if ($errors !== []) {
            foreach ($errors as $e) {
                $this->error($e);
            }
            $this->error(count($errors).' error(s): nothing imported.');

            return self::FAILURE;
        }

        $imported = $skipped = 0;
        try {
            DB::transaction(function () use ($rows, &$imported, &$skipped) {
                foreach ($rows as $r) {
                    if (Member::query()->where('member_number', $r['member_number'])->exists()) {
                        $skipped++;

                        continue;
                    }
                    $this->importRow($r);
                    $imported++;
                }
                if ($this->option('dry-run')) {
                    throw new DryRunRollback;
                }
            }, 1);
        } catch (DryRunRollback) {
            $this->info("[dry run] {$imported} member(s) would be imported, {$skipped} skipped. Nothing was written.");

            return self::SUCCESS;
        }

        return $this->summary($imported, $skipped);
    }

    private function importRow(array $r): void
    {
        $tenant = app(TenantContext::class);
        $member = Member::query()->forceCreate([
            'cooperative_id' => $tenant->cooperativeId(), 'branch_id' => $tenant->branchId(), 'member_number' => (int) $r['member_number'],
            'status' => 'active', 'full_name' => $r['full_name'], 'date_of_birth' => $r['date_of_birth'] ?: null, 'gender' => $r['gender'] ?: null,
            'phone' => $r['phone'] ?: null, 'declares_nepali_citizen' => true, 'declares_resides_in_working_area' => true,
            'declares_no_other_same_type_membership' => true, 'applied_on' => $r['membership_date'], 'decided_at' => $r['membership_date'].' 00:00:00',
            'decision_reason' => 'Imported from previous system', 'next_kyc_review_on' => BusinessDayService::today(), // KYM due at once
        ]);
        DB::statement('INSERT INTO member_sequences (cooperative_id, last_number) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))', [$member->cooperative_id, $member->member_number]);

        $shares = BigDecimal::of(ShareService::FACE_VALUE)->multipliedBy((int) ($r['share_units'] ?: 0))->toScale(2);
        $savings = Money::parse($r['savings_balance'] ?: '0');
        $total = $shares->plus($savings);
        if ($total->isZero()) {
            return;
        }
        $mappings = app(LedgerMappings::class);
        $product = $r['savings_product_code'] ? SavingsProduct::query()->where('code', $r['savings_product_code'])->first() : null;
        $lines = [['ledger_account_id' => $mappings->account('opening_balance')->id, 'debit' => (string) $total]];
        if ($shares->isPositive()) {
            $lines[] = ['ledger_account_id' => $mappings->account('share_capital')->id, 'credit' => (string) $shares];
        }
        if ($savings->isPositive()) {
            $lines[] = ['ledger_account_id' => $product->ledger_account_id, 'credit' => (string) $savings];
        }
        $entry = app(PostingService::class)->post("Opening balances (migration): member {$member->member_number}", $lines,
            null, "migration:{$member->cooperative_id}:{$member->member_number}", ['type' => 'migration', 'id' => $member->id]);

        if ($shares->isPositive()) {
            DB::table('share_transactions')->insert(['cooperative_id' => $member->cooperative_id, 'member_id' => $member->id, 'type' => 'purchase',
                'units' => (int) $r['share_units'], 'amount' => (string) $shares, 'journal_entry_id' => $entry->id,
                'source_of_funds' => 'Opening balance (previous system)', 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($product) {
            $account = SavingsAccount::query()->forceCreate([
                'cooperative_id' => $member->cooperative_id, 'member_id' => $member->id, 'savings_product_id' => $product->id,
                'account_number' => NumberSequence::next($member->cooperative_id, 'savings_account'), 'opened_on' => $r['membership_date'],
                'balance' => (string) $savings,
            ]);
            if ($savings->isPositive()) {
                DB::table('savings_transactions')->insert(['cooperative_id' => $member->cooperative_id, 'savings_account_id' => $account->id,
                    'type' => 'deposit', 'channel' => 'system', 'amount' => (string) $savings, 'balance_after' => (string) $savings,
                    'journal_entry_id' => $entry->id, 'narration' => 'Opening balance (previous system)', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /** @return list<string> */
    private function validate(array $rows): array
    {
        $errors = [];
        $seen = [];
        foreach (['opening_balance', 'share_capital'] as $role) {
            try {
                app(LedgerMappings::class)->account($role);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        $hasBalances = collect($rows)->contains(fn ($r) => (int) $r['share_units'] > 0 || (preg_match(Money::PATTERN, $r['savings_balance']) && ! BigDecimal::of($r['savings_balance'])->isZero()));
        if ($hasBalances && ! app(BusinessDayService::class)->current()) {
            $errors[] = 'Open the business day (the cut-over date) first.';
        }
        foreach ($rows as $i => $r) {
            $n = $i + 2;
            $add = function (string $msg) use (&$errors, $n) {
                $errors[] = "Row {$n}: {$msg}";
            };
            if (! ctype_digit((string) $r['member_number']) || (int) $r['member_number'] < 1) {
                $add('member_number must be a positive whole number.');
            } elseif (isset($seen[$r['member_number']])) {
                $add("duplicate member_number {$r['member_number']}.");
            }
            $seen[$r['member_number']] = true;
            if (trim($r['full_name']) === '' || mb_strlen($r['full_name']) > 150) {
                $add('full_name is required (max 150).');
            }
            foreach (['date_of_birth', 'membership_date'] as $d) {
                if ($d === 'date_of_birth' && $r[$d] === '') {
                    continue; // unknown in old registers: collected at the KYM review
                }
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $r[$d]) || ! checkdate((int) substr($r[$d], 5, 2), (int) substr($r[$d], 8, 2), (int) substr($r[$d], 0, 4))) {
                    $add("{$d} must be an AD date YYYY-MM-DD.");
                }
            }
            if (! in_array($r['gender'], ['', 'female', 'male', 'other'], true)) {
                $add('gender must be female, male, other or blank.');
            }
            if ($r['share_units'] !== '' && ! ctype_digit($r['share_units'])) {
                $add('share_units must be a whole number.');
            }
            if ($r['savings_balance'] !== '' && ! preg_match(Money::PATTERN, $r['savings_balance'])) {
                $add('savings_balance must be an amount with at most 2 decimals.');
            }
            if ($r['savings_product_code'] !== '' && ! SavingsProduct::query()->where('code', $r['savings_product_code'])->exists()) {
                $add("unknown savings product {$r['savings_product_code']}.");
            }
            if ($r['savings_product_code'] === '' && $r['savings_balance'] !== '' && ! BigDecimal::of($r['savings_balance'])->isZero()) {
                $add('a savings balance needs a savings_product_code.');
            }
        }

        return $errors;
    }

    private function summary(int $imported, int $skipped): int
    {
        $suspense = DB::table('journal_lines')->where('cooperative_id', app(TenantContext::class)->cooperativeId())
            ->where('ledger_account_id', app(LedgerMappings::class)->account('opening_balance')->id)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS b')->value('b');
        $this->info(($this->option('dry-run') ? '[dry run] ' : '')."Imported {$imported} member(s), skipped {$skipped} already imported.");
        $this->info('Opening balance account now: '.Money::format((string) $suspense).' (debit). Post the remaining old-system balances against it until it is 0.00.');

        return self::SUCCESS;
    }
}

/** @internal rolls back a dry run */
final class DryRunRollback extends RuntimeException {}
