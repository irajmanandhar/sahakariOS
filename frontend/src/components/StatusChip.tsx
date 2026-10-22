'use client';

import BlockOutlined from '@mui/icons-material/BlockOutlined';
import CheckCircleOutlined from '@mui/icons-material/CheckCircleOutlined';
import ErrorOutlined from '@mui/icons-material/ErrorOutlined';
import HelpOutlineOutlined from '@mui/icons-material/HelpOutlineOutlined';
import HourglassEmptyOutlined from '@mui/icons-material/HourglassEmptyOutlined';
import LockOutlined from '@mui/icons-material/LockOutlined';
import ReportProblemOutlined from '@mui/icons-material/ReportProblemOutlined';
import UndoOutlined from '@mui/icons-material/UndoOutlined';
import Chip from '@mui/material/Chip';
import type { ReactElement } from 'react';
import { type MessageKey, useT } from '@/i18n';

type Tone = 'success' | 'warning' | 'error' | 'info' | 'default' | 'primary';
type Spec = { tone: Tone; icon: ReactElement; label: MessageKey; outlined?: boolean };

const ok = <CheckCircleOutlined />;
const wait = <HourglassEmptyOutlined />;
const stop = <BlockOutlined />;
const warn = <ReportProblemOutlined />;
const bad = <ErrorOutlined />;

/**
 * Every status the app shows, with colour + icon + words (colour is never the only signal).
 * Loan classes follow NCRA Std 3/3; risk levels AML/CFT Instruction 3.
 */
const SPECS = {
  member: {
    applied: { tone: 'warning', icon: wait, label: 'status.member.applied' },
    active: { tone: 'success', icon: ok, label: 'status.member.active' },
    rejected: { tone: 'default', icon: stop, label: 'status.member.rejected' },
    exited: { tone: 'default', icon: stop, label: 'status.member.exited' },
  },
  risk: {
    low: { tone: 'success', icon: ok, label: 'status.risk.low', outlined: true },
    medium: { tone: 'warning', icon: warn, label: 'status.risk.medium', outlined: true },
    high: { tone: 'error', icon: bad, label: 'status.risk.high', outlined: true },
    none: { tone: 'default', icon: <HelpOutlineOutlined />, label: 'status.risk.none', outlined: true },
  },
  loan: {
    applied: { tone: 'warning', icon: wait, label: 'status.loan.applied' },
    approved: { tone: 'info', icon: ok, label: 'status.loan.approved' },
    rejected: { tone: 'default', icon: stop, label: 'status.loan.rejected' },
    disbursed: { tone: 'primary', icon: ok, label: 'status.loan.disbursed' },
    closed: { tone: 'default', icon: <LockOutlined />, label: 'status.loan.closed' },
  },
  classification: {
    pass: { tone: 'success', icon: ok, label: 'status.classification.pass' },
    substandard: { tone: 'warning', icon: warn, label: 'status.classification.substandard' },
    doubtful: { tone: 'error', icon: warn, label: 'status.classification.doubtful', outlined: true },
    loss: { tone: 'error', icon: bad, label: 'status.classification.loss' },
  },
  journal: {
    posted: { tone: 'success', icon: ok, label: 'status.journal.posted', outlined: true },
    reversal: { tone: 'warning', icon: <UndoOutlined />, label: 'status.journal.reversal' },
    reversed: { tone: 'default', icon: <UndoOutlined />, label: 'status.journal.reversed', outlined: true },
  },
  check: {
    ok: { tone: 'success', icon: ok, label: 'status.check.ok' },
    fail: { tone: 'error', icon: bad, label: 'status.check.fail' },
  },
  balance: {
    ok: { tone: 'success', icon: ok, label: 'status.balanced' },
    fail: { tone: 'error', icon: bad, label: 'status.notBalanced' },
  },
  sms: {
    pending: { tone: 'default', icon: wait, label: 'status.sms.pending' },
    sent: { tone: 'success', icon: ok, label: 'status.sms.sent' },
    failed: { tone: 'error', icon: bad, label: 'status.sms.failed' },
  },
  account: {
    active: { tone: 'success', icon: ok, label: 'status.account.active', outlined: true },
    dormant: { tone: 'warning', icon: wait, label: 'status.account.dormant', outlined: true },
    closed: { tone: 'default', icon: <LockOutlined />, label: 'status.account.closed', outlined: true },
    inactive: { tone: 'default', icon: stop, label: 'status.account.inactive', outlined: true },
  },
} satisfies Record<string, Record<string, Spec>>;

export type StatusKind = keyof typeof SPECS;

export default function StatusChip({ kind, value }: { kind: StatusKind; value: string | null | undefined }) {
  const t = useT();
  const specs: Record<string, Spec> = SPECS[kind];
  const spec = specs[value ?? 'none'];
  if (!spec) return <Chip variant="outlined" label={value ?? '—'} />;
  return <Chip color={spec.tone} variant={spec.outlined ? 'outlined' : 'filled'} icon={spec.icon} label={t(spec.label)} />;
}

export function MemberStatusChip({ status }: { status: string }) {
  return <StatusChip kind="member" value={status} />;
}

export function RiskChip({ level }: { level: string | null }) {
  return <StatusChip kind="risk" value={level ?? 'none'} />;
}
