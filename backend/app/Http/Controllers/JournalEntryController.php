<?php

namespace App\Http\Controllers;

use App\Accounting\Money;
use App\Accounting\PostingService;
use App\Calendar\BsCalendar;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manual journals. PROPOSED: in production these should need a second person's
 * approval (maker-checker) — not built yet; today one permitted user posts.
 */
class JournalEntryController
{
    public function __construct(private BsCalendar $calendar) {}

    public function index(): JsonResponse
    {
        $page = JournalEntry::query()->with('fiscalYear')->withSum('lines', 'debit')
            ->orderByDesc('id')->cursorPaginate(50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (JournalEntry $e) => $this->summary($e) + ['total' => Money::format($e->lines_sum_debit)]),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request, PostingService $posting): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_\-:.]{8,100}$/', $key)) {
            return response()->json(['message' => 'An Idempotency-Key header (8-100 characters: letters, digits, - _ : .) is required.'], 422);
        }

        $v = $request->validate([
            'description' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*.account_code' => ['required', 'string', 'max:32'],
            'lines.*.debit' => ['nullable', 'string'],
            'lines.*.credit' => ['nullable', 'string'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = $posting->post($v['description'], $v['lines'], $request->user(), $key);

        return response()->json(['data' => $this->detail($entry)], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        return response()->json(['data' => $this->detail($journalEntry)]);
    }

    public function reverse(Request $request, JournalEntry $journalEntry, PostingService $posting): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        $reversal = $posting->reverse($journalEntry->load('lines'), $v['reason'], $request->user());

        return response()->json(['data' => $this->detail($reversal)], 201);
    }

    private function summary(JournalEntry $e): array
    {
        return [
            'id' => $e->id,
            'reference' => $e->reference(),
            'entry_date' => $e->entry_date->format('Y-m-d'),
            'entry_date_bs' => (string) $this->calendar->toBs($e->entry_date),
            'description' => $e->description,
            'reversal_of_id' => $e->reversal_of_id,
            'posted_by' => $e->posted_by,
            'posted_at' => $e->posted_at->toIso8601String(),
        ];
    }

    private function detail(JournalEntry $e): array
    {
        $e->loadMissing(['lines.account', 'reversal']);

        return $this->summary($e) + [
            'reversed_by_id' => $e->reversal?->id,
            'lines' => $e->lines->map(fn (JournalLine $l) => [
                'line_number' => $l->line_number,
                'account_code' => $l->account->code,
                'account_name' => $l->account->name,
                'branch_id' => $l->branch_id,
                'debit' => $l->debit,
                'credit' => $l->credit,
                'description' => $l->description,
            ])->all(),
        ];
    }
}
