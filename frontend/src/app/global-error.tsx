'use client';

import Link from 'next/link';
import { useEffect } from 'react';
import { useT } from '@/i18n';

/**
 * Last-resort boundary for errors in the root layout. It replaces the whole document, so it uses
 * plain HTML (no theme or providers) and follows the OS colour scheme.
 */
export default function GlobalError({ error, retry }: { error: unknown; retry: () => void }) {
  const t = useT();
  useEffect(() => {
    console.error(error); // ponytail: send to error tracking once one is chosen (docs/frontend.md checklist)
  }, [error]);

  return (
    <html lang="en">
      <body style={{ margin: 0, minHeight: '100vh', display: 'grid', placeItems: 'center', fontFamily: 'system-ui, sans-serif', colorScheme: 'light dark' }}>
        <title>SahakariOS</title>
        <main style={{ maxWidth: 480, padding: 24 }}>
          <h1 style={{ fontSize: '1.5rem', margin: '0 0 8px' }}>{t('errors.unexpected')}</h1>
          <p style={{ margin: '0 0 16px', lineHeight: 1.5 }}>{t('errors.unexpectedBody')}</p>
          <button type="button" onClick={() => retry()} style={{ padding: '8px 16px', marginRight: 8, font: 'inherit', cursor: 'pointer' }}>{t('common.retry')}</button>
          <Link href="/">{t('nav.dashboard')}</Link>
        </main>
      </body>
    </html>
  );
}
