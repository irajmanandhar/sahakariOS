<?php

namespace App\Http\Controllers;

use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Read-only audit trail of the selected cooperative (its own chain only). */
class AuditLogController
{
    public function index(TenantContext $tenant): JsonResponse
    {
        $page = DB::table('audit_logs')
            ->where('cooperative_id', $tenant->cooperativeId())
            ->orderByDesc('id')
            ->select(['id', 'sequence', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'data', 'ip_address', 'occurred_at'])
            ->cursorPaginate(50);

        $page->getCollection()->transform(function ($row) {
            $row->data = $row->data === null ? null : json_decode($row->data, true);

            return $row;
        });

        return response()->json($page);
    }
}
