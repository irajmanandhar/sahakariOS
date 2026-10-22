/**
 * Values the UI uses to show the right prompt at the right time. They mirror rules the API
 * enforces (sources in docs/savings.md, docs/members.md and the regulatory register); the API
 * still decides every case, and its message is shown as sent. Change them only with the API.
 */

/** Source of funds required from Rs 10 lakh (AML Instruction 8; register K-12 uses "10 lakh or more"). */
export const SOURCE_OF_FUNDS_FROM = '1000000.00';

/** Cash withdrawal above Rs 5 lakh needs a written reason and savings.large_cash_withdrawal (AML Guidance, 15 Jan 2026). */
export const LARGE_CASH_WITHDRAWAL_ABOVE = '500000.00';

/** Share face value (Act s.63). */
export const SHARE_FACE_VALUE_PAISA = 10000n;

/** Member documents: JPEG, PNG or PDF up to 5 MB (MembershipService::DOCUMENT_MAX_BYTES). */
export const DOCUMENT_MAX_BYTES = 5 * 1024 * 1024;
export const DOCUMENT_TYPES = ['photo', 'citizenship', 'national_id', 'voter_id', 'passport', 'driving_licence', 'pan', 'staff_id', 'thumbprint_right', 'thumbprint_left', 'signature', 'kym_form', 'other'] as const;
export const ID_DOCUMENTS = ['citizenship', 'national_id', 'voter_id', 'passport', 'driving_licence', 'pan'];
/** Needed before approval: photo, both thumbprints and one ID copy (docs/members.md). */
export const REQUIRED_DOCUMENTS = ['photo', 'thumbprint_right', 'thumbprint_left'];
