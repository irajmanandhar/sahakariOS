<?php

namespace App\Support;

use App\Accounting\LedgerMappings;
use App\Accounting\Money;
use App\Accounting\PostingService;
use App\Calendar\BsCalendar;
use App\Calendar\BusinessDayService;
use App\Compliance\CashMovements;
use App\Models\BusinessDay;
use App\Models\Cooperative;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Savings\SavingsService;
use App\Shares\ShareService;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Backdated bulk upload: replays passbook history from the previous system,
 * day by day, so the books, passbooks and audit trail start from the old
 * opening balances instead of a single cut-over figure.
 *
 * CSV header: date_ad,date_bs,voucher_no,member_number,product_code,legacy_account_no,type,amount,particulars
 * - date_ad YYYY-MM-DD, or leave it blank and give date_bs (YYYY-MM-DD BS); if both are given they must agree;
 * - product_code: a savings product code, or SHARE for the share register;
 * - type: opening, deposit, withdrawal, interest, tax, charge (SHARE: opening, deposit, withdrawal);
 * - amount: positive, at most 2 decimals; legacy_account_no: the old passbook's account number.
 *
 * Rules:
 * - every row is validated (including running balances) before anything is written; any error imports nothing;
 * - the whole replay is one database transaction: for each date it opens the business day, posts that day's
 *   rows and closes the day, so the dates must be after the last business day and no day may be open;
 * - postings (PROPOSED, as for the counter): opening Dr opening balance; deposit Dr cash; withdrawal Cr cash;
 *   interest Dr savings interest expense; tax Cr interest tax payable; charge Cr service charge income;
 *   the other side is the product's savings account (or share capital);
 * - interest for the imported period is the old system's interest rows: the app's own interest accrual for
 *   imported accounts starts the day after the last imported date;
 * - historical cash over the AML thresholds is imported as recorded and listed for review, never blocked;
 * - --expected reconciles every account's final balance with the old system and refuses any difference.
 */
class ImportPassbookHistory extends Command
{
    protected $signature = 'migration:import-history {cooperative : cooperative code} {file : CSV path}
        {--expected= : CSV product_code,legacy_account_no,member_number,balance to reconcile against}
        {--dry-run : validate and replay, then roll everything back}';

    protected $description = 'Backdated bulk upload: replay passbook history (savings and shares) from the previous system';

    public const HEADER = ['date_ad', 'date_bs', 'voucher_no', 'member_number', 'product_code', 'legacy_account_no', 'type', 'amount', 'particulars'];

    public const EXPECTED_HEADER = ['product_code', 'legacy_account_no', 'member_number', 'balance'];

    public const SHARE = 'SHARE';

    /** Sign of each type on the account balance; credits sort before debits on the same day. */
    private const TYPES = ['opening' => 1, 'deposit' => 1, 'interest' => 1, 'withdrawal' => -1, 'tax' => -1, 'charge' => -1];

    private const SHARE_TYPES = ['opening', 'deposit', 'withdrawal'];

    private const ROLE_FOR = ['opening' => 'opening_balance', 'deposit' => 'cash', 'withdrawal' => 'cash', 'interest' => 'savings_interest_expense',
        'tax' => 'interest_tax_payable', 'charge' => 'service_charge_income'];

    /** @var array<int, Member> */
    private array $members = [];

    /** @var array<string, SavingsProduct> */
    private array $products = [];

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
            $expected = $this->option('expected') ? Csv::read((string) $this->option('expected'), self::EXPECTED_HEADER) : null;

            return $tenant->runAs($coop->id, $branch->id, fn () => $this->import($rows, $expected));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function import(array $rows, ?array $expected): int
    {
        [$rows, $errors] = $this->validate($rows);
        if ($errors !== []) {
            return $this->refuse($errors);
        }

        $key = 'history:'.hash_file('sha256', (string) $this->argument('file'));
        try {
            DB::transaction(function () use ($rows, $expected, $key) {
                $this->replay($rows, $key);
                if ($expected !== null && ($differences = $this->reconcile($expected)) !== []) {
                    throw new ImportRefused($differences);
                }
                if ($this->option('dry-run')) {
                    throw new ImportRefused([]);
                }
            });
        } catch (ImportRefused $e) {
            if ($e->errors !== []) {
                return $this->refuse($e->errors);
            }
            $this->summary($rows, true);

            return self::SUCCESS;
        }
        $this->summary($rows, false);

        return self::SUCCESS;
    }

    /** @return array{0: list<array>, 1: list<string>} rows sorted for replay, and errors */
    private function validate(array $rows): array
    {
        $errors = [];
        $calendar = app(BsCalendar::class);
        $add = function (int $line, string $msg) use (&$errors) {
            $errors[] = "Row {$line}: {$msg}";
        };
        if ($rows === []) {
            return [[], ['The file has no rows.']];
        }
        if (app(BusinessDayService::class)->current()) {
            $errors[] = 'Close the open business day first: history is replayed day by day.';
        }
        $this->members = Member::query()->whereIn('member_number', array_unique(array_map('intval', array_column($rows, 'member_number'))))->get()->keyBy('member_number')->all();
        $this->products = SavingsProduct::query()->get()->keyBy('code')->all();

        foreach ($rows as $i => &$r) {
            $line = $i + 2;
            $r['line'] = $line;
            if ($r['date_ad'] === '' && $r['date_bs'] !== '') {
                try {
                    $bs = $calendar->parseBs($r['date_bs']);
                    $r['date_ad'] = $calendar->toAd($bs->year, $bs->month, $bs->day)->format('Y-m-d');
                } catch (Throwable) {
                    $add($line, "date_bs {$r['date_bs']} is not a date in the verified BS calendar.");
                }
            } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['date_ad']) || ! checkdate((int) substr($r['date_ad'], 5, 2), (int) substr($r['date_ad'], 8, 2), (int) substr($r['date_ad'], 0, 4))) {
                $add($line, 'date_ad must be an AD date YYYY-MM-DD (or give date_bs).');
            } elseif ($r['date_bs'] !== '') {
                try {
                    if ((string) $calendar->toBs($r['date_ad']) !== (string) $calendar->parseBs($r['date_bs'])) {
                        $add($line, "date_bs {$r['date_bs']} is BS ".$calendar->toBs($r['date_ad'])." for {$r['date_ad']}.");
                    }
                } catch (Throwable) {
                    $add($line, "date_bs {$r['date_bs']} is not a date in the verified BS calendar.");
                }
            }
            if (! isset($this->members[(int) $r['member_number']])) {
                $add($line, "member {$r['member_number']} does not exist (import members first).");
            }
            $isShare = strtoupper($r['product_code']) === self::SHARE;
            if ($isShare) {
                $r['product_code'] = self::SHARE;
            } elseif (! isset($this->products[$r['product_code']])) {
                $add($line, "unknown savings product {$r['product_code']}.");
            } elseif ($r['legacy_account_no'] === '' || mb_strlen($r['legacy_account_no']) > 30) {
                $add($line, 'legacy_account_no is required (max 30).');
            }
            if (! in_array($r['type'], $isShare ? self::SHARE_TYPES : array_keys(self::TYPES), true)) {
                $add($line, "type {$r['type']} is not allowed for ".($isShare ? 'shares' : 'savings').'.');
            }
            if (! preg_match(Money::PATTERN, $r['amount']) || BigDecimal::of($r['amount'])->isZero()) {
                $add($line, 'amount must be greater than zero with at most 2 decimals.');
            } elseif ($isShare && ! BigDecimal::of($r['amount'])->remainder(ShareService::FACE_VALUE)->isZero()) {
                $add($line, 'share amounts must be whole shares of Rs '.ShareService::FACE_VALUE.'.');
            }
            if (mb_strlen($r['voucher_no']) > 30 || mb_strlen($r['particulars']) > 300) {
                $add($line, 'voucher_no max 30 and particulars max 300 characters.');
            }
            $r['account'] = $isShare ? self::SHARE.':'.(int) $r['member_number'] : $r['product_code'].':'.$r['legacy_account_no'];
        }
        unset($r);
        if ($errors !== []) {
            return [[], $errors];
        }

        $order = ['opening' => 0, 'deposit' => 1, 'interest' => 1, 'withdrawal' => 2, 'tax' => 2, 'charge' => 2];
        usort($rows, fn ($a, $b) => [$a['date_ad'], $order[$a['type']]] <=> [$b['date_ad'], $order[$b['type']]]); // stable: file order otherwise

        $last = BusinessDay::query()->max('business_date');
        if ($last !== null && $rows[0]['date_ad'] <= $last) {
            $errors[] = "History must start after the last business day ({$last}); the first row is dated {$rows[0]['date_ad']}.";
        }
        $balances = $owners = [];
        foreach ($rows as $r) {
            $account = $r['account'];
            if ($r['type'] === 'opening' && isset($balances[$account])) {
                $add($r['line'], "the opening balance of {$account} must be its first row.");
            }
            if (isset($owners[$account]) && $owners[$account] !== (int) $r['member_number']) {
                $add($r['line'], "account {$account} belongs to member {$owners[$account]}.");
            }
            $owners[$account] = (int) $r['member_number'];
            $balances[$account] = ($balances[$account] ?? BigDecimal::zero())->plus(BigDecimal::of($r['amount'])->multipliedBy(self::TYPES[$r['type']]));
            if ($balances[$account]->isNegative()) {
                $add($r['line'], "the balance of {$account} would go below zero on {$r['date_ad']}.");
            }
        }
        foreach (array_keys($owners) as $account) {
            [$product, $legacy] = explode(':', $account, 2);
            if ($product !== self::SHARE && SavingsAccount::query()->where('savings_product_id', $this->products[$product]->id)->where('legacy_account_number', $legacy)->exists()) {
                $errors[] = "Account {$account} was already imported.";
            }
        }
        $roles = array_map(fn ($r) => self::ROLE_FOR[$r['type']], $rows);
        if (in_array(self::SHARE, array_column($rows, 'product_code'), true)) {
            $roles[] = 'share_capital';
        }
        foreach (array_unique($roles) as $role) {
            try {
                app(LedgerMappings::class)->account($role);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [$rows, array_values(array_unique($errors))];
    }

    private function replay(array $rows, string $key): void
    {
        $days = app(BusinessDayService::class);
        $mappings = app(LedgerMappings::class);
        $posting = app(PostingService::class);
        $cash = app(CashMovements::class);
        $lastDate = end($rows)['date_ad'];
        $accounts = [];

        foreach (collect($rows)->groupBy('date_ad') as $date => $dayRows) {
            $days->open($date);
            foreach ($dayRows as $r) {
                $member = $this->members[(int) $r['member_number']];
                $amount = (string) BigDecimal::of($r['amount'])->toScale(2);
                $credit = self::TYPES[$r['type']] > 0;
                $isShare = $r['product_code'] === self::SHARE;
                $holder = $isShare ? $mappings->account('share_capital')->id : $this->products[$r['product_code']]->ledger_account_id;
                $other = $mappings->account(self::ROLE_FOR[$r['type']])->id;
                $lines = $credit
                    ? [['ledger_account_id' => $other, 'debit' => $amount], ['ledger_account_id' => $holder, 'credit' => $amount]]
                    : [['ledger_account_id' => $holder, 'debit' => $amount], ['ledger_account_id' => $other, 'credit' => $amount]];
                $what = $isShare ? 'shares' : "{$r['product_code']} account {$r['legacy_account_no']}";
                $entry = $posting->post(mb_substr(ucfirst($r['type'])." (previous system): member {$member->member_number}, {$what}".($r['voucher_no'] !== '' ? ", voucher {$r['voucher_no']}" : ''), 0, 500),
                    $lines, null, "{$key}:{$r['line']}", ['type' => 'migration_history', 'id' => $member->id]);
                $isCash = in_array($r['type'], ['deposit', 'withdrawal'], true);

                if ($isShare) {
                    $id = DB::table('share_transactions')->insertGetId(['cooperative_id' => $member->cooperative_id, 'member_id' => $member->id,
                        'type' => $credit ? 'purchase' : 'refund', 'units' => (int) (string) BigDecimal::of($amount)->dividedBy(ShareService::FACE_VALUE, 0),
                        'amount' => $amount, 'reference' => $r['voucher_no'] ?: null, 'journal_entry_id' => $entry->id,
                        'source_of_funds' => $r['type'] === 'opening' ? 'Opening balance (previous system)' : null, 'created_at' => now(), 'updated_at' => now()]);
                    if ($isCash) {
                        $cash->record($member, $entry, $credit ? 'in' : 'out', $amount, $credit ? 'share_purchase' : 'share_refund', $id);
                    }

                    continue;
                }

                $account = $accounts[$r['account']] ??= SavingsAccount::query()->forceCreate([
                    'cooperative_id' => $member->cooperative_id, 'member_id' => $member->id, 'savings_product_id' => $this->products[$r['product_code']]->id,
                    'account_number' => NumberSequence::next($member->cooperative_id, 'savings_account'), 'legacy_account_number' => $r['legacy_account_no'],
                    'opened_on' => $date, 'interest_accrued_through' => $lastDate, // old system's interest rows cover the imported period
                ])->refresh();
                $balance = BigDecimal::of($account->balance)->plus(BigDecimal::of($amount)->multipliedBy($credit ? 1 : -1));
                $account->forceFill(['balance' => (string) $balance])->save();
                $id = DB::table('savings_transactions')->insertGetId(['cooperative_id' => $member->cooperative_id, 'savings_account_id' => $account->id,
                    'type' => $r['type'] === 'opening' ? 'deposit' : $r['type'], 'channel' => $isCash ? 'cash' : 'system', 'amount' => $amount,
                    'balance_after' => (string) $balance, 'journal_entry_id' => $entry->id,
                    'narration' => $r['type'] === 'opening' ? 'Opening balance (previous system)' : ($r['particulars'] ?: null),
                    'reference' => $r['voucher_no'] ?: null, 'created_at' => now(), 'updated_at' => now()]);
                if ($isCash) {
                    $cash->record($member, $entry, $credit ? 'in' : 'out', $amount, 'savings_'.$r['type'], $id);
                }
            }
            $days->close();
        }
    }

    /** @return list<string> differences between the replayed and the old system's final balances */
    private function reconcile(array $expected): array
    {
        $differences = [];
        foreach ($expected as $i => $e) {
            $want = preg_match('/^-?\d{1,16}(\.\d{1,2})?$/', $e['balance']) ? BigDecimal::of($e['balance']) : null;
            if ($want === null) {
                $differences[] = 'Expected row '.($i + 2).': balance must be an amount.';

                continue;
            }
            if (strtoupper($e['product_code']) === self::SHARE) {
                $member = Member::query()->where('member_number', (int) $e['member_number'])->first();
                $have = BigDecimal::of(ShareService::FACE_VALUE)->multipliedBy($member ? app(ShareService::class)->units($member) : 0);
                $label = "SHARE member {$e['member_number']}";
            } else {
                $product = $this->products[$e['product_code']] ?? null;
                $have = BigDecimal::of($product ? (SavingsAccount::query()->where('savings_product_id', $product->id)->where('legacy_account_number', $e['legacy_account_no'])->value('balance') ?? '0') : '0');
                $label = "{$e['product_code']} account {$e['legacy_account_no']}";
            }
            if (! $have->isEqualTo($want)) {
                $differences[] = "{$label}: imported ".Money::format((string) $have->toScale(2)).', previous system '.Money::format((string) $want->toScale(2)).'.';
            }
        }

        return $differences;
    }

    private function summary(array $rows, bool $dryRun): void
    {
        $prefix = $dryRun ? '[dry run] ' : '';
        $byType = array_count_values(array_column($rows, 'type'));
        ksort($byType);
        $this->info($prefix.sprintf('Replayed %d row(s) over %d business day(s), %s to %s, for %d account(s): %s.',
            count($rows), count(array_unique(array_column($rows, 'date_ad'))), $rows[0]['date_ad'], end($rows)['date_ad'],
            count(array_unique(array_column($rows, 'account'))), implode(', ', array_map(fn ($t, $n) => "{$t} {$n}", array_keys($byType), $byType))));

        $review = [];
        foreach ($rows as $r) {
            if ($r['type'] === 'deposit' && CashMovements::requiresSourceOfFunds($r['amount'])) {
                $review[] = "Row {$r['line']}: cash deposit of Rs {$r['amount']} on {$r['date_ad']} has no recorded source of funds (Act s.52क).";
            }
            if ($r['type'] === 'withdrawal' && $r['product_code'] !== self::SHARE && BigDecimal::of($r['amount'])->isGreaterThan(SavingsService::CASH_WITHDRAWAL_LIMIT)) {
                $review[] = "Row {$r['line']}: cash withdrawal of Rs {$r['amount']} on {$r['date_ad']} is above the Rs 5 lakh limit.";
            }
        }
        foreach ($review as $line) {
            $this->warn($line);
        }
        $this->info($prefix.count($review).' historical transaction(s) to review against the AML rules (imported as recorded).');
        if ($dryRun) {
            $this->info('[dry run] Nothing was written.');
        }
    }

    private function refuse(array $errors): int
    {
        foreach ($errors as $e) {
            $this->error($e);
        }
        $this->error(count($errors).' problem(s): nothing imported.');

        return self::FAILURE;
    }
}

/** @internal rolls the replay back with a reason (empty for a dry run) */
final class ImportRefused extends RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Import refused');
    }
}
