import type { ReactNode } from 'react';
import type { Metadata, Viewport } from 'next';
import { Inter, Noto_Sans_Devanagari } from 'next/font/google';
import { AppRouterCacheProvider } from '@mui/material-nextjs/v16-appRouter';
import CssBaseline from '@mui/material/CssBaseline';
import InitColorSchemeScript from '@mui/material/InitColorSchemeScript';
import { ThemeProvider } from '@mui/material/styles';
import { ToastProvider } from '@/components/Toasts';
import theme from '@/theme';

// Self-hosted at build time by next/font: the browser never contacts Google.
const inter = Inter({ subsets: ['latin'], variable: '--font-inter', display: 'swap' });
const devanagari = Noto_Sans_Devanagari({ subsets: ['devanagari'], weight: ['400', '500', '600', '700'], variable: '--font-devanagari', display: 'swap' });

export const metadata: Metadata = {
  title: { default: 'SahakariOS', template: '%s · SahakariOS' },
  description: 'Cooperative core banking platform',
  robots: { index: false, follow: false },
};

export const viewport: Viewport = { width: 'device-width', initialScale: 1 };

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en" className={`${inter.variable} ${devanagari.variable}`} suppressHydrationWarning>
      <body>
        {/* Sets the saved light/dark scheme before first paint (no flash). */}
        <InitColorSchemeScript attribute="data" />
        {/* Collects Emotion styles during server rendering so MUI renders without a flash. */}
        <AppRouterCacheProvider>
          <ThemeProvider theme={theme}>
            <CssBaseline />
            <ToastProvider>{children}</ToastProvider>
          </ThemeProvider>
        </AppRouterCacheProvider>
      </body>
    </html>
  );
}
