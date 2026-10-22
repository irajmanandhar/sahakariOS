'use client';

import ChevronLeft from '@mui/icons-material/ChevronLeft';
import ChevronRight from '@mui/icons-material/ChevronRight';
import ExpandLess from '@mui/icons-material/ExpandLess';
import ExpandMore from '@mui/icons-material/ExpandMore';
import KeyboardOutlined from '@mui/icons-material/KeyboardOutlined';
import LogoutOutlined from '@mui/icons-material/LogoutOutlined';
import MenuIcon from '@mui/icons-material/Menu';
import SwapHorizOutlined from '@mui/icons-material/SwapHorizOutlined';
import TodayOutlined from '@mui/icons-material/TodayOutlined';
import VisibilityOutlined from '@mui/icons-material/VisibilityOutlined';
import Alert from '@mui/material/Alert';
import AppBar from '@mui/material/AppBar';
import Avatar from '@mui/material/Avatar';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Collapse from '@mui/material/Collapse';
import Divider from '@mui/material/Divider';
import Drawer from '@mui/material/Drawer';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemIcon from '@mui/material/ListItemIcon';
import ListItemText from '@mui/material/ListItemText';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Toolbar from '@mui/material/Toolbar';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { useColorScheme } from '@mui/material/styles';
import NextLink from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { type MouseEvent, type ReactNode, useEffect, useState } from 'react';
import { useT } from '@/i18n';
import { api } from '@/lib/api';
import { hasUnsavedChanges } from '@/lib/hooks';
import { setPreferences, usePreferences } from '@/lib/preferences';
import { useSession } from '@/lib/session';
import { useBsText } from '../BsDate';
import GlobalSearch from './GlobalSearch';
import IdleTimeout from './IdleTimeout';
import { activeHref, visibleNav } from './nav';
import Shortcuts from './Shortcuts';

const WIDE = 252;
const MINI = 64;

/** Leaving a page with unsaved changes asks first (in-app links only; reload/close use beforeunload). */
function guard(event: MouseEvent, message: string) {
  if (hasUnsavedChanges() && !window.confirm(message)) event.preventDefault();
}

function Brand({ mini }: { mini: boolean }) {
  return (
    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.25, px: mini ? 1.5 : 2.5, height: 64 }}>
      <Box aria-hidden sx={{ width: 28, height: 28, borderRadius: '8px 2px 8px 2px', bgcolor: 'secondary.main', display: 'grid', placeItems: 'center', color: 'secondary.contrastText', fontWeight: 800, fontSize: 15, flexShrink: 0 }}>स</Box>
      {!mini && <Typography sx={{ fontWeight: 700, letterSpacing: '-0.01em', fontSize: '1.05rem', color: 'sidebar.fg' }}>Sahakari<Box component="span" sx={{ color: 'secondary.main' }}>OS</Box></Typography>}
    </Box>
  );
}

function NavList({ mini, onNavigate }: { mini: boolean; onNavigate?: () => void }) {
  const t = useT();
  const { can } = useSession();
  const pathname = usePathname();
  const search = useSearchParams().toString();
  const groups = visibleNav(can);
  const active = activeHref(pathname, search, groups);
  const [open, setOpen] = useState<Record<string, boolean>>({});

  const itemSx = (selected: boolean, indent: boolean) => ({
    borderRadius: 1.5, mx: 1, my: 0.25, py: 0.75, pl: indent ? 6.5 : 1.5, color: selected ? 'sidebar.fg' : 'sidebar.muted', position: 'relative' as const,
    '&:hover': { bgcolor: 'sidebar.hover', color: 'sidebar.fg' },
    '&.Mui-selected, &.Mui-selected:hover': { bgcolor: 'sidebar.active', color: 'sidebar.fg' },
    '&.Mui-selected::before': { content: '""', position: 'absolute', left: -8, top: 8, bottom: 8, width: 3, borderRadius: 2, bgcolor: 'secondary.main' },
    '&.Mui-focusVisible': { outline: '2px solid', outlineColor: 'secondary.main' },
  });
  const message = t('common.unsavedConfirm');

  return (
    <List component="nav" aria-label={t('nav.main')} sx={{ py: 1 }}>
      {groups.map((g) => {
        const Icon = g.icon;
        const groupActive = g.items.some((i) => i.href === active);
        const single = g.items.length === 1;
        const expanded = open[g.label] ?? groupActive;
        const head = (
          <ListItemButton
            {...(single || mini ? { component: NextLink, href: g.items[0].href, onClick: (e: MouseEvent) => { guard(e, message); onNavigate?.(); } } : { onClick: () => setOpen((o) => ({ ...o, [g.label]: !expanded })), 'aria-expanded': expanded })}
            selected={(single || mini) && groupActive}
            aria-current={single && groupActive ? 'page' : undefined}
            sx={{ ...itemSx((single || mini) && groupActive, false), justifyContent: mini ? 'center' : undefined }}
          >
            <ListItemIcon sx={{ minWidth: mini ? 0 : 36, color: groupActive ? 'secondary.main' : 'inherit' }}><Icon fontSize="small" /></ListItemIcon>
            {!mini && <ListItemText primary={t(g.label)} slotProps={{ primary: { sx: { fontSize: '0.875rem', fontWeight: groupActive ? 650 : 500 } } }} />}
            {!mini && !single && (expanded ? <ExpandLess fontSize="small" /> : <ExpandMore fontSize="small" />)}
          </ListItemButton>
        );
        return (
          <Box key={g.label}>
            {mini ? <Tooltip title={t(g.label)} placement="right">{head}</Tooltip> : head}
            {!mini && !single && (
              <Collapse in={expanded} unmountOnExit>
                <List disablePadding>
                  {g.items.map((i) => (
                    <li key={i.href}>
                      <ListItemButton component={NextLink} href={i.href} selected={i.href === active} aria-current={i.href === active ? 'page' : undefined} onClick={(e: MouseEvent) => { guard(e, message); onNavigate?.(); }} sx={itemSx(i.href === active, true)}>
                        <ListItemText primary={t(i.label)} slotProps={{ primary: { sx: { fontSize: '0.8125rem' } } }} />
                      </ListItemButton>
                    </li>
                  ))}
                </List>
              </Collapse>
            )}
          </Box>
        );
      })}
    </List>
  );
}

function BusinessDayChip() {
  const t = useT();
  const { businessDay } = useSession();
  const bs = useBsText();
  if (!businessDay) {
    return <Chip variant="outlined" icon={<TodayOutlined />} label={t('shell.businessDayClosed')} component={NextLink} href="/" clickable />;
  }
  return (
    <Tooltip title={`${businessDay.date} AD · ${t('shell.fiscalYear', { fy: businessDay.fiscal_year })}`}>
      <Chip
        color="secondary"
        icon={<TodayOutlined />}
        label={t('shell.businessDayOpen', { date: bs(businessDay.date, null, 'long') ?? businessDay.date_bs_text })}
        component={NextLink}
        href="/"
        clickable
      />
    </Tooltip>
  );
}

function UserMenu({ onShortcuts }: { onShortcuts: () => void }) {
  const t = useT();
  const router = useRouter();
  const { user, membership } = useSession();
  const prefs = usePreferences();
  const { mode, setMode } = useColorScheme();
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const initials = user.name.split(' ').map((p) => p[0]).slice(0, 2).join('');
  const label = `${t('shell.userMenu')} ${initials}`; // visible initials are part of the name (WCAG 2.5.3)

  async function signOut() {
    await api('/auth/logout', { method: 'POST' }).catch(() => undefined);
    router.replace('/login');
  }

  return (
    <>
      <Tooltip title={t('shell.userMenu')}>
        <IconButton onClick={(e) => setAnchor(e.currentTarget)} aria-label={label} aria-haspopup="menu" aria-expanded={!!anchor}>
          <Avatar sx={{ width: 32, height: 32, bgcolor: 'primary.main', fontSize: 14 }}>{initials}</Avatar>
        </IconButton>
      </Tooltip>
      <Menu anchorEl={anchor} open={!!anchor} onClose={() => setAnchor(null)} slotProps={{ paper: { sx: { width: 300 } } }}>
        <Box sx={{ px: 2, py: 1.5 }}>
          <Typography sx={{ fontWeight: 650 }}>{user.name}</Typography>
          <Typography variant="body2" color="text.secondary">{user.email}</Typography>
          <Typography variant="caption" color="text.secondary">{membership.cooperative.name} · {t('shell.branch', { name: membership.branch.name })}</Typography>
        </Box>
        <Divider />
        <Box sx={{ px: 2, py: 1, display: 'grid', gap: 1 }}>
          <Typography variant="caption" color="text.secondary" id="lang-label">{t('shell.language')}</Typography>
          <ToggleButtonGroup size="small" exclusive fullWidth value={prefs.locale} onChange={(_, v) => v && setPreferences({ locale: v })} aria-labelledby="lang-label">
            <ToggleButton value="en" lang="en">{t('shell.english')}</ToggleButton>
            <ToggleButton value="ne" lang="ne">{t('shell.nepali')}</ToggleButton>
          </ToggleButtonGroup>
          <FormControlLabel control={<Switch size="small" checked={prefs.nepaliDigits} onChange={(e) => setPreferences({ nepaliDigits: e.target.checked })} />} label={t('shell.nepaliDigits')} />
          <Typography variant="caption" color="text.secondary" id="theme-label">{t('shell.theme')}</Typography>
          <ToggleButtonGroup size="small" exclusive fullWidth value={mode ?? 'system'} onChange={(_, v) => v && setMode(v)} aria-labelledby="theme-label">
            <ToggleButton value="light">{t('shell.themeLight')}</ToggleButton>
            <ToggleButton value="dark">{t('shell.themeDark')}</ToggleButton>
            <ToggleButton value="system">{t('shell.themeSystem')}</ToggleButton>
          </ToggleButtonGroup>
        </Box>
        <Divider />
        <MenuItem onClick={() => { setAnchor(null); onShortcuts(); }}><ListItemIcon><KeyboardOutlined fontSize="small" /></ListItemIcon>{t('shell.shortcuts')}</MenuItem>
        <MenuItem onClick={() => router.push('/select-cooperative')}><ListItemIcon><SwapHorizOutlined fontSize="small" /></ListItemIcon>{t('shell.switchCooperative')}</MenuItem>
        <MenuItem onClick={signOut}><ListItemIcon><LogoutOutlined fontSize="small" /></ListItemIcon>{t('shell.signOut')}</MenuItem>
      </Menu>
    </>
  );
}

/** Staff application frame: sidebar, top bar (search, business day, account), banners, idle warning. */
export default function AppShell({ children }: { children: ReactNode }) {
  const t = useT();
  const { membership, readOnly } = useSession();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [mini, setMini] = useState(false);
  const [shortcutsOpen, setShortcutsOpen] = useState(false);
  const pathname = usePathname();
  const width = mini ? MINI : WIDE;

  useEffect(() => {
    try {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- restore the collapsed sidebar after hydration
      setMini(window.localStorage.getItem('sahakarios-sidebar') === 'mini');
    } catch {
      // storage blocked
    }
  }, []);
  // After client navigation, move focus to the new page so screen readers announce it.
  const [lastPath, setLastPath] = useState(pathname);
  useEffect(() => {
    if (pathname === lastPath) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- remember the page focus was moved for
    setLastPath(pathname);
    document.getElementById('main')?.focus({ preventScroll: true });
  }, [pathname, lastPath]);

  const toggleMini = () => {
    setMini((m) => {
      try { window.localStorage.setItem('sahakarios-sidebar', m ? 'wide' : 'mini'); } catch { /* storage blocked */ }
      return !m;
    });
  };

  const sidebar = (isMini: boolean, close?: () => void) => (
    <Box sx={{ height: '100%', display: 'flex', flexDirection: 'column', bgcolor: 'sidebar.bg', color: 'sidebar.fg', overflowX: 'hidden' }}>
      <Brand mini={isMini} />
      <Box sx={{ flexGrow: 1, overflowY: 'auto' }}><NavList mini={isMini} onNavigate={close} /></Box>
      {!close && (
        <Box sx={{ p: 1, borderTop: 1, borderColor: 'rgba(255,255,255,0.08)' }}>
          <IconButton onClick={toggleMini} aria-label={isMini ? t('nav.expand') : t('nav.collapse')} sx={{ color: 'sidebar.muted' }}>
            {isMini ? <ChevronRight /> : <ChevronLeft />}
          </IconButton>
        </Box>
      )}
    </Box>
  );

  return (
    <Box sx={{ display: 'flex', minHeight: '100vh' }}>
      <Box component="a" href="#main" sx={{ position: 'absolute', left: -9999, zIndex: 2000, '&:focus': { left: 8, top: 8, bgcolor: 'background.paper', p: 1, borderRadius: 1 } }}>{t('nav.skipToContent')}</Box>
      <Drawer variant="permanent" className="no-print" sx={{ display: { xs: 'none', md: 'block' }, width, flexShrink: 0, '& .MuiDrawer-paper': { width, transition: 'width 150ms' } }}>
        {sidebar(mini)}
      </Drawer>
      <Drawer variant="temporary" open={mobileOpen} onClose={() => setMobileOpen(false)} className="no-print" sx={{ display: { md: 'none' }, '& .MuiDrawer-paper': { width: WIDE } }} ModalProps={{ keepMounted: false }}>
        {sidebar(false, () => setMobileOpen(false))}
      </Drawer>
      <Box sx={{ flexGrow: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        <AppBar position="sticky" color="inherit" elevation={0} className="no-print" sx={{ borderBottom: 1, borderColor: 'divider', bgcolor: 'background.paper' }}>
          <Toolbar sx={{ gap: 1.5, minHeight: { xs: 56, sm: 64 }, flexWrap: { xs: 'wrap', sm: 'nowrap' }, py: { xs: 1, sm: 0 } }}>
            <IconButton edge="start" onClick={() => setMobileOpen(true)} aria-label={t('nav.openMenu')} sx={{ display: { md: 'none' } }}><MenuIcon /></IconButton>
            <Box sx={{ minWidth: 0, display: { xs: 'none', lg: 'block' } }}>
              <Typography variant="subtitle2" noWrap>{membership.cooperative.name}</Typography>
              <Typography variant="caption" color="text.secondary" noWrap component="div">{t('shell.branch', { name: membership.branch.name })}</Typography>
            </Box>
            <Box sx={{ flexGrow: 1, display: 'flex', justifyContent: { xs: 'flex-start', lg: 'center' }, order: { xs: 3, sm: 0 }, width: { xs: '100%', sm: 'auto' } }}><GlobalSearch /></Box>
            <BusinessDayChip />
            <UserMenu onShortcuts={() => setShortcutsOpen(true)} />
          </Toolbar>
        </AppBar>
        {membership.cooperative.status === 'licence_suspended' && <Alert severity="warning" square sx={{ borderRadius: 0 }}>{t('shell.licenceSuspended')}</Alert>}
        {readOnly && <Alert severity="info" icon={<VisibilityOutlined />} sx={{ borderRadius: 0 }} className="no-print">{t('shell.auditorMode')}</Alert>}
        <Box component="main" id="main" tabIndex={-1} sx={{ flexGrow: 1, p: { xs: 2, sm: 3, lg: 4 }, maxWidth: 1440, width: '100%', mx: 'auto', outline: 'none' }}>
          {children}
        </Box>
      </Box>
      <Shortcuts open={shortcutsOpen} onOpen={() => setShortcutsOpen(true)} onClose={() => setShortcutsOpen(false)} />
      <IdleTimeout />
    </Box>
  );
}
