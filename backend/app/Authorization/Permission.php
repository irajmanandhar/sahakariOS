<?php

namespace App\Authorization;

/**
 * Every permission the application checks. Roles (per cooperative) are sets
 * of these values. Add a case only together with the code that checks it.
 */
enum Permission: string
{
    case BusinessDayOpen = 'business_day.open';
    case BusinessDayClose = 'business_day.close';
    case AuditLogView = 'audit_log.view';
    case LedgerView = 'ledger.view';
    case LedgerAccountManage = 'ledger_account.manage';
    case JournalPost = 'journal.post';
    case JournalReverse = 'journal.reverse';
}
