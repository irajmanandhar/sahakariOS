<?php

namespace App\Support;

use App\Calendar\BsCalendar;
use App\Calendar\BusinessDayService;
use DateTimeInterface;
use Illuminate\Console\Command;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Converts the legacy "passbook per member" Excel workbook used by many
 * cooperatives into the standard import files, and lists what an auditor
 * should look at. It writes nothing to the database.
 *
 * Workbook layout (one sheet per product, one block of columns per account):
 * - row 5 account type, row 7 "A/c No.<n> ... Member No.<m>", row 8 "Interest Rate:<r>%";
 * - the "brought forward" row holds the opening balance in the Balance column;
 * - then one row per calendar day: Nepali date, day, English date, voucher, particulars,
 *   Debit, Credit, Can Withdraw, Balance, Interest, Tax (12 columns per account);
 * - loan sheets use 17 columns: ... Debit, Credit, Balance, Days, Percent, Interest,
 *   Interest charged, Interest paid, Interest balance, Fine, Last date;
 * - a "Member List" sheet with M/No, Name, ..., Date, Phone No., ..., Kharej (cancelled).
 *
 * Output (into the given folder, which must not be inside Git; /imports/ is ignored):
 * members.csv (for migration:import), history.csv (for migration:import-history),
 * expected_balances.csv (for --expected), products.csv, loans.csv (reference only) and findings.txt.
 */
class ConvertLegacyWorkbook extends Command
{
    protected $signature = 'migration:convert-excel {file : legacy .xlsx workbook} {out : folder for the output files}
        {--as-of= : last date (AD) to take; later rows are projections (default: today in Nepal)}';

    protected $description = 'Convert a legacy passbook workbook into the standard import files and an audit findings list';

    private const OPENING = 'cufl8sf] df}Hbft'; // "brought forward" (अगाडिको मौज्दात) in Preeti

    private const DAY_COLUMNS = ['bs' => 0, 'ad' => 2, 'voucher' => 3, 'particulars' => 4, 'debit' => 5, 'credit' => 6, 'balance' => 8, 'interest' => 9];

    private const LOAN_COLUMNS = ['ad' => 2, 'debit' => 5, 'credit' => 6, 'balance' => 7, 'interest' => 10, 'charged' => 11, 'interest_paid' => 12, 'interest_balance' => 13, 'fine' => 14];

    private BsCalendar $calendar;

    private string $asOf;

    /** @var array<string, list<string>> finding => examples */
    private array $findings = [];

    private array $history = [];

    private array $expected = [];

    private array $products = [];

    private array $loans = [];

    /** @var array<int, true> member numbers seen in passbooks */
    private array $passbookMembers = [];

    public function handle(BsCalendar $calendar): int
    {
        $this->calendar = $calendar;
        $this->asOf = (string) ($this->option('as-of') ?: BusinessDayService::today());
        $file = (string) $this->argument('file');
        $out = rtrim((string) $this->argument('out'), '/');
        if (! is_readable($file)) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }
        if (! is_dir($out) && ! mkdir($out, 0700, true)) {
            $this->error("Cannot create {$out}.");

            return self::FAILURE;
        }

        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $members = [];
        try {
            $reader->open($file);
            foreach ($reader->getSheetIterator() as $sheet) {
                $this->line("Reading sheet {$sheet->getName()}...");
                $rows = $sheet->getRowIterator();
                if (trim($sheet->getName()) === 'Member List') {
                    $members = $this->memberList($rows);
                } else {
                    $this->passbookSheet($sheet->getName(), $rows);
                }
            }
        } catch (Throwable $e) {
            $this->error('Cannot read the workbook: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $reader->close();
        }

        foreach (array_keys($this->passbookMembers) as $number) {
            if (! isset($members[$number])) {
                $this->finding('Passbooks for members missing from the Member List (import fails until they are added)', "member {$number}");
            }
        }

        Csv::write("{$out}/members.csv", ['member_number', 'full_name', 'date_of_birth', 'gender', 'phone', 'membership_date', 'share_units', 'savings_product_code', 'savings_balance'], $members);
        Csv::write("{$out}/history.csv", ImportPassbookHistory::HEADER, $this->history);
        Csv::write("{$out}/expected_balances.csv", ImportPassbookHistory::EXPECTED_HEADER, $this->expected);
        Csv::write("{$out}/products.csv", ['product_code', 'sheet', 'account_type', 'interest_rate_in_header', 'minimum_balance', 'accounts'], $this->products);
        Csv::write("{$out}/loans.csv", ['sheet', 'legacy_loan_no', 'member_number', 'interest_rate', 'disbursed', 'principal_repaid', 'principal_outstanding',
            'interest_charged', 'interest_paid', 'interest_outstanding', 'fine_outstanding', 'last_transaction_ad'], $this->loans);
        file_put_contents("{$out}/findings.txt", $this->findingsText());

        $this->info(sprintf('Wrote %d member(s), %d history row(s) for %d account(s), %d loan(s) to %s.', count($members), count($this->history), count($this->expected), count($this->loans), $out));
        $this->info(count($this->findings).' kind(s) of finding: see findings.txt.');

        return self::SUCCESS;
    }

    /** @return array<int, list<string>> member number => members.csv row */
    private function memberList(iterable $rows): array
    {
        $cols = null;
        $members = [];
        foreach ($rows as $r => $row) {
            $cells = $row->cells;
            if ($cols === null) {
                foreach ($cells as $i => $cell) {
                    if (trim((string) $this->value($cell)) === 'M/No') {
                        $cols = ['number' => $i, 'name' => $i + 1, 'date' => $i + 8, 'phone' => $i + 9, 'cancelled' => $i + 15];
                    }
                }

                continue;
            }
            $number = $this->value($cells[$cols['number']] ?? null);
            if (! is_int($number) && ! (is_float($number) && floor($number) === $number)) {
                continue;
            }
            $number = (int) $number;
            $name = trim((string) $this->value($cells[$cols['name']] ?? null));
            if (isset($members[$number])) {
                $this->finding('Member numbers listed more than once in the Member List (first entry used)', "member {$number}");

                continue;
            }
            if ($this->value($cells[$cols['cancelled']] ?? null) !== null) {
                $this->finding('Members marked Kharej (cancelled) in the Member List: not imported', "member {$number}");

                continue;
            }
            if ($name === '') {
                $this->finding('Member List rows without a name: not imported', "member {$number}");

                continue;
            }
            $date = $this->adDate($this->value($cells[$cols['date']] ?? null));
            if ($date === null) {
                $this->finding('Members without a usable membership date (fill membership_date in members.csv)', "member {$number}");
            }
            $phone = $this->value($cells[$cols['phone']] ?? null);
            $members[$number] = [$number, mb_substr($name, 0, 150), '', '', is_numeric($phone) ? (string) (int) $phone : '', $date ?? '', '', '', ''];
        }
        if ($cols === null) {
            $this->finding('Member List sheet has no "M/No" heading: members not read', 'Member List');
        }
        ksort($members);

        return $members;
    }

    private function passbookSheet(string $sheet, iterable $rows): void
    {
        $blocks = [];
        $width = null;
        $isLoan = false;
        $minimum = null;
        foreach ($rows as $r => $row) {
            $cells = $row->cells;
            if ($r === 7) {
                foreach ($cells as $i => $cell) {
                    $text = $this->value($cell);
                    if (is_string($text) && preg_match('/^\s*(Loan\s+)?A\/c\s*No\.?\s*(\S+)(?:.*Member\s*No\.?\s*:?\s*(\d+))?/i', $text, $m)) {
                        $isLoan = $m[1] !== '';
                        $blocks[$i] = ['account' => $m[2], 'member' => isset($m[3]) ? (int) $m[3] : null, 'type' => '', 'rate' => null,
                            'opening' => null, 'rows' => [], 'last_balance' => null, 'rates' => [], 'last' => []];
                    }
                }
                if ($blocks === []) {
                    $this->finding('Sheets without passbook blocks: not converted', $sheet);

                    return;
                }
                $starts = array_keys($blocks);
                $width = count($starts) > 1 ? $starts[1] - $starts[0] : ($isLoan ? 17 : 12);
                foreach ($blocks as $i => &$b) {
                    $b['type'] = Preeti::toUnicodeIfPreeti(trim((string) $this->value($this->rowCache[5][$i] ?? null)));
                }
                unset($b);
            }
            if ($r < 7) {
                $this->rowCache[$r] = $cells; // header rows above the account row

                continue;
            }
            if ($blocks === []) {
                continue;
            }
            foreach ($blocks as $i => &$b) {
                $isLoan ? $this->loanRow($b, $cells, $i, $r) : $this->savingsRow($b, $cells, $i, $r, $minimum);
            }
            unset($b);
        }
        $this->rowCache = [];
        if ($blocks === []) {
            return;
        }

        $accounts = array_count_values(array_column($blocks, 'account'));
        if (max($accounts) > 1) {
            $this->finding('Sheets with repeated account numbers (not a passbook sheet): not converted', $sheet);

            return;
        }
        $isLoan ? $this->finishLoans($sheet, $blocks) : $this->finishSavings($sheet, $blocks, $minimum, $width);
    }

    /** @var array<int, array> */
    private array $rowCache = [];

    private function savingsRow(array &$b, array $cells, int $i, int $r, ?string &$minimum): void
    {
        $at = fn (string $col) => $cells[$i + self::DAY_COLUMNS[$col]] ?? null;
        if ($r === 8 && preg_match('/Interest\s*Rate\s*:?\s*([\d.]+)\s*%/i', (string) $this->value($cells[$i] ?? null), $m)) {
            $b['rate'] = $m[1];
        }
        if ($b['opening'] === null && trim((string) $this->value($cells[$i] ?? null)) === self::OPENING) {
            $b['opening'] = $this->amount($this->value($at('balance')), "{$b['account']} row {$r}") ?? '0.00';

            return;
        }
        $interest = $at('interest');
        if ($interest instanceof FormulaCell && preg_match('/\*\s*([\d.]+)\s*%\s*\/\s*365/', $interest->getValue(), $m)) {
            $b['rates'][$m[1]] = true;
        }
        if ($minimum === null && ($can = $cells[$i + 7] ?? null) instanceof FormulaCell && preg_match('/^=[A-Z]+\d+-([\d.]+)$/', $can->getValue(), $m)) {
            $minimum = $m[1];
        }
        $ad = $this->value($at('ad'));
        $debit = $at('debit');
        $credit = $at('credit');
        if (! $ad instanceof DateTimeInterface) {
            foreach ([$debit, $credit] as $cell) {
                if (! $cell instanceof FormulaCell && is_numeric($this->value($cell)) && (float) $this->value($cell) !== 0.0) {
                    $this->finding('Amounts typed on rows without a date: not imported, check by hand', "{$b['account']} row {$r}");
                }
            }

            return;
        }
        if ($ad->format('Y-m-d') > $this->asOf) {
            foreach ([$debit, $credit] as $cell) {
                if (! $cell instanceof FormulaCell && is_numeric($this->value($cell)) && (float) $this->value($cell) !== 0.0) {
                    $this->finding("Amounts dated after the as-of date {$this->asOf}: not imported", "{$b['account']} row {$r}");
                }
            }

            return;
        }
        $balance = $this->value($at('balance'));
        if (is_numeric($balance)) {
            $b['last_balance'] = $balance;
        }
        foreach (['credit' => $credit, 'debit' => $debit] as $side => $cell) {
            $value = $cell instanceof FormulaCell ? null : $this->value($cell);
            if ($cell instanceof FormulaCell && is_numeric($cell->getComputedValue()) && (float) $cell->getComputedValue() !== 0.0) {
                $this->finding('Debit/Credit cells holding formulas instead of typed amounts: imported at their value, check them', "{$b['account']} row {$r}");
                $value = $cell->getComputedValue();
            }
            if (! is_numeric($value) || (float) $value === 0.0) {
                continue;
            }
            $amount = $this->amount($value, "{$b['account']} row {$r}");
            if (str_starts_with($amount, '-')) {
                $this->finding('Negative amounts (corrections): imported on the opposite side', "{$b['account']} row {$r}");
                $amount = substr($amount, 1);
                $side = $side === 'credit' ? 'debit' : 'credit';
            }
            $b['rows'][] = ['ad' => $ad->format('Y-m-d'), 'bs' => $this->bsText($this->value($at('bs'))), 'side' => $side, 'amount' => $amount,
                'voucher' => trim((string) $this->value($at('voucher'))), 'particulars' => trim((string) $this->value($at('particulars'))), 'row' => $r];
        }
    }

    private function finishSavings(string $sheet, array $blocks, ?string $minimum, int $width): void
    {
        $isShare = collect($blocks)->contains(fn ($b) => str_contains($b['type'], 'शेयर'));
        $code = $isShare ? ImportPassbookHistory::SHARE : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $sheet));
        if ($width !== 12) {
            $this->finding('Passbook sheets whose blocks are not 12 columns wide: check the conversion', "{$sheet} ({$width})");
        }
        $count = 0;
        foreach ($blocks as $b) {
            $label = "{$code} {$b['account']}";
            if ($b['opening'] === null && $b['rows'] === []) {
                continue;
            }
            $opening = $b['opening'] ?? '0.00';
            if ($b['rows'] === [] && (float) $opening === 0.0) {
                continue; // empty template block
            }
            if ($b['member'] === null) {
                $this->finding('Passbooks with money but no member number in the heading: not converted', $label);

                continue;
            }
            $count++;
            $this->passbookMembers[$b['member']] = true;
            $legacy = $b['account'];
            $first = $b['rows'][0]['ad'] ?? null;
            if ((float) $opening !== 0.0) {
                $this->history[] = [$first ?? '', '', '', $b['member'], $code, $legacy, 'opening', ltrim($opening, '-'), 'Brought forward'];
                if (str_starts_with($opening, '-')) {
                    $this->finding('Negative opening balances (overdrawn): import fails until corrected', $label);
                }
                if ($first === null) {
                    $this->finding('Accounts with an opening balance but no transactions: give the opening row a date in history.csv', $label);
                }
            }
            $running = $this->dec($opening);
            foreach ($b['rows'] as $t) {
                $type = $this->classify($t, $isShare, $label);
                [$adOk, $bs] = $this->checkDates($t, $label);
                $this->history[] = [$t['ad'], $adOk ? $bs : '', $t['voucher'], $b['member'], $code, $legacy, $type, $t['amount'], Preeti::toUnicodeIfPreeti($t['particulars'])];
                $running += ($t['side'] === 'credit' ? 1 : -1) * $this->dec($t['amount']);
            }
            $final = $b['last_balance'] !== null ? $this->amount($b['last_balance'], $label) : null;
            if ($final !== null && $this->dec($final) !== $running) {
                $this->finding('Balance column differs from brought forward + credits - debits (formula break or typing error)',
                    "{$label}: sheet {$final}, recomputed ".number_format($running / 100, 2, '.', ''));
            }
            $this->expected[] = [$code, $legacy, $b['member'], $final ?? number_format($running / 100, 2, '.', '')];
            $rates = array_keys($b['rates']);
            if (! $isShare && $b['rate'] !== null && ($rates === [] ? false : (count($rates) > 1 || (float) $rates[0] !== (float) $b['rate']))) {
                $this->finding('Daily interest formula uses a different rate from the passbook heading', "{$label}: heading {$b['rate']}%, formula ".implode('/', $rates).'%');
            }
        }
        $rate = collect($blocks)->pluck('rate')->filter()->countBy()->sortDesc()->keys()->first();
        $this->products[] = [$code, $sheet, $blocks[array_key_first($blocks)]['type'], $rate ?? '', $minimum ?? '', $count];
    }

    private function loanRow(array &$b, array $cells, int $i, int $r): void
    {
        // Interest charged is summed from the Interest (rounded) column; the last date sets the outstanding figures.
        $at = fn (string $col) => $cells[$i + self::LOAN_COLUMNS[$col]] ?? null;
        if ($r === 8 && preg_match('/Interest\s*Rate\s*:?\s*([\d.]+)\s*%/i', (string) $this->value($cells[$i] ?? null), $m)) {
            $b['rate'] = $m[1];
        }
        $interest = $at('interest');
        if ($interest instanceof FormulaCell && ! isset($b['compounds'])) {
            // Interest base: principal only, or principal + unpaid interest + fine?
            $b['compounds'] = preg_match_all('/\b[A-Z]{1,3}\d+\b/', explode('*', $interest->getValue())[0]) > 1;
        }
        $ad = $this->value($at('ad'));
        if (! $ad instanceof DateTimeInterface || $ad->format('Y-m-d') > $this->asOf) {
            return; // later rows are the sheet's own projection of future interest
        }
        foreach (['debit' => 'disbursed', 'credit' => 'principal_repaid', 'interest_paid' => 'interest_paid', 'charged' => 'interest_charged'] as $col => $key) {
            $v = $this->value($at($col));
            if (is_numeric($v) && (float) $v !== 0.0) {
                $b['last'][$key] = ($b['last'][$key] ?? 0) + $this->dec($this->amount($v, "loan {$b['account']} row {$r}"));
                $b['last']['date'] = $ad->format('Y-m-d');
            }
        }
        foreach (['balance' => 'principal_outstanding', 'interest_balance' => 'interest_outstanding', 'fine' => 'fine_outstanding'] as $col => $key) {
            $v = $this->value($at($col));
            if (is_numeric($v)) {
                $b['last'][$key] = $this->dec($this->amount($v, "loan {$b['account']}"));
            }
        }
    }

    private function finishLoans(string $sheet, array $blocks): void
    {
        $money = fn (?int $paisa) => number_format(($paisa ?? 0) / 100, 2, '.', '');
        foreach ($blocks as $b) {
            $l = $b['last'];
            if (($l['disbursed'] ?? 0) === 0 && ($l['principal_outstanding'] ?? 0) === 0) {
                continue;
            }
            $label = "loan {$b['account']}";
            if (! empty($b['compounds'])) {
                $this->finding('Loan interest charged on principal + unpaid interest + fine (interest on interest; Cooperatives Act s.50(5) forbids capitalising interest)', $label);
            }
            if ($b['member'] !== null) {
                $this->passbookMembers[$b['member']] = true;
            }
            $this->loans[] = [$sheet, $b['account'], $b['member'] ?? '', $b['rate'] ?? '', $money($l['disbursed'] ?? 0), $money($l['principal_repaid'] ?? 0),
                $money($l['principal_outstanding'] ?? 0), $money($l['interest_charged'] ?? 0), $money($l['interest_paid'] ?? 0),
                $money($l['interest_outstanding'] ?? 0), $money($l['fine_outstanding'] ?? 0), $l['date'] ?? ''];
        }
    }

    private function classify(array $t, bool $isShare, string $label): string
    {
        $text = Preeti::toUnicodeIfPreeti($t['particulars'].' '.$t['voucher']);
        $words = preg_split('/[\s.,:;\/()\-]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $type = match (true) {
            $t['side'] === 'credit' && (in_array('ब्याज', $words, true) || in_array('interest', $words, true)) => 'interest',
            $t['side'] === 'debit' && (in_array('कर', $words, true) || in_array('tax', $words, true)) => 'tax',
            $t['side'] === 'debit' && array_intersect($words, ['charge', 'renew', 'शुल्क', 'दस्तुर']) !== [] => 'charge',
            default => $t['side'] === 'credit' ? 'deposit' : 'withdrawal',
        };
        if ($isShare && ! in_array($type, ['deposit', 'withdrawal'], true)) {
            $this->finding('Share passbook rows that look like interest, tax or charges: imported as share purchase/refund, check them', "{$label} row {$t['row']}");
            $type = $t['side'] === 'credit' ? 'deposit' : 'withdrawal';
        }

        return $type;
    }

    /** @return array{0: bool, 1: string} whether the sheet's BS date matches its English date, and the BS date */
    private function checkDates(array $t, string $label): array
    {
        if ($t['bs'] === '') {
            return [true, ''];
        }
        try {
            if ((string) $this->calendar->toBs($t['ad']) === $t['bs']) {
                return [true, $t['bs']];
            }
        } catch (Throwable) {
            // outside the verified calendar: keep the English date only
        }
        $this->finding('Nepali date does not match the English date on the same row (English date used)', "{$label} row {$t['row']}: {$t['bs']} / {$t['ad']}");

        return [false, ''];
    }

    private function bsText(mixed $v): string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        return is_string($v) && preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', trim($v), $m) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : '';
    }

    /** AD date from a date cell; years after 2040 are taken as BS dates typed into date cells. */
    private function adDate(mixed $v): ?string
    {
        if (! $v instanceof DateTimeInterface) {
            return null;
        }
        if ((int) $v->format('Y') <= 2040) {
            return $v->format('Y-m-d');
        }
        try {
            return $this->calendar->toAd((int) $v->format('Y'), (int) $v->format('n'), (int) $v->format('j'))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function amount(mixed $v, string $where): ?string
    {
        if (! is_numeric($v)) {
            return null;
        }
        $rounded = round((float) $v, 2);
        if (abs((float) $v - $rounded) > 1e-6) {
            $this->finding('Amounts with more than 2 decimals (rounded to paisa)', "{$where}: {$v}");
        }

        return number_format($rounded, 2, '.', '');
    }

    /** Amount string to paisa (exact for 2-decimal strings). */
    private function dec(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        [$rupees, $paisa] = array_pad(explode('.', ltrim($amount, '-')), 2, '0');

        return ($negative ? -1 : 1) * ((int) $rupees * 100 + (int) str_pad(substr($paisa, 0, 2), 2, '0'));
    }

    private function value(?Cell $cell): mixed
    {
        $v = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell?->getValue();

        return $v === '' ? null : $v; // empty cells read as ''
    }

    private function finding(string $kind, string $example): void
    {
        $this->findings[$kind][] = $example;
    }

    private function findingsText(): string
    {
        $text = "Findings from the legacy workbook (for the auditor and the import team)\n\n";
        foreach ($this->findings as $kind => $examples) {
            $text .= sprintf("%s: %d\n", $kind, count($examples));
            foreach (array_slice($examples, 0, 50) as $e) {
                $text .= "  - {$e}\n";
            }
            if (count($examples) > 50) {
                $text .= '  ... and '.(count($examples) - 50)." more\n";
            }
            $text .= "\n";
        }

        return $this->findings === [] ? $text."No findings.\n" : $text;
    }
}
