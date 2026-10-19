<?php

namespace App\Http\Controllers;

use App\Accounting\Money;
use App\Audit\AuditLogger;
use App\Calendar\BsCalendar;
use App\Calendar\OutOfCalendarRange;
use App\Http\Controllers\Concerns\RequiresIdempotencyKey;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Savings\SavingsService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /** Passbook: every row with BS and AD dates, voucher, debit/credit, running balance and what could be withdrawn. */
    public function statement(SavingsAccount $savingsAccount): JsonResponse
    {
        $savingsAccount->load('product');

        return response()->json(['data' => $this->present($savingsAccount) + [
            'legacy_account_number' => $savingsAccount->legacy_account_number,
            'minimum_balance' => Money::format($savingsAccount->product->minimum_balance),
            'accrued_interest' => Money::format((string) BigDecimal::of($savingsAccount->accrued_interest)->toScale(2, RoundingMode::Down)),
            'transactions' => $this->passbook($savingsAccount),
        ]]);
    }

    /** The same passbook as CSV (opens in Excel), for auditors. Each export is recorded in the audit log. */
    public function passbookCsv(Request $request, SavingsAccount $savingsAccount, AuditLogger $audit): StreamedResponse
    {
        $savingsAccount->load('product');
        $rows = $this->passbook($savingsAccount);
        $audit->record('savings_account.passbook_exported', $savingsAccount, ['rows' => count($rows)], $request->user());

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 marker so Excel shows Nepali text
            fputcsv($out, ['Nepali date', 'English date', 'Voucher No.', 'Particulars', 'Debit', 'Credit', 'Can withdraw', 'Balance'], escape: '');
            foreach ($rows as $r) {
                fputcsv($out, [$r['date_bs'], $r['date'], $r['reference'], $r['particulars'], $r['debit'], $r['credit'], $r['can_withdraw'], $r['balance']], escape: '');
            }
            fclose($out);
        }, "passbook-{$savingsAccount->account_number}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function passbook(SavingsAccount $account): array
    {
        $minimum = BigDecimal::of($account->product->minimum_balance);

        return DB::table('savings_transactions as t')->join('journal_entries as e', 'e.id', '=', 't.journal_entry_id')
            ->where('t.savings_account_id', $account->id)->orderBy('t.id')
            ->get(['t.id', 't.type', 't.channel', 't.amount', 't.balance_after', 't.narration', 't.reference', 'e.entry_date', 'e.entry_number'])
            ->map(function ($r) use ($minimum) {
                $credit = in_array($r->type, SavingsService::CREDIT_TYPES, true);
                $available = BigDecimal::of($r->balance_after)->minus($minimum);

                return [
                    'id' => $r->id, 'date' => $r->entry_date, 'date_bs' => $this->bs($r->entry_date), 'reference' => $r->reference,
                    'journal_entry_number' => $r->entry_number, 'type' => $r->type, 'channel' => $r->channel,
                    'particulars' => $r->narration ?? ucfirst(str_replace('_', ' ', $r->type)),
                    'debit' => $credit ? null : Money::format($r->amount), 'credit' => $credit ? Money::format($r->amount) : null,
                    'amount' => Money::format($r->amount), 'balance' => Money::format($r->balance_after), 'balance_after' => Money::format($r->balance_after),
                    'can_withdraw' => Money::format($available->isNegative() ? '0' : (string) $available),
                    'narration' => $r->narration,
                ];
            })->all();
    }

    private function bs(string $ad): ?string
    {
        try {
            return (string) app(BsCalendar::class)->toBs($ad);
        } catch (OutOfCalendarRange) {
            return null;
        }
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
