/**
 * Shapes of the Laravel API responses, as the controllers in backend/app/Http/Controllers
 * return them. Money and rates are decimal strings; dates are AD "YYYY-MM-DD" unless named *_bs.
 */

export type Money = string;

/** Cursor-paginated list (members, loans, journal entries, audit logs). */
export type Page<T> = { data: T[]; next_cursor: string | null };

export type User = { id: number; name: string; email: string };

export type Membership = {
  cooperative: { id: number; code: string; name: string; status: string };
  branch: { id: number; code: string; name: string };
  /** Present on the current selection only. Hides actions in the UI; the API enforces them. */
  permissions?: string[];
};

export type BusinessDay = {
  date: string;
  date_bs: string;
  date_bs_text: string;
  fiscal_year: string;
  status: string;
};

export type Branch = { id: number; code: string; name: string; is_head_office: boolean; status: string };

export type MemberStatus = 'applied' | 'active' | 'rejected' | 'exited';
export type RiskLevel = 'low' | 'medium' | 'high';

export type MemberDocument = { id: number; type: string; mime_type: string; size_bytes: number; uploaded_at: string };

export type Member = {
  id: number;
  member_number: number | null;
  status: MemberStatus;
  full_name: string;
  full_name_ne: string | null;
  gender: string | null;
  date_of_birth: string | null;
  date_of_birth_bs: string | null;
  phone: string | null;
  is_pep: boolean;
  risk_level: RiskLevel | null;
  next_kyc_review_on: string | null;
  applied_on: string;
  days_to_decide: number | null;
  decided_at: string | null;
  decision_reason: string | null;
  documents?: MemberDocument[];
};

export type Kyc = { version: number; recorded_at: string; verified_in_person_at: string; kym: Record<string, unknown> };

export type ShareHolding = { units: number; amount: Money; transactions: { id: number; type: string; units: number; amount: Money; date: string }[] };

export type SavingsProduct = {
  id: number; code: string; name: string; type: 'general' | 'regular' | 'term'; interest_rate: string;
  interest_posting: string; minimum_balance: Money; term_months: number | null; status: string;
};

export type SavingsAccount = {
  id: number; account_number: number; member_id: number;
  product: { id: number; code: string; name: string; type: string };
  status: string; balance: Money; hold_amount: Money; opened_on: string; maturity_date: string | null;
};

export type PassbookLine = {
  id: number; date: string; date_bs: string | null; reference: string | null; journal_entry_number: number;
  type: string; channel: string | null; particulars: string; debit: Money | null; credit: Money | null;
  amount: Money; balance: Money; can_withdraw: Money; narration: string | null;
};

export type SavingsStatement = SavingsAccount & {
  legacy_account_number: string | null; minimum_balance: Money; accrued_interest: Money; transactions: PassbookLine[];
};

export type CollateralType = 'savings' | 'real_estate' | 'unsecured';
export type LoanStatus = 'applied' | 'approved' | 'rejected' | 'disbursed' | 'closed';
export type Classification = 'pass' | 'substandard' | 'doubtful' | 'loss';

export type LoanProduct = {
  id: number; code: string; name: string; collateral_type: CollateralType; interest_rate: string; penalty_percent: string;
  service_fee_percent: string; repayment_frequency: 'monthly' | 'quarterly'; schedule_method: 'equal_installment' | 'equal_principal';
  max_term_months: number; max_grace_months: number; max_amount: Money; max_ltv_percent: string | null;
  is_productive_sector: boolean; status: string;
};

export type Loan = {
  id: number; loan_number: number; status: LoanStatus;
  member: { id: number; member_number: number | null; full_name: string };
  product: { id: number; code: string; name: string; collateral_type: CollateralType };
  principal: Money; interest_rate: string; term_months: number; grace_months: number; purpose: string;
  outstanding_principal: Money; classification: Classification | null; applied_on: string;
  disbursed_on: string | null; interest_paid_through: string | null; decision_reason: string | null;
};

export type LoanDetail = Loan & {
  schedule: { installment_number: number; due_date: string; principal_due: Money; interest_projected: Money; principal_paid: Money }[];
  transactions: { id: number; type: string; date: string; amount: Money; principal: Money; interest: Money; penalty: Money; fee: Money; outstanding_after: Money }[];
};

export type RepaymentQuote = { date: string; penalty: Money; interest: Money; principal_overdue: Money; outstanding_principal: Money; minimum_payment: Money; payoff: Money };
export type RepaymentResult = { transaction_id: number; amount: Money; principal: Money; interest: Money; penalty: Money; outstanding_after: Money };
export type ProvisioningResult = { date: string; counts: Partial<Record<Classification, number>>; required_provision: Money; previous_provision: Money; change: Money };
/** What a savings deposit or withdrawal returns. */
export type SavingsPosting = { transaction_id: number; type: string; amount: Money; balance_after: Money; balance: Money; journal_entry_id: number };

export type LedgerAccountType = 'asset' | 'liability' | 'equity' | 'income' | 'expense';

export type LedgerAccount = {
  id: number; code: string; name: string; type: LedgerAccountType; normal_side: 'debit' | 'credit';
  is_postable: boolean; parent_id: number | null; status: string;
};

export type JournalSummary = {
  id: number; reference: string; entry_date: string; entry_date_bs: string; description: string;
  reversal_of_id: number | null; posted_by: number | null; posted_at: string; total?: Money;
};

export type JournalLine = {
  line_number: number; account_code: string; account_name: string; branch_id: number | null;
  debit: Money | null; credit: Money | null; description: string | null;
};

export type JournalEntry = JournalSummary & { reversed_by_id: number | null; lines: JournalLine[] };

export type TrialBalanceRow = { account_id: number; code: string; name: string; type: LedgerAccountType; debit_balance: Money; credit_balance: Money };
export type TrialBalance = { as_of: string | null; accounts: TrialBalanceRow[]; total_debit: Money; total_credit: Money; balanced: boolean };

export type ReportRow = { label: string; amount: Money; note?: string };
export type ReportCheck = { label: string; value: string; requirement: string; ok: boolean; source: string };
export type Report = {
  code: string; title: string; as_of?: string; from?: string; to?: string;
  sections: { title: string; rows: ReportRow[]; total?: Money }[]; checks: ReportCheck[];
};

export type DayBook = {
  from: string; to: string; products: string[]; opening: Record<string, Money>; closing: Record<string, Money>;
  days: { date: string; date_bs: string | null; products: Record<string, { credit: Money; debit: Money; balance: Money }> }[];
};

export type ThresholdTransaction = { business_date: string; report_by: string; member_number: number; full_name: string; direction: string; total: Money; transactions: number };

export type AuditLog = {
  id: number; sequence: number; actor_type: string | null; actor_id: number | null; action: string;
  subject_type: string | null; subject_id: number | null; data: Record<string, unknown> | null; ip_address: string | null; occurred_at: string;
};

export type LedgerMapping = { role: string; description: string; account_type: LedgerAccountType; ledger_account_id: number | null };
export type LedgerTags = { tags: { tag: string; description: string; account_type: string }[]; assigned: { code: string; tag: string }[] };

export type ImportResult = { ok: boolean; dry_run: boolean; lines: string[] };

export type Reconciliation = {
  account: string; as_of: string;
  ledger_balance: Money; statement_balance: Money; adjusted_ledger_balance: Money; reconciled: boolean;
  unmatched_ledger: { date: string; description: string; amount: Money }[];
  unmatched_statement: { date: string; description: string; amount: Money }[];
};

export type SmsMessage = { id: number; member_id: number | null; body: string; status: string; attempts: number; sent_at: string | null; created_at: string };
