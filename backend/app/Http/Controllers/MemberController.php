<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Calendar\BsCalendar;
use App\Calendar\BusinessDayService;
use App\Calendar\OutOfCalendarRange;
use App\Members\KymForm;
use App\Members\MembershipService;
use App\Models\Member;
use App\Models\MemberDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberController
{
    public function __construct(private BsCalendar $calendar) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'status' => ['nullable', Rule::in(['applied', 'active', 'rejected', 'exited'])],
            'kyc_review_due' => ['nullable', 'boolean'],
        ]);

        $page = Member::query()
            ->when($v['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(filter_var($v['kyc_review_due'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q
                ->where('status', 'active')->where('next_kyc_review_on', '<=', BusinessDayService::today()))
            ->orderByDesc('id')->cursorPaginate(50);

        return response()->json([
            'data' => $page->getCollection()->map($this->present(...)),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function show(Member $member): JsonResponse
    {
        return response()->json(['data' => $this->present($member) + [
            'documents' => $member->documents()->orderBy('id')->get()->map(fn (MemberDocument $d) => [
                'id' => $d->id, 'type' => $d->type, 'mime_type' => $d->mime_type, 'size_bytes' => $d->size_bytes,
                'uploaded_at' => $d->created_at->toIso8601String(),
            ]),
        ]]);
    }

    public function store(Request $request, MembershipService $membership): JsonResponse
    {
        $v = $request->validate($this->coreRules() + KymForm::rules());
        $core = collect($v)->except('kym')->all();
        foreach (['declares_nepali_citizen', 'declares_resides_in_working_area', 'declares_no_other_same_type_membership'] as $f) {
            $core[$f] = filter_var($core[$f], FILTER_VALIDATE_BOOLEAN);
        }

        return response()->json(['data' => $this->present($membership->apply($core, $v['kym'], $request->user()))], 201);
    }

    /** Decrypted KYM form. Sensitive: separate permission, and every view is audited. */
    public function kyc(Request $request, Member $member, AuditLogger $audit): JsonResponse
    {
        $latest = $member->latestKyc;
        $audit->record('member.kyc_viewed', $member, ['version' => $latest?->version]);

        return response()->json(['data' => $latest ? [
            'version' => $latest->version,
            'recorded_at' => $latest->created_at->toIso8601String(),
            'verified_in_person_at' => $latest->verified_in_person_at->toIso8601String(),
            'kym' => $latest->payload,
        ] : null]);
    }

    public function updateKyc(Request $request, Member $member, MembershipService $membership): JsonResponse
    {
        $v = $request->validate(KymForm::rules());

        return response()->json(['data' => $this->present($membership->updateKyc($member, $v['kym'], $request->user()))]);
    }

    public function assessRisk(Request $request, Member $member, MembershipService $membership): JsonResponse
    {
        $v = $request->validate(['risk_level' => ['required', Rule::in(['low', 'medium', 'high'])], 'reason' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->present($membership->assessRisk($member, $v['risk_level'], $v['reason'], $request->user()))]);
    }

    public function uploadDocument(Request $request, Member $member, MembershipService $membership): JsonResponse
    {
        $v = $request->validate([
            'type' => ['required', Rule::in(['photo', ...KymForm::ID_DOCUMENTS, 'staff_id', 'thumbprint_right', 'thumbprint_left', 'signature', 'kym_form', 'other'])],
            'file' => ['required', 'file', 'max:'.(MembershipService::DOCUMENT_MAX_BYTES / 1024)],
        ]);

        $document = $membership->uploadDocument($member, $v['type'], $v['file'], $request->user());

        return response()->json(['data' => ['id' => $document->id, 'type' => $document->type, 'sha256' => $document->sha256]], 201);
    }

    /** Streams a private document. Tenant scoping (route binding) and the permission guard access; views are audited. */
    public function downloadDocument(Member $member, MemberDocument $document, AuditLogger $audit): StreamedResponse
    {
        abort_unless($document->member_id === $member->id, 404);
        $audit->record('member.document_viewed', $member, ['document_id' => $document->id, 'type' => $document->type]);

        return Storage::disk('local')->download($document->path, "{$document->type}-{$member->id}.".pathinfo($document->path, PATHINFO_EXTENSION), [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function approve(Request $request, Member $member, MembershipService $membership): JsonResponse
    {
        return response()->json(['data' => $this->present($membership->approve($member, $request->user()))]);
    }

    public function reject(Request $request, Member $member, MembershipService $membership): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->present($membership->reject($member, $request->user(), $v['reason']))]);
    }

    private function coreRules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'full_name_ne' => ['nullable', 'string', 'max:150'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['required', Rule::in(['female', 'male', 'other'])],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\- ]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'declares_nepali_citizen' => ['required', 'boolean'],
            'declares_resides_in_working_area' => ['required', 'boolean'],
            'declares_no_other_same_type_membership' => ['required', 'boolean'],
        ];
    }

    /** No ID numbers or KYM details here: those need member.kyc_view. */
    private function present(Member $m): array
    {
        return [
            'id' => $m->id,
            'member_number' => $m->member_number,
            'status' => $m->status,
            'full_name' => $m->full_name,
            'full_name_ne' => $m->full_name_ne,
            'gender' => $m->gender,
            'date_of_birth' => $m->date_of_birth?->format('Y-m-d'),
            'date_of_birth_bs' => $m->date_of_birth ? $this->bs($m->date_of_birth->format('Y-m-d')) : null,
            'phone' => $m->phone,
            'is_pep' => $m->is_pep,
            'risk_level' => $m->risk_level,
            'next_kyc_review_on' => $m->next_kyc_review_on?->format('Y-m-d'),
            'applied_on' => $m->applied_on->format('Y-m-d'),
            'days_to_decide' => MembershipService::daysToDecide($m),
            'decided_at' => $m->decided_at?->toIso8601String(),
            'decision_reason' => $m->decision_reason,
        ];
    }

    /** BS only within the verified calendar range (BS 2000-2083); otherwise null, never a guess. */
    private function bs(string $ad): ?string
    {
        try {
            return (string) $this->calendar->toBs($ad);
        } catch (OutOfCalendarRange) {
            return null;
        }
    }
}
