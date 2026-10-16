<?php

namespace App\Reports;

use App\Accounting\Money;
use App\Calendar\BsCalendar;
use App\Loans\LoanClassifier;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Financial statements and NCRA returns, computed from the ledger and
 * sub-ledgers at a date. Output shape (all amounts are decimal strings):
 *   {code, title, source, as_of|from+to, sections:[{title, rows:[{label, amount, note?}], total?}],
 *    checks:[{label, value, requirement, ok, source}]}
 * Formats follow the NCRA tables' rows where stated; presentation is PROPOSED
 * until the regulator's official formats are obtained (register R-09).
 */
class ReportBuilder
{
    public const REPORTS = [
        'balance_sheet' => 'Balance sheet',
        'profit_and_loss' => 'Profit and loss',
        'loan_classification' => 'Loan classification and provision (NCRA Table 3.2)',
        'liquidity' => 'Liquid assets (NCRA Table 4.1)',
        'capital_adequacy' => 'Capital fund and risk-weighted assets (NCRA Tables 6.1/6.2)',
        'interest_spread' => 'Interest rates and spread (NCRA Std 5/3; Act s.50(4))',
        'portfolio_ratios' => 'Savings and loan portfolio ratios (NCRA Std 2/3.1, 3/1.7)',
    ];

    /** NCRA Std 1/2.3: savings or loans above Rs 25 crore (from the previous year's audit; current books used here, PROPOSED). */
    public const LARGE_COOPERATIVE_THRESHOLD = '250000000.00';

    public function __construct(private Ledger $ledger, private TenantContext $tenant, private BsCalendar $calendar) {}

    public function build(string $code, string $asOf, ?string $from = null): array
    {
        return match ($code) {
            'balance_sheet' => $this->balanceSheet($asOf),
            'profit_and_loss' => $this->profitAndLoss($from ?? $this->fiscalYearStart($asOf), $asOf),
            'loan_classification' => $this->loanClassification($asOf),
            'liquidity' => $this->liquidity($asOf),
            'capital_adequacy' => $this->capitalAdequacy($asOf),
            'interest_spread' => $this->interestSpread(),
            'portfolio_ratios' => $this->portfolioRatios(),
        };
    }

    private function balanceSheet(string $asOf): array
    {
        $accounts = $this->ledger->accounts($asOf);
        $section = fn (string $type, string $title) => [
            'title' => $title,
            'rows' => $accounts->where('type', $type)->filter(fn ($a) => ! $a->balance->isZero())
                ->map(fn ($a) => ['label' => "{$a->code} {$a->name}", 'amount' => Money::format($a->balance)])->values()->all(),
            'total' => Money::format($this->ledger->sum($accounts->where('type', $type))),
        ];
        $profit = $this->ledger->sum($accounts->where('type', 'income'))->minus($this->ledger->sum($accounts->where('type', 'expense')));
        $assets = $this->ledger->sum($accounts->where('type', 'asset'));
        $claims = $this->ledger->sum($accounts->where('type', 'liability'))->plus($this->ledger->sum($accounts->where('type', 'equity')))->plus($profit);

        $equity = $section('equity', 'Equity');
        $equity['rows'][] = ['label' => 'Profit / (loss) not yet closed to equity', 'amount' => Money::format($profit)];
        $equity['total'] = Money::format(BigDecimal::of($equity['total'])->plus($profit));

        return $this->report('balance_sheet', ['as_of' => $asOf], [$section('asset', 'Assets'), $section('liability', 'Liabilities'), $equity], [
            $this->check('Assets = liabilities + equity + profit', Money::format($assets).' = '.Money::format($claims), 'must be equal', $assets->isEqualTo($claims), 'double entry'),
        ]);
    }

    private function profitAndLoss(string $from, string $to): array
    {
        $accounts = $this->ledger->accounts($to, $from);
        $rows = fn (string $type) => $accounts->where('type', $type)->filter(fn ($a) => ! $a->balance->isZero())
            ->map(fn ($a) => ['label' => "{$a->code} {$a->name}", 'amount' => Money::format($a->balance)])->values()->all();
        $income = $this->ledger->sum($accounts->where('type', 'income'));
        $expense = $this->ledger->sum($accounts->where('type', 'expense'));

        return $this->report('profit_and_loss', ['from' => $from, 'to' => $to], [
            ['title' => 'Income', 'rows' => $rows('income'), 'total' => Money::format($income)],
            ['title' => 'Expenses', 'rows' => $rows('expense'), 'total' => Money::format($expense)],
            ['title' => 'Net', 'rows' => [['label' => 'Net profit / (loss)', 'amount' => Money::format($income->minus($expense))]]],
        ], []);
    }

    /** Table 3.2 rows 1-4 (amounts in Rs; the NCRA form uses Rs lakh). */
    private function loanClassification(string $asOf): array
    {
        $rows = DB::table('loans')->where('cooperative_id', $this->tenant->cooperativeId())->where('status', 'disbursed')
            ->groupBy('classification')->selectRaw('classification, COUNT(*) n, SUM(outstanding_principal) amount')->get()->keyBy('classification');
        $labels = ['pass' => '1.1 Pass (असल)', 'substandard' => '2.1 Substandard (कमसल)', 'doubtful' => '2.2 Doubtful (शंकास्पद)', 'loss' => '2.3 Loss (खराब)'];
        $loanRows = $provisionRows = [];
        $total = $required = BigDecimal::zero()->toScale(2);
        foreach ($labels as $class => $label) {
            $amount = BigDecimal::of($rows[$class]->amount ?? '0')->toScale(2);
            $need = $amount->multipliedBy(LoanClassifier::PROVISION_PERCENT[$class])->dividedBy(100, 2, RoundingMode::HalfUp);
            $loanRows[] = ['label' => $label, 'amount' => Money::format($amount), 'note' => ((int) ($rows[$class]->n ?? 0)).' loan(s)'];
            $provisionRows[] = ['label' => '4.'.(array_search($class, array_keys($labels)) + 1).' '.ucfirst($class).' at '.LoanClassifier::PROVISION_PERCENT[$class].'%', 'amount' => Money::format($need)];
            $total = $total->plus($amount);
            $required = $required->plus($need);
        }
        $held = $this->ledger->role('loan_loss_provision', $asOf)->negated(); // contra asset: credit balance

        return $this->report('loan_classification', ['as_of' => $asOf], [
            ['title' => 'Loans by classification (outstanding principal)', 'rows' => $loanRows, 'total' => Money::format($total)],
            ['title' => 'Minimum loan loss provision (Std 3/4.1)', 'rows' => $provisionRows, 'total' => Money::format($required)],
            ['title' => 'Provision held in the ledger', 'rows' => [['label' => 'Loan loss provision balance', 'amount' => Money::format($held)]]],
        ], [
            $this->check('Provision held ≥ minimum required', Money::format($held), '≥ '.Money::format($required), $held->isGreaterThanOrEqualTo($required), 'NCRA Std 3/4.1'),
        ]);
    }

    /** Table 4.1: weekly snapshots at days 7, 14, 21, 28 of the BS month of $asOf (PROPOSED reading of "weekly average"). */
    private function liquidity(string $asOf): array
    {
        $bs = $this->calendar->toBs($asOf);
        $weeks = [];
        foreach ([7, 14, 21, 28] as $day) {
            $date = $this->calendar->toAd($bs->year, $bs->month, $day)->format('Y-m-d');
            $savings = $this->savingsTotal($date);
            $fd = $this->ledger->tagged('fixed_deposit', $date)->multipliedBy(90)->dividedBy(100, 2, RoundingMode::Down);
            $fdCap = $savings->multipliedBy(5)->dividedBy(100, 2, RoundingMode::Down);
            $liquid = $this->ledger->role('cash', $date)
                ->plus($this->ledger->tagged('government_bond', $date))
                ->plus($this->ledger->tagged('bank_deposit', $date))
                ->plus($this->ledger->tagged('cooperative_bank_deposit', $date))
                ->plus(BigDecimal::min($fd, $fdCap));
            $weeks[] = ['date' => $date, 'savings' => $savings, 'liquid' => $liquid];
        }
        $avg = fn (string $k) => array_reduce($weeks, fn (BigDecimal $c, $w) => $c->plus($w[$k]), BigDecimal::zero())->dividedBy(4, 2, RoundingMode::HalfUp);
        $savingsAvg = $avg('savings');
        $liquidAvg = $avg('liquid');
        $ratio = $savingsAvg->isZero() ? null : $liquidAvg->multipliedBy(100)->dividedBy($savingsAvg, 2, RoundingMode::Down);

        return $this->report('liquidity', ['as_of' => $asOf, 'bs_month' => sprintf('%04d-%02d', $bs->year, $bs->month)], [
            ['title' => 'Weekly snapshots', 'rows' => array_map(fn ($w, $i) => [
                'label' => 'Week '.($i + 1)." ({$w['date']})", 'amount' => Money::format($w['liquid']), 'note' => 'savings '.Money::format($w['savings']),
            ], $weeks, array_keys($weeks))],
            ['title' => 'Monthly average', 'rows' => [
                ['label' => '1 Total savings', 'amount' => Money::format($savingsAvg)],
                ['label' => '5 Total liquid assets', 'amount' => Money::format($liquidAvg)],
            ]],
        ], [
            $this->check('Liquid assets / savings', $ratio === null ? 'no savings' : "{$ratio}%", '≥ 15%', $ratio === null || $ratio->isGreaterThanOrEqualTo(15), 'NCRA Std 4/1'),
        ]);
    }

    /** Tables 6.1 / 6.2 (PROPOSED mapping of accounts to risk weights). */
    private function capitalAdequacy(string $asOf): array
    {
        $core = $this->ledger->role('share_capital', $asOf)->plus($this->ledger->role('reserve_fund', $asOf))
            ->plus($this->ledger->tagged('loss_compensation_fund', $asOf))->plus($this->retainedProfit($asOf));

        // Risk weights (Std 6/3): 0% cash, government bonds, loans secured by own deposits; 20% bank and cooperative bank balances;
        // 100% other loans, fixed assets and other assets. Specific provisions are deducted from the loans they cover (approximated
        // here by deducting the whole provision balance from 100%-weighted loans).
        $zero = $this->ledger->role('cash', $asOf)->plus($this->ledger->tagged('government_bond', $asOf))->plus($this->loansOutstanding(['savings']));
        $twenty = $this->ledger->tagged('bank_deposit', $asOf)->plus($this->ledger->tagged('cooperative_bank_deposit', $asOf))->plus($this->ledger->tagged('fixed_deposit', $asOf));
        $totalAssets = $this->ledger->sum($this->ledger->accounts($asOf)->where('type', 'asset'));
        $provision = $this->ledger->role('loan_loss_provision', $asOf)->negated(); // inside total assets as a negative asset
        $hundred = $totalAssets->minus($zero)->minus($twenty);
        $rwa = $twenty->multipliedBy(20)->dividedBy(100, 2, RoundingMode::HalfUp)->plus($hundred);

        $generalProvision = BigDecimal::of((string) DB::table('loans')->where('cooperative_id', $this->tenant->cooperativeId())->where('status', 'disbursed')
            ->where('classification', 'pass')->sum('outstanding_principal'))->dividedBy(100, 2, RoundingMode::HalfUp);
        $supplementary = BigDecimal::min($generalProvision, $rwa->multipliedBy('1.5')->dividedBy(100, 2, RoundingMode::Down))
            ->plus($this->ledger->tagged('free_reserve', $asOf));
        $supplementary = BigDecimal::min($supplementary, $core->isPositive() ? $core : BigDecimal::zero());
        $total = $core->plus($supplementary);
        $pct = fn (BigDecimal $x) => $rwa->isZero() ? null : $x->multipliedBy(100)->dividedBy($rwa, 2, RoundingMode::Down);
        $large = $this->savingsTotal($asOf)->isGreaterThan(self::LARGE_COOPERATIVE_THRESHOLD) || $this->loansOutstanding()->isGreaterThan(self::LARGE_COOPERATIVE_THRESHOLD);

        $checks = [$this->check('Core capital / RWA', ($pct($core) ?? '—').'%', '≥ 4%', $pct($core) === null || $pct($core)->isGreaterThanOrEqualTo(4), 'NCRA Std 6/1')];
        if ($large) {
            $checks[] = $this->check('Total capital / RWA (large cooperative)', ($pct($total) ?? '—').'%', '≥ 8%', $pct($total) === null || $pct($total)->isGreaterThanOrEqualTo(8), 'NCRA Std 6/2');
        }

        return $this->report('capital_adequacy', ['as_of' => $asOf, 'large_cooperative' => $large], [
            ['title' => 'Table 6.1 Capital fund', 'rows' => [
                ['label' => 'Share capital', 'amount' => Money::format($this->ledger->role('share_capital', $asOf))],
                ['label' => 'Reserve fund', 'amount' => Money::format($this->ledger->role('reserve_fund', $asOf))],
                ['label' => 'Loss compensation fund', 'amount' => Money::format($this->ledger->tagged('loss_compensation_fund', $asOf))],
                ['label' => 'Accumulated profit / (loss)', 'amount' => Money::format($this->retainedProfit($asOf))],
                ['label' => 'Core capital', 'amount' => Money::format($core)],
                ['label' => 'Supplementary capital (general provision ≤ 1.5% RWA, free reserve; ≤ core)', 'amount' => Money::format($supplementary)],
                ['label' => 'Total capital fund', 'amount' => Money::format($total)],
            ]],
            ['title' => 'Table 6.2 Risk-weighted assets', 'rows' => [
                ['label' => '0%: cash, government bonds, loans secured by members\' savings', 'amount' => Money::format($zero)],
                ['label' => '20%: bank, cooperative bank and fixed deposits', 'amount' => Money::format($twenty)],
                ['label' => '100%: other loans (net of provision), fixed and other assets', 'amount' => Money::format($hundred), 'note' => 'provision deducted: '.Money::format($provision)],
                ['label' => 'Risk-weighted assets', 'amount' => Money::format($rwa)],
            ]],
        ], $checks);
    }

    /** Weighted average rates by current balances (Std 5/1.3). */
    private function interestSpread(): array
    {
        $coop = $this->tenant->cooperativeId();
        $savings = DB::table('savings_accounts as a')->join('savings_products as p', 'p.id', '=', 'a.savings_product_id')
            ->where('a.cooperative_id', $coop)->where('a.balance', '>', 0)->selectRaw('SUM(a.balance) b, SUM(a.balance * p.interest_rate) w')->first();
        $loans = DB::table('loans')->where('cooperative_id', $coop)->where('status', 'disbursed')->selectRaw('SUM(outstanding_principal) b, SUM(outstanding_principal * interest_rate) w')->first();
        $avg = fn ($r) => ($r->b ?? 0) > 0 ? BigDecimal::of($r->w)->dividedBy($r->b, 4, RoundingMode::HalfUp) : null;
        $s = $avg($savings);
        $l = $avg($loans);
        $spread = ($s !== null && $l !== null) ? $l->minus($s) : null;

        return $this->report('interest_spread', [], [
            ['title' => 'Weighted average rates', 'rows' => [
                ['label' => 'Savings (weighted by balance)', 'amount' => $s === null ? '—' : "{$s}%"],
                ['label' => 'Loans (weighted by outstanding)', 'amount' => $l === null ? '—' : "{$l}%"],
                ['label' => 'Spread', 'amount' => $spread === null ? '—' : "{$spread}%"],
            ]],
            ['title' => 'Products', 'rows' => DB::table('savings_products')->where('cooperative_id', $coop)->get()->map(fn ($p) => ['label' => "Savings {$p->code} {$p->name}", 'amount' => "{$p->interest_rate}%"])
                ->merge(DB::table('loan_products')->where('cooperative_id', $coop)->get()->map(fn ($p) => ['label' => "Loan {$p->code} {$p->name}", 'amount' => "{$p->interest_rate}%", 'note' => "penalty {$p->penalty_percent}% of overdue interest, fee {$p->service_fee_percent}%"]))->values()->all()],
        ], [
            $this->check('Loan rate − savings rate', $spread === null ? 'n/a' : "{$spread}%", '≤ 6%', $spread === null || $spread->isLessThanOrEqualTo(6), 'Act s.50(4); NCRA Std 5/1.3'),
        ]);
    }

    private function portfolioRatios(): array
    {
        $coop = $this->tenant->cooperativeId();
        $total = BigDecimal::of((string) DB::table('savings_accounts')->where('cooperative_id', $coop)->sum('balance'));
        $regular = BigDecimal::of((string) DB::table('savings_accounts as a')->join('savings_products as p', 'p.id', '=', 'a.savings_product_id')
            ->where('a.cooperative_id', $coop)->where('p.type', 'regular')->sum('a.balance'));
        $loans = $this->loansOutstanding();
        $productive = BigDecimal::of((string) DB::table('loans as l')->join('loan_products as p', 'p.id', '=', 'l.loan_product_id')
            ->where('l.cooperative_id', $coop)->where('l.status', 'disbursed')->where('p.is_productive_sector', true)->sum('l.outstanding_principal'));
        $pct = fn (BigDecimal $a, BigDecimal $b) => $b->isZero() ? null : $a->multipliedBy(100)->dividedBy($b, 2, RoundingMode::Down);
        $large = $total->isGreaterThan(self::LARGE_COOPERATIVE_THRESHOLD) || $loans->isGreaterThan(self::LARGE_COOPERATIVE_THRESHOLD);

        $checks = [$this->check('Regular savings / total savings', ($pct($regular, $total) ?? '—').'%', '≥ 25%', $pct($regular, $total) === null || $pct($regular, $total)->isGreaterThanOrEqualTo(25), 'NCRA Std 2/3.1')];
        if ($large) {
            $checks[] = $this->check('Productive-sector loans / total loans (large cooperative)', ($pct($productive, $loans) ?? '—').'%', '≥ 50%', $pct($productive, $loans) === null || $pct($productive, $loans)->isGreaterThanOrEqualTo(50), 'NCRA Std 3/1.7');
        }

        return $this->report('portfolio_ratios', ['large_cooperative' => $large], [
            ['title' => 'Savings', 'rows' => [['label' => 'Total savings', 'amount' => Money::format($total)], ['label' => 'Regular savings', 'amount' => Money::format($regular)]]],
            ['title' => 'Loans', 'rows' => [['label' => 'Total outstanding', 'amount' => Money::format($loans)], ['label' => 'Productive sector', 'amount' => Money::format($productive)]]],
        ], $checks);
    }

    private function savingsTotal(string $asOf): BigDecimal
    {
        $ids = DB::table('savings_products')->where('cooperative_id', $this->tenant->cooperativeId())->pluck('ledger_account_id')->unique()->all();

        return $this->ledger->sum($this->ledger->accounts($asOf)->whereIn('id', $ids));
    }

    private function loansOutstanding(array $collateral = ['savings', 'real_estate', 'unsecured']): BigDecimal
    {
        return BigDecimal::of((string) DB::table('loans as l')->join('loan_products as p', 'p.id', '=', 'l.loan_product_id')
            ->where('l.cooperative_id', $this->tenant->cooperativeId())->where('l.status', 'disbursed')->whereIn('p.collateral_type', $collateral)
            ->sum('l.outstanding_principal'))->toScale(2);
    }

    private function retainedProfit(string $asOf): BigDecimal
    {
        $accounts = $this->ledger->accounts($asOf);

        return $this->ledger->sum($accounts->where('type', 'income'))->minus($this->ledger->sum($accounts->where('type', 'expense')));
    }

    private function fiscalYearStart(string $asOf): string
    {
        return $this->calendar->fiscalYearBounds($this->calendar->toBs($asOf)->fiscalYearStart())['start']->format('Y-m-d');
    }

    private function report(string $code, array $period, array $sections, array $checks): array
    {
        return ['code' => $code, 'title' => self::REPORTS[$code]] + $period + ['sections' => $sections, 'checks' => $checks];
    }

    private function check(string $label, string $value, string $requirement, bool $ok, string $source): array
    {
        return compact('label', 'value', 'requirement', 'ok', 'source');
    }
}
