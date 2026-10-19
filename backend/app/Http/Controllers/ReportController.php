<?php

namespace App\Http\Controllers;

use App\Accounting\Money;
use App\Accounting\PostingRejected;
use App\Calendar\BsCalendar;
use App\Calendar\BusinessDayService;
use App\Calendar\OutOfCalendarRange;
use App\Models\LedgerAccount;
use App\Reports\Ledger;
use App\Reports\ReportBuilder;
use App\Savings\SavingsService;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => collect(ReportBuilder::REPORTS)->map(fn ($title, $code) => compact('code', 'title'))->values()]);
    }

    public function show(Request $request, string $code, ReportBuilder $reports): JsonResponse
    {
        abort_unless(isset(ReportBuilder::REPORTS[$code]), 404);
        $v = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d'], 'from' => ['nullable', 'date_format:Y-m-d']]);
        $asOf = $v['as_of'] ?? app(BusinessDayService::class)->current()?->business_date->format('Y-m-d') ?? BusinessDayService::today();

        return response()->json(['data' => $reports->build($code, $asOf, $v['from'] ?? null)]);
    }

    /**
     * Day book by product, like the "Balance" sheet of the old workbooks: for each business date,
     * what was credited and debited to each savings product and to share capital, and the closing balance.
     */
    public function dailyBalances(Request $request, BsCalendar $calendar, TenantContext $tenant): JsonResponse
    {
        $v = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        abort_if((new \DateTimeImmutable($v['from']))->diff(new \DateTimeImmutable($v['to']))->days > 400, 422, 'Choose at most 400 days.');
        $credit = "'".implode("','", SavingsService::CREDIT_TYPES)."'";
        $coop = $tenant->cooperativeId(); // query builder: no tenant scope, so filter explicitly
        $saved = fn () => DB::table('savings_transactions as t')->where('t.cooperative_id', $coop)->join('journal_entries as e', 'e.id', '=', 't.journal_entry_id')
            ->join('savings_accounts as a', 'a.id', '=', 't.savings_account_id')->join('savings_products as p', 'p.id', '=', 'a.savings_product_id');
        $shares = fn () => DB::table('share_transactions as t')->where('t.cooperative_id', $coop)->join('journal_entries as e', 'e.id', '=', 't.journal_entry_id');

        $opening = [];
        $before = $saved()->where('e.entry_date', '<', $v['from'])->groupBy('p.code')
            ->selectRaw("p.code AS product, SUM(CASE WHEN t.type IN ({$credit}) THEN t.amount ELSE -t.amount END) AS net")
            ->unionAll($shares()->where('e.entry_date', '<', $v['from'])->havingRaw('COUNT(*) > 0')
                ->selectRaw("'SHARE' AS product, SUM(CASE WHEN t.type = 'purchase' THEN t.amount ELSE -t.amount END) AS net"));
        foreach ($before->get() as $r) {
            $opening[$r->product] = BigDecimal::of($r->net);
        }

        $movements = $saved()->whereBetween('e.entry_date', [$v['from'], $v['to']])->groupBy('p.code', 'e.entry_date')
            ->selectRaw("p.code AS product, e.entry_date AS day, SUM(CASE WHEN t.type IN ({$credit}) THEN t.amount ELSE 0 END) AS credit, SUM(CASE WHEN t.type IN ({$credit}) THEN 0 ELSE t.amount END) AS debit")
            ->unionAll($shares()->whereBetween('e.entry_date', [$v['from'], $v['to']])->groupBy('e.entry_date')
                ->selectRaw("'SHARE' AS product, e.entry_date AS day, SUM(CASE WHEN t.type = 'purchase' THEN t.amount ELSE 0 END) AS credit, SUM(CASE WHEN t.type = 'refund' THEN t.amount ELSE 0 END) AS debit"))
            ->get()->sortBy(['day', 'product']);

        $products = collect(array_keys($opening))->merge($movements->pluck('product'))->unique()->sort()->values()->all();
        $balance = $opening;
        $days = [];
        foreach ($movements->groupBy('day') as $day => $rows) {
            $row = ['date' => $day, 'date_bs' => $this->bs($calendar, $day), 'products' => []];
            foreach ($rows as $m) {
                $balance[$m->product] = ($balance[$m->product] ?? BigDecimal::zero())->plus($m->credit)->minus($m->debit);
                $row['products'][$m->product] = ['credit' => Money::format($m->credit), 'debit' => Money::format($m->debit), 'balance' => Money::format((string) $balance[$m->product])];
            }
            $days[] = $row;
        }

        return response()->json(['data' => [
            'from' => $v['from'], 'to' => $v['to'], 'products' => $products,
            'opening' => array_map(fn ($b) => Money::format((string) $b), $opening),
            'days' => $days,
            'closing' => array_map(fn ($b) => Money::format((string) $b), $balance),
        ]]);
    }

    private function bs(BsCalendar $calendar, string $ad): ?string
    {
        try {
            return (string) $calendar->toBs($ad);
        } catch (OutOfCalendarRange) {
            return null;
        }
    }

    public function tags(TenantContext $tenant): JsonResponse
    {
        return response()->json(['data' => [
            'tags' => collect(Ledger::TAGS)->map(fn ($t, $key) => ['tag' => $key, 'description' => $t[0], 'account_type' => $t[1]])->values(),
            'assigned' => DB::table('ledger_account_tags as t')->join('ledger_accounts as a', 'a.id', '=', 't.ledger_account_id')
                ->where('t.cooperative_id', $tenant->cooperativeId())->orderBy('a.code')->get(['a.code', 't.tag']),
        ]]);
    }

    public function setTags(Request $request, TenantContext $tenant): JsonResponse
    {
        $v = $request->validate(['account_code' => ['required', 'string'], 'tags' => ['present', 'array'], 'tags.*' => [Rule::in(array_keys(Ledger::TAGS))]]);
        $account = LedgerAccount::query()->where('code', $v['account_code'])->first() ?? throw new PostingRejected("Account {$v['account_code']} does not exist.");
        foreach ($v['tags'] as $tag) {
            if (Ledger::TAGS[$tag][1] !== $account->type) {
                throw new PostingRejected("Tag {$tag} needs an account of type ".Ledger::TAGS[$tag][1].'.');
            }
        }
        DB::transaction(function () use ($account, $v, $tenant) {
            DB::table('ledger_account_tags')->where('ledger_account_id', $account->id)->delete();
            foreach (array_unique($v['tags']) as $tag) {
                DB::table('ledger_account_tags')->insert(['cooperative_id' => $tenant->cooperativeId(), 'ledger_account_id' => $account->id, 'tag' => $tag, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return $this->tags($tenant);
    }
}
