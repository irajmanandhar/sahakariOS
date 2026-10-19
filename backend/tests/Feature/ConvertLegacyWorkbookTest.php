<?php

namespace Tests\Feature;

use DateTimeImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/** A small synthetic workbook in the legacy passbook layout (no real member data). */
class ConvertLegacyWorkbookTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/legacy');
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** One sheet with blocks of $width columns from column G; $cells[row][block][offset] = value. */
    private function sheet(Writer $writer, string $name, int $width, array $cells, bool $first = false): void
    {
        if (! $first) {
            $writer->addNewSheetAndMakeItCurrent();
        }
        $writer->getCurrentSheet()->setName($name);
        $last = max(array_keys($cells));
        for ($r = 1; $r <= $last; $r++) {
            $values = [];
            foreach ($cells[$r] ?? [] as $block => $offsets) {
                foreach ($offsets as $offset => $v) {
                    $values[6 + $block * $width + $offset] = $v;
                }
            }
            $row = [];
            for ($c = 0; $c <= ($values === [] ? 0 : max(array_keys($values))); $c++) {
                $v = $values[$c] ?? null;
                $row[] = match (true) {
                    is_string($v) && str_starts_with($v, '=') => new Cell\FormulaCell($v),
                    $v instanceof DateTimeImmutable => Cell::fromValue($v, (new Style)->withFormat('yyyy-mm-dd')),
                    default => Cell::fromValue($v),
                };
            }
            $writer->addRow(new Row($row));
        }
    }

    private function workbook(): string
    {
        $file = "{$this->dir}/book.xlsx";
        $writer = new Writer;
        $writer->openToFile($file);
        $d = fn (string $s) => new DateTimeImmutable($s);
        $header = fn (string $type, string $acc, string $rate) => [5 => [[0 => $type]], 7 => [[0 => $acc]], 8 => [[0 => $rate]]];

        // Savings: account 1001 of member 7, heading rate 6% but formulas use 3%.
        $savings = $header('P]lR5s art', 'A/c No.1001              Member No.07', 'Interest Rate:6%       Cheque No.From 00') + [
            13 => [[0 => 'cufl8sf] df}Hbft', 8 => 1000]],
            14 => [[0 => $d('2083-07-01'), 1 => 'Sunday', 2 => $d('2026-10-18'), 5 => 0, 6 => 500, 7 => '=O14-500', 8 => 1500, 9 => '=O12*3%/365']],
            15 => [[0 => '2083/07/02', 2 => $d('2026-10-19'), 3 => 'V-5', 4 => 'Aofh', 6 => 12.5, 8 => 1512.5, 9 => '=O13*3%/365']],
            16 => [[0 => $d('2083-07-03'), 2 => $d('2026-10-21'), 4 => 's/', 5 => 0.75, 8 => 1511.75]],   // BS date typed wrong (should be 07-04)
            17 => [[0 => $d('2083-07-05'), 2 => $d('2026-10-22'), 3 => 'Member Renew Charge', 5 => 100, 8 => 1400]], // sheet balance wrong by 11.75
            18 => [[0 => $d('2083-07-06'), 2 => $d('2026-10-23'), 5 => 50, 8 => 1350]],                  // after --as-of: ignored
            19 => [[5 => 9]],                                                                             // amount without a date
        ];
        // Share register: member 7 bought 3 shares.
        $shares = $header('JolQmut z]o/ vftf', 'A/c No.001     Member No.07', 'Interest Rate:0%') + [
            13 => [[0 => 'cufl8sf] df}Hbft', 8 => 200]],
            14 => [[0 => $d('2083-07-01'), 2 => $d('2026-10-18'), 6 => 100, 8 => 300]],
        ];
        // Loan: interest formula charges interest on principal + unpaid interest + fine.
        $loan = $header('AolQmut C0f vftf', 'Loan A/c No.001     Member No.07', 'Interest Rate:12%') + [
            14 => [[2 => $d('2026-10-18'), 5 => 10000, 7 => 10000, 10 => '=(N13+T13+U13)*O14*P14/36000', 11 => 0, 13 => 0, 14 => 0]],
            15 => [[2 => $d('2026-10-19'), 6 => 1000, 7 => 9000, 11 => 4, 12 => 4, 13 => 0, 14 => 0]],
        ];
        $this->sheet($writer, 'Achhik', 12, $savings, true);
        $this->sheet($writer, 'Share Khhata', 12, $shares);
        $this->sheet($writer, 'Loan', 17, $loan);

        $writer->addNewSheetAndMakeItCurrent();
        $writer->getCurrentSheet()->setName('Member List');
        $list = [[], [], [], array_merge(array_fill(0, 23, null), ['M/No', 'Name']),
            array_merge(array_fill(0, 23, null), [7, 'Test Member Seven', null, null, null, null, null, null, $d('2075-01-15'), 9800000007]),
            array_merge(array_fill(0, 23, null), [8, 'Test Member Eight', null, null, null, null, null, null, null, null, null, null, null, null, null, 1]),
            array_merge(array_fill(0, 23, null), [7, 'Duplicate'])];
        foreach ($list as $values) {
            $writer->addRow(new Row(array_map(fn ($v) => $v instanceof DateTimeImmutable ? Cell::fromValue($v, (new Style)->withFormat('yyyy-mm-dd')) : Cell::fromValue($v), $values)));
        }
        $writer->close();

        return $file;
    }

    public function test_converts_a_legacy_workbook_into_import_files_and_findings(): void
    {
        $this->artisan('migration:convert-excel', ['file' => $this->workbook(), 'out' => $this->dir, '--as-of' => '2026-10-22'])
            ->expectsOutputToContain('Wrote 1 member(s), 7 history row(s) for 2 account(s), 1 loan(s)')
            ->assertSuccessful();

        $this->assertSame([
            'member_number,full_name,date_of_birth,gender,phone,membership_date,share_units,savings_product_code,savings_balance',
            '7,"Test Member Seven",,,9800000007,2018-04-28,,,',  // BS 2075-01-15
        ], $this->lines('members.csv'));
        $this->assertSame([
            'date_ad,date_bs,voucher_no,member_number,product_code,legacy_account_no,type,amount,particulars',
            '2026-10-18,,,7,ACHHIK,1001,opening,1000.00,"Brought forward"',
            '2026-10-18,2083-07-01,,7,ACHHIK,1001,deposit,500.00,',
            '2026-10-19,2083-07-02,V-5,7,ACHHIK,1001,interest,12.50,ब्याज',
            '2026-10-21,,,7,ACHHIK,1001,tax,0.75,कर',
            '2026-10-22,2083-07-05,"Member Renew Charge",7,ACHHIK,1001,charge,100.00,',
            '2026-10-18,,,7,SHARE,001,opening,200.00,"Brought forward"',
            '2026-10-18,2083-07-01,,7,SHARE,001,deposit,100.00,',
        ], $this->lines('history.csv'));
        $this->assertSame(['product_code,legacy_account_no,member_number,balance', 'ACHHIK,1001,7,1400.00', 'SHARE,001,7,300.00'], $this->lines('expected_balances.csv'));
        $this->assertSame('ACHHIK,Achhik,"ऐच्छिक बचत",6,500,1', $this->lines('products.csv')[1]);
        $this->assertSame('Loan,001,7,12,10000.00,1000.00,9000.00,4.00,4.00,0.00,0.00,2026-10-19', $this->lines('loans.csv')[1]);

        $findings = file_get_contents("{$this->dir}/findings.txt");
        foreach ([
            'Daily interest formula uses a different rate from the passbook heading: 1',
            'ACHHIK 1001: heading 6%, formula 3%',
            'Nepali date does not match the English date on the same row (English date used): 1',
            'Balance column differs from brought forward + credits - debits (formula break or typing error): 1',
            'ACHHIK 1001: sheet 1400.00, recomputed 1411.75',
            'Amounts typed on rows without a date: not imported, check by hand: 1',
            'Amounts dated after the as-of date 2026-10-22: not imported: 1',
            'Loan interest charged on principal + unpaid interest + fine',
            'Members marked Kharej (cancelled) in the Member List: not imported: 1',
            'Member numbers listed more than once in the Member List (first entry used): 1',
        ] as $expected) {
            $this->assertStringContainsString($expected, $findings);
        }
    }

    private function lines(string $file): array
    {
        return explode("\n", trim(file_get_contents("{$this->dir}/{$file}")));
    }
}
