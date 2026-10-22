'use client';

import Box from '@mui/material/Box';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';
import { useT } from '@/i18n';

/** Frame for the signed-out screens: brand on ledger navy, the form on paper. */
export default function AuthCard({ title, subtitle, children, wide = false }: { title: string; subtitle?: ReactNode; children: ReactNode; wide?: boolean }) {
  const t = useT();
  return (
    <Box component="main" sx={{ minHeight: '100vh', display: 'grid', gridTemplateColumns: { xs: '1fr', md: 'minmax(320px, 5fr) 7fr' }, bgcolor: 'background.default' }}>
      <Box sx={{ bgcolor: 'sidebar.bg', color: 'sidebar.fg', p: { xs: 3, md: 6 }, display: 'flex', flexDirection: 'column', justifyContent: { md: 'space-between' }, gap: 2 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
          <Box aria-hidden sx={{ width: 36, height: 36, borderRadius: '10px 3px 10px 3px', bgcolor: 'secondary.main', color: 'secondary.contrastText', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 19 }}>स</Box>
          <Typography sx={{ fontWeight: 700, fontSize: '1.25rem' }}>Sahakari<Box component="span" sx={{ color: 'secondary.main' }}>OS</Box></Typography>
        </Box>
        <Typography sx={{ display: { xs: 'none', md: 'block' }, fontSize: '1.75rem', fontWeight: 650, lineHeight: 1.25, maxWidth: 420, letterSpacing: '-0.01em' }}>
          {t('app.tagline')}
        </Typography>
        <Typography variant="caption" sx={{ display: { xs: 'none', md: 'block' }, color: 'sidebar.muted' }}>{t('auth.footer')}</Typography>
      </Box>
      <Box sx={{ display: 'grid', placeItems: 'center', p: { xs: 2, sm: 4 } }}>
        <Paper sx={{ p: { xs: 3, sm: 4 }, width: '100%', maxWidth: wide ? 560 : 420 }}>
          <Typography variant="h1" gutterBottom>{title}</Typography>
          {subtitle && <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{subtitle}</Typography>}
          {children}
        </Paper>
      </Box>
    </Box>
  );
}
