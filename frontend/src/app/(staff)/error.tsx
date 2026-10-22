'use client';

import Alert from '@mui/material/Alert';
import AlertTitle from '@mui/material/AlertTitle';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import NextLink from 'next/link';
import { useEffect } from 'react';
import { useT } from '@/i18n';

/** Error boundary for staff pages: the shell stays usable, the page offers a retry. */
export default function StaffError({ error, retry }: { error: unknown; retry: () => void }) {
  const t = useT();
  useEffect(() => {
    console.error(error); // ponytail: send to error tracking once one is chosen (production checklist)
  }, [error]);

  return (
    <Box sx={{ maxWidth: 640 }}>
      <Alert severity="error">
        <AlertTitle>{t('errors.unexpected')}</AlertTitle>
        {t('errors.unexpectedBody')}
        <Box sx={{ mt: 2, display: 'flex', gap: 1 }}>
          <Button variant="contained" color="error" onClick={() => retry()}>{t('common.retry')}</Button>
          <Button component={NextLink} href="/">{t('nav.dashboard')}</Button>
        </Box>
      </Alert>
    </Box>
  );
}
