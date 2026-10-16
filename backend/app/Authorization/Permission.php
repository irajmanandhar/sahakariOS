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
    case MemberView = 'member.view';
    case MemberApply = 'member.apply';
    case MemberDecide = 'member.decide';
    case MemberDecideHighRisk = 'member.decide_high_risk';
    case MemberKycView = 'member.kyc_view';
    case MemberKycUpdate = 'member.kyc_update';
    case MemberRiskAssess = 'member.risk_assess';
    case ShareView = 'share.view';
    case SharePurchase = 'share.purchase';
    case AmlView = 'aml.view';
    case SavingsView = 'savings.view';
    case SavingsProductManage = 'savings.product_manage';
    case SavingsOpen = 'savings.open';
    case SavingsDeposit = 'savings.deposit';
    case SavingsWithdraw = 'savings.withdraw';
    case SavingsLargeCashWithdrawal = 'savings.large_cash_withdrawal';
    case LoanView = 'loan.view';
    case LoanProductManage = 'loan.product_manage';
    case LoanApply = 'loan.apply';
    case LoanDecide = 'loan.decide';
    case LoanDisburse = 'loan.disburse';
    case LoanRepay = 'loan.repay';
}
