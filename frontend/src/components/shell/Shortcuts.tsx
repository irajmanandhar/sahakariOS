'use client';

import Box from '@mui/material/Box';
import Dialog from '@mui/material/Dialog';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Typography from '@mui/material/Typography';
import { useRouter } from 'next/navigation';
import { useEffect, useRef } from 'react';
import { type MessageKey, useT } from '@/i18n';
import { SEARCH_INPUT_ID } from './GlobalSearch';

const GO: Record<string, [string, MessageKey]> = {
  d: ['/', 'shortcuts.goDashboard'],
  m: ['/members', 'shortcuts.goMembers'],
  l: ['/loans', 'shortcuts.goLoans'],
  t: ['/ledger', 'shortcuts.goLedger'],
  r: ['/reports', 'shortcuts.goReports'],
  a: ['/audit', 'shortcuts.goAudit'],
};

function typing(target: EventTarget | null): boolean {
  const el = target as HTMLElement | null;
  return !!el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
}

const Key = ({ children }: { children: string }) => (
  <Box component="kbd" sx={{ px: 0.75, py: 0.25, border: 1, borderColor: 'divider', borderRadius: 1, fontFamily: 'inherit', fontSize: '0.8125rem', bgcolor: 'action.hover' }}>{children}</Box>
);

/** Ctrl/⌘+K or / : member search · ? : this list · g then d/m/l/t/r/a : go to a section. */
export default function Shortcuts({ open, onOpen, onClose }: { open: boolean; onOpen: () => void; onClose: () => void }) {
  const t = useT();
  const router = useRouter();
  const pendingG = useRef(0);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        document.getElementById(SEARCH_INPUT_ID)?.focus();
        return;
      }
      if (e.ctrlKey || e.metaKey || e.altKey || typing(e.target)) return;
      if (e.key === '/') {
        e.preventDefault();
        document.getElementById(SEARCH_INPUT_ID)?.focus();
      } else if (e.key === '?') {
        onOpen();
      } else if (e.key === 'g') {
        pendingG.current = Date.now();
      } else if (Date.now() - pendingG.current < 1200 && GO[e.key]) {
        pendingG.current = 0;
        router.push(GO[e.key][0]);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [router, onOpen]);

  const rows: [string[], string][] = [
    [['Ctrl', 'K'], t('shortcuts.search')],
    [['/'], t('shortcuts.search')],
    [['?'], t('shortcuts.help')],
    ...Object.entries(GO).map(([k, [, label]]): [string[], string] => [['g', k], t(label)]),
  ];

  return (
    <Dialog open={open} onClose={onClose} aria-labelledby="shortcuts-title">
      <DialogTitle id="shortcuts-title">{t('shell.shortcuts')}</DialogTitle>
      <DialogContent>
        <Box component="dl" sx={{ display: 'grid', gridTemplateColumns: 'auto 1fr', gap: 1.25, m: 0 }}>
          {rows.map(([keys, label]) => (
            <Box key={keys.join('+') + label} sx={{ display: 'contents' }}>
              <Box component="dt" sx={{ display: 'flex', gap: 0.5, alignItems: 'center' }}>
                {keys.map((k, i) => <Box key={k} component="span" sx={{ display: 'contents' }}>{i > 0 && <Typography variant="caption" color="text.secondary">{keys[0] === 'g' ? t('shortcuts.then') : '+'}</Typography>}<Key>{k}</Key></Box>)}
              </Box>
              <Typography component="dd" variant="body2" sx={{ m: 0 }}>{label}</Typography>
            </Box>
          ))}
        </Box>
      </DialogContent>
    </Dialog>
  );
}
