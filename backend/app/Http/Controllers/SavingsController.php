<?php

namespace App\Http\Controllers;

use App\Accounting\Money;
use App\Http\Controllers\Concerns\RequiresIdempotencyKey;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Savings\SavingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SavingsController
{
    use RequiresIdempotencyKey;

    public function products(): JsonResponse
    {
        return response()->json(['data' => SavingsProduct::query()->orderBy('code')->get()->map(fn (SavingsProduct $p) => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'type' => $p->type, 'interest_rate' => $p->interest_rate,
            'interest_posting' => $p->interest_posting, 'minimum_balance' => Money::format($p->minimum_balance),
            'term_months' => $p->term_months, 'status' => $p->status,
        ])]);
    }

    public function createProduct(Request $request, SavingsService $savings): JsonResponse
    {
        $v = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['general', 'regular', 'term'])],
            'interest_rate' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,6})?$/'],
            'interest_posting' => ['required', Rule::in(['monthly', 'quarterly', 'half_yearly', 'yearly', 'at_maturity'])],
            'minimum_balance' => ['nullable', 'string'],
            'term_months' => ['nullable', 'integer', 'min:1'],
            'ledger_account_code' => ['required', 'string', 'max:32'],
        ]);

        return response()->json(['data' => ['id' => $savings->createProduct($v, $request->user())->id]], 201);
    }

    public function memberAccounts(Member $member): JsonResponse
    {
        return response()->json(['data' => SavingsAccount::query()->where('member_id', $member->id)->with('product')->orderBy('id')->get()
            ->map($this->present(...))]);
    }

    public function open(Request $request, Member $member, SavingsService $savings): JsonResponse
    {
        $v = $request->validate(['savings_product_id' => ['required', 'integer']]);
        $product = SavingsProduct::query()->findOrFail($v['savings_product_id']);

        return response()->json(['data' => $this->present($savings->open($member, $product, $request->user())->load('product'))], 201);
    }

    public function statement(SavingsAccount $savingsAccount): JsonResponse
    {
        $rows = DB::table('savings_transactions')->where('savings_account_id', $savingsAccount->id)->orderBy('id')->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'date' => substr($r->created_at, 0, 10), 'type' => $r->type, 'channel' => $r->channel,
                'amount' => Money::format($r->amount), 'balance_after' => Money::format($r->balance_after), 'narration' => $r->narration,
            ]);

        return response()->json(['data' => $this->present($savingsAccount->load('product')) + ['transactions' => $rows]]);
    }

    public function deposit(Request $request, SavingsAccount $savingsAccount, SavingsService $savings): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        $v = $request->validate(['amount' => ['required', 'string'], 'source_of_funds' => ['nullable', 'string', 'max:500'], 'narration' => ['nullable', 'string', 'max:300']]);

        return response()->json(['data' => $savings->deposit($savingsAccount, $v['amount'], $request->user(), $key, $v['source_of_funds'] ?? null, $v['narration'] ?? null)], 201);
    }

    public function withdraw(Request $request, SavingsAccount $savingsAccount, SavingsService $savings): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        $v = $request->validate(['amount' => ['required', 'string'], 'large_cash_reason' => ['nullable', 'string', 'max:500'], 'narration' => ['nullable', 'string', 'max:300']]);

        return response()->json(['data' => $savings->withdraw($savingsAccount, $v['amount'], $request->user(), $key, $v['large_cash_reason'] ?? null, $v['narration'] ?? null)], 201);
    }

    private function present(SavingsAccount $a): array
    {
        return [
            'id' => $a->id, 'account_number' => $a->account_number, 'member_id' => $a->member_id,
            'product' => ['id' => $a->product->id, 'code' => $a->product->code, 'name' => $a->product->name, 'type' => $a->product->type],
            'status' => $a->status, 'balance' => Money::format($a->balance), 'hold_amount' => Money::format($a->hold_amount),
            'opened_on' => $a->opened_on->format('Y-m-d'), 'maturity_date' => $a->maturity_date?->format('Y-m-d'),
        ];
    }
}
