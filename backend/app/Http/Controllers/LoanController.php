<?php

namespace App\Http\Controllers;

use App\Accounting\Money;
use App\Http\Controllers\Concerns\RequiresIdempotencyKey;
use App\Loans\LoanService;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\SavingsAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LoanController
{
    use RequiresIdempotencyKey;

    public function products(): JsonResponse
    {
        return response()->json(['data' => LoanProduct::query()->orderBy('code')->get()->map(fn (LoanProduct $p) => $p->only([
            'id', 'code', 'name', 'collateral_type', 'interest_rate', 'penalty_percent', 'service_fee_percent', 'repayment_frequency',
            'schedule_method', 'max_term_months', 'max_grace_months', 'max_amount', 'max_ltv_percent', 'is_productive_sector', 'status',
        ]))]);
    }

    public function createProduct(Request $request, LoanService $loans): JsonResponse
    {
        $v = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'collateral_type' => ['required', Rule::in(['savings', 'real_estate', 'unsecured'])],
            'interest_rate' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,6})?$/'],
            'penalty_percent' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'service_fee_percent' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'repayment_frequency' => ['required', Rule::in(['monthly', 'quarterly'])],
            'schedule_method' => ['required', Rule::in(['equal_installment', 'equal_principal'])],
            'max_term_months' => ['required', 'integer', 'min:1', 'max:600'],
            'max_grace_months' => ['nullable', 'integer', 'min:0'],
            'max_amount' => ['required', 'string'],
            'max_ltv_percent' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'is_productive_sector' => ['nullable', 'boolean'],
            'ledger_account_code' => ['required', 'string', 'max:32'],
        ]);

        return response()->json(['data' => ['id' => $loans->createProduct($v, $request->user())->id]], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['status' => ['nullable', Rule::in(['applied', 'approved', 'rejected', 'disbursed', 'closed'])]]);
        $page = Loan::query()->with(['product', 'member'])->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->cursorPaginate(50);

        return response()->json(['data' => $page->getCollection()->map($this->present(...)), 'next_cursor' => $page->nextCursor()?->encode()]);
    }

    public function memberLoans(Member $member): JsonResponse
    {
        return response()->json(['data' => Loan::query()->where('member_id', $member->id)->with(['product', 'member'])->orderBy('id')->get()->map($this->present(...))]);
    }

    public function apply(Request $request, Member $member, LoanService $loans): JsonResponse
    {
        $v = $request->validate([
            'loan_product_id' => ['required', 'integer'],
            'principal' => ['required', 'string'],
            'term_months' => ['required', 'integer', 'min:1'],
            'grace_months' => ['nullable', 'integer', 'min:0'],
            'purpose' => ['required', 'string', 'max:300'],
            'pledged_savings_account_id' => ['nullable', 'integer'],
            'collateral_value' => ['nullable', 'string'],
            'collateral_description' => ['nullable', 'string', 'max:500'],
            'guarantor_member_ids' => ['nullable', 'array', 'max:10'],
            'guarantor_member_ids.*' => ['integer'],
            'cic_report_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $product = LoanProduct::query()->findOrFail($v['loan_product_id']);

        return response()->json(['data' => $this->present($loans->apply($member, $product, $v, $request->user())->load(['product', 'member']))], 201);
    }

    public function show(Loan $loan): JsonResponse
    {
        $loan->load(['product', 'member', 'schedule']);

        return response()->json(['data' => $this->present($loan) + [
            'schedule' => $loan->schedule->map(fn ($s) => [
                'installment_number' => $s->installment_number, 'due_date' => $s->due_date->format('Y-m-d'),
                'principal_due' => Money::format($s->principal_due), 'interest_projected' => Money::format($s->interest_projected),
                'principal_paid' => Money::format($s->principal_paid),
            ]),
            'transactions' => DB::table('loan_transactions')->where('loan_id', $loan->id)->orderBy('id')->get()->map(fn ($t) => [
                'id' => $t->id, 'type' => $t->type, 'date' => substr($t->created_at, 0, 10), 'amount' => Money::format($t->amount),
                'principal' => Money::format($t->principal), 'interest' => Money::format($t->interest), 'penalty' => Money::format($t->penalty),
                'fee' => Money::format($t->fee), 'outstanding_after' => Money::format($t->outstanding_after),
            ]),
        ]]);
    }

    public function approve(Request $request, Loan $loan, LoanService $loans): JsonResponse
    {
        return response()->json(['data' => $this->present($loans->approve($loan, $request->user())->load(['product', 'member']))]);
    }

    public function reject(Request $request, Loan $loan, LoanService $loans): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->present($loans->reject($loan, $request->user(), $v['reason'])->load(['product', 'member']))]);
    }

    public function disburse(Request $request, Loan $loan, LoanService $loans): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        $v = $request->validate(['savings_account_id' => ['required', 'integer']]);
        $account = SavingsAccount::query()->findOrFail($v['savings_account_id']);

        return response()->json(['data' => $this->present($loans->disburse($loan, $account, $request->user(), $key)->load(['product', 'member']))], 201);
    }

    private function present(Loan $l): array
    {
        return [
            'id' => $l->id, 'loan_number' => $l->loan_number, 'status' => $l->status,
            'member' => ['id' => $l->member->id, 'member_number' => $l->member->member_number, 'full_name' => $l->member->full_name],
            'product' => ['id' => $l->product->id, 'code' => $l->product->code, 'name' => $l->product->name, 'collateral_type' => $l->product->collateral_type],
            'principal' => Money::format($l->principal), 'interest_rate' => $l->interest_rate, 'term_months' => $l->term_months,
            'grace_months' => $l->grace_months, 'purpose' => $l->purpose, 'outstanding_principal' => Money::format($l->outstanding_principal),
            'classification' => $l->classification, 'applied_on' => $l->applied_on->format('Y-m-d'),
            'disbursed_on' => $l->disbursed_on?->format('Y-m-d'), 'interest_paid_through' => $l->interest_paid_through?->format('Y-m-d'),
            'decision_reason' => $l->decision_reason,
        ];
    }
}
