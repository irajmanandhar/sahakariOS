'use client';

import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import PageHeader from '@/components/PageHeader';
import { GapNotice } from '@/components/States';
import { useT } from '@/i18n';
import { useSession } from '@/lib/session';

/**
 * Roles and users. The API has no role administration yet (gap G9 in docs/frontend-gaps.md), so
 * this page shows only what the signed-in user holds in this cooperative.
 */
export default function RolesPage() {
  const t = useT();
  const { user, membership } = useSession();
  const permissions = [...(membership.permissions ?? [])].sort();
  return (
    <>
      <PageHeader title={t('nav.roles')} crumbs={[{ label: t('nav.settings') }]} subtitle={t('roles.help')} />
      <GapNotice gap="G9">{t('roles.gap')}</GapNotice>
      <Paper sx={{ p: { xs: 2, sm: 3 } }}>
        <Typography variant="h2" sx={{ mb: 1 }}>{t('roles.yours', { name: user.name })}</Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('roles.yoursHelp')}</Typography>
        <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap' }}>
          {permissions.length === 0 ? <Typography>{t('common.none')}</Typography> : permissions.map((p) => <Chip key={p} variant="outlined" label={p} />)}
        </Box>
      </Paper>
    </>
  );
}
