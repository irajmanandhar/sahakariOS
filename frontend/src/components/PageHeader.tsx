'use client';

import Box from '@mui/material/Box';
import Breadcrumbs from '@mui/material/Breadcrumbs';
import Link from '@mui/material/Link';
import Typography from '@mui/material/Typography';
import NextLink from 'next/link';
import type { ReactNode } from 'react';
import { useT } from '@/i18n';

export type Crumb = { label: string; href?: string };

/** Page title (the page's only h1), breadcrumbs, a one-line explanation and the page actions. */
export default function PageHeader({ title, crumbs = [], subtitle, actions, meta }: { title: ReactNode; crumbs?: Crumb[]; subtitle?: ReactNode; actions?: ReactNode; meta?: ReactNode }) {
  const t = useT();
  return (
    <Box component="header" sx={{ mb: 3 }}>
      {crumbs.length > 0 && (
        <Breadcrumbs aria-label={t('nav.breadcrumbs')} sx={{ mb: 0.5, fontSize: '0.8125rem' }} className="no-print">
          {crumbs.map((c) => (c.href
            ? <Link key={c.label} component={NextLink} href={c.href} underline="hover" color="text.secondary">{c.label}</Link>
            : <Typography key={c.label} color="text.secondary" sx={{ fontSize: 'inherit' }}>{c.label}</Typography>))}
        </Breadcrumbs>
      )}
      <Box sx={{ display: 'flex', gap: 2, alignItems: { xs: 'flex-start', sm: 'center' }, flexDirection: { xs: 'column', sm: 'row' } }}>
        <Box sx={{ flexGrow: 1, minWidth: 0 }}>
          <Typography variant="h1" sx={{ display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap' }}>{title}</Typography>
          {subtitle && <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, maxWidth: 760 }}>{subtitle}</Typography>}
          {meta && <Box sx={{ mt: 1, display: 'flex', gap: 1, flexWrap: 'wrap', alignItems: 'center' }}>{meta}</Box>}
        </Box>
        {actions && <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap' }} className="no-print">{actions}</Box>}
      </Box>
    </Box>
  );
}
