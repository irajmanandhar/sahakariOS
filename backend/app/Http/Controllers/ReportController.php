<?php

namespace App\Http\Controllers;

use App\Accounting\PostingRejected;
use App\Calendar\BusinessDayService;
use App\Models\LedgerAccount;
use App\Reports\Ledger;
use App\Reports\ReportBuilder;
use App\Tenancy\TenantContext;
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
