<?php

namespace App\Http\Controllers;

use App\Accounting\Money;
use App\Http\Controllers\Concerns\RequiresIdempotencyKey;
use App\Models\Member;
use App\Shares\ShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShareController
{
    use RequiresIdempotencyKey;

    public function index(Member $member, ShareService $shares): JsonResponse
    {
        $rows = DB::table('share_transactions')->where('member_id', $member->id)->orderBy('id')->get()
            ->map(fn ($r) => ['id' => $r->id, 'type' => $r->type, 'units' => (int) $r->units, 'amount' => Money::format($r->amount), 'date' => substr($r->created_at, 0, 10)]);
        $units = $shares->units($member);

        return response()->json(['data' => [
            'units' => $units,
            'amount' => Money::format(bcmul((string) $units, ShareService::FACE_VALUE, 2)),
            'transactions' => $rows,
        ]]);
    }

    public function purchase(Request $request, Member $member, ShareService $shares): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        $v = $request->validate([
            'units' => ['required', 'integer', 'min:1', 'max:1000000'],
            'source_of_funds' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $shares->purchase($member, $v['units'], $request->user(), $key, $v['source_of_funds'] ?? null)], 201);
    }
}
