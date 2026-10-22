import AccountBalanceOutlined from '@mui/icons-material/AccountBalanceOutlined';
import AssessmentOutlined from '@mui/icons-material/AssessmentOutlined';
import DashboardOutlined from '@mui/icons-material/DashboardOutlined';
import FactCheckOutlined from '@mui/icons-material/FactCheckOutlined';
import GavelOutlined from '@mui/icons-material/GavelOutlined';
import GroupsOutlined from '@mui/icons-material/GroupsOutlined';
import PieChartOutlined from '@mui/icons-material/PieChartOutlined';
import RequestQuoteOutlined from '@mui/icons-material/RequestQuoteOutlined';
import SavingsOutlined from '@mui/icons-material/SavingsOutlined';
import SettingsOutlined from '@mui/icons-material/SettingsOutlined';
import UploadFileOutlined from '@mui/icons-material/UploadFileOutlined';
import type { SvgIconComponent } from '@mui/icons-material';
import type { MessageKey } from '@/i18n';

export type NavItem = { label: MessageKey; href: string; permission?: string };
export type NavGroup = { label: MessageKey; icon: SvgIconComponent; items: NavItem[] };

/**
 * Sidebar information architecture. An item shows only with its permission (cosmetic: the API
 * enforces every action). A group with one visible item renders as a plain link.
 */
export const NAV: NavGroup[] = [
  { label: 'nav.dashboard', icon: DashboardOutlined, items: [{ label: 'nav.dashboard', href: '/' }] },
  {
    label: 'nav.members', icon: GroupsOutlined, items: [
      { label: 'nav.memberList', href: '/members', permission: 'member.view' },
      { label: 'nav.newApplication', href: '/members/new', permission: 'member.apply' },
      { label: 'nav.kymDue', href: '/members?filter=review', permission: 'member.view' },
    ],
  },
  { label: 'nav.shares', icon: PieChartOutlined, items: [{ label: 'nav.shares', href: '/shares', permission: 'share.view' }] },
  {
    label: 'nav.savings', icon: SavingsOutlined, items: [
      { label: 'nav.savings', href: '/savings', permission: 'savings.view' },
      { label: 'nav.savingsProducts', href: '/settings/savings-products', permission: 'savings.view' },
    ],
  },
  {
    label: 'nav.loans', icon: RequestQuoteOutlined, items: [
      { label: 'nav.loanList', href: '/loans', permission: 'loan.view' },
      { label: 'nav.provisioning', href: '/loans/provisioning', permission: 'loan.view' },
      { label: 'nav.loanProducts', href: '/settings/loan-products', permission: 'loan.view' },
    ],
  },
  {
    label: 'nav.ledger', icon: AccountBalanceOutlined, items: [
      { label: 'nav.trialBalance', href: '/ledger', permission: 'ledger.view' },
      { label: 'nav.journal', href: '/ledger/journal', permission: 'ledger.view' },
      { label: 'nav.newJournal', href: '/ledger/journal/new', permission: 'journal.post' },
      { label: 'nav.chartOfAccounts', href: '/ledger/accounts', permission: 'ledger.view' },
      { label: 'nav.banking', href: '/banking', permission: 'bank.reconcile' },
    ],
  },
  {
    label: 'nav.reports', icon: AssessmentOutlined, items: [
      { label: 'nav.statements', href: '/reports', permission: 'report.view' },
      { label: 'nav.dayBook', href: '/reports/day-book', permission: 'report.view' },
    ],
  },
  {
    label: 'nav.aml', icon: GavelOutlined, items: [
      { label: 'nav.threshold', href: '/aml/threshold', permission: 'aml.view' },
      { label: 'nav.highRisk', href: '/aml/risk', permission: 'member.view' },
    ],
  },
  {
    label: 'nav.audit', icon: FactCheckOutlined, items: [
      { label: 'nav.auditLog', href: '/audit', permission: 'audit_log.view' },
      { label: 'nav.integrity', href: '/audit/integrity', permission: 'ledger.view' },
    ],
  },
  {
    label: 'nav.settings', icon: SettingsOutlined, items: [
      { label: 'nav.ledgerRoles', href: '/settings/ledger', permission: 'ledger.view' },
      { label: 'nav.branches', href: '/settings/branches' },
      { label: 'nav.roles', href: '/settings/roles' },
      { label: 'nav.sms', href: '/settings/sms', permission: 'notification.view' },
    ],
  },
  { label: 'nav.import', icon: UploadFileOutlined, items: [{ label: 'nav.import', href: '/settings/import', permission: 'migration.import' }] },
];

export function visibleNav(can: (p: string) => boolean): NavGroup[] {
  return NAV.map((g) => ({ ...g, items: g.items.filter((i) => !i.permission || can(i.permission)) })).filter((g) => g.items.length > 0);
}

/** The item whose href best matches the current path (longest prefix wins). */
export function activeHref(pathname: string, search: string, groups: NavGroup[]): string | null {
  let best: string | null = null;
  for (const item of groups.flatMap((g) => g.items)) {
    const [path, query] = item.href.split('?');
    const matches = query ? pathname === path && search.includes(query) : path === '/' ? pathname === '/' : pathname === path || pathname.startsWith(`${path}/`);
    if (matches && (!best || item.href.length > best.length)) best = item.href;
  }
  return best;
}
