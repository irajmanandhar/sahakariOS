'use client';

import { createTheme } from '@mui/material/styles';

/**
 * Design tokens. Ledger-ink navy for structure, cool paper for the work surface,
 * marigold (sayapatri) reserved for "live" state: the open business day and the active page.
 * Status colours always come with an icon or text (StatusChip), never colour alone.
 */

declare module '@mui/material/styles' {
  interface Palette {
    sidebar: { bg: string; fg: string; muted: string; hover: string; active: string };
  }
  interface PaletteOptions {
    sidebar?: { bg: string; fg: string; muted: string; hover: string; active: string };
  }
}

export const FONT_STACK = 'var(--font-inter), var(--font-devanagari), system-ui, -apple-system, "Segoe UI", sans-serif';

const theme = createTheme({
  cssVariables: { colorSchemeSelector: 'data' },
  colorSchemes: {
    light: {
      palette: {
        primary: { main: '#1D4E89', dark: '#143A68', light: '#3D6FAD', contrastText: '#FFFFFF' },
        secondary: { main: '#E3A21A', dark: '#B07A0B', light: '#F2C45E', contrastText: '#241800' },
        success: { main: '#2E7D32' },
        warning: { main: '#955300' }, // ≥ 4.5:1 as text on the page background
        error: { main: '#C62828' },
        info: { main: '#1D6FB8' },
        background: { default: '#F3F5F9', paper: '#FFFFFF' },
        text: { primary: '#15213B', secondary: '#4B5875' },
        divider: '#DCE1EA',
        sidebar: { bg: '#14213D', fg: '#E8EDF6', muted: '#9AA8C3', hover: 'rgba(255,255,255,0.06)', active: 'rgba(227,162,26,0.16)' },
      },
    },
    dark: {
      palette: {
        primary: { main: '#8DB8F2', contrastText: '#0B1424' },
        secondary: { main: '#F0B73A', contrastText: '#241800' },
        success: { main: '#66BB6A' },
        warning: { main: '#FFB74D' },
        error: { main: '#EF6B6B' },
        info: { main: '#64B5F6' },
        background: { default: '#0E1626', paper: '#152036' },
        text: { primary: '#E6EBF4', secondary: '#A7B2C7' },
        divider: '#27334B',
        sidebar: { bg: '#0A111E', fg: '#E6EBF4', muted: '#8391AB', hover: 'rgba(255,255,255,0.05)', active: 'rgba(240,183,58,0.14)' },
      },
    },
  },
  shape: { borderRadius: 6 },
  typography: {
    fontFamily: FONT_STACK,
    fontSize: 14,
    h1: { fontSize: '1.625rem', fontWeight: 650, lineHeight: 1.25, letterSpacing: '-0.01em' },
    h2: { fontSize: '1.25rem', fontWeight: 650, lineHeight: 1.3 },
    h3: { fontSize: '1.0625rem', fontWeight: 650, lineHeight: 1.35 },
    h4: { fontSize: '0.9375rem', fontWeight: 650, lineHeight: 1.4 },
    h5: { fontSize: '0.9375rem', fontWeight: 600 },
    h6: { fontSize: '0.875rem', fontWeight: 600 },
    subtitle1: { fontSize: '0.9375rem', fontWeight: 500 },
    subtitle2: { fontSize: '0.8125rem', fontWeight: 600 },
    body1: { fontSize: '0.9375rem', lineHeight: 1.55 },
    body2: { fontSize: '0.875rem', lineHeight: 1.5 },
    caption: { fontSize: '0.75rem', lineHeight: 1.45 },
    button: { textTransform: 'none', fontWeight: 600 },
  },
  components: {
    MuiCssBaseline: {
      styleOverrides: {
        ':focus-visible': { outline: '2px solid var(--mui-palette-secondary-main)', outlineOffset: 2 },
        '@media print': {
          '.no-print': { display: 'none !important' },
          '.print-only': { display: 'block !important' },
          body: { background: '#fff' },
        },
        '.print-only': { display: 'none' },
        '@media (prefers-reduced-motion: reduce)': {
          '*, *::before, *::after': { animationDuration: '0.01ms !important', transitionDuration: '0.01ms !important' },
        },
      },
    },
    MuiButton: { defaultProps: { disableElevation: true } },
    MuiPaper: {
      defaultProps: { elevation: 0 },
      styleOverrides: { root: ({ theme }) => ({ border: `1px solid ${theme.vars.palette.divider}`, backgroundImage: 'none' }) },
    },
    MuiAppBar: { styleOverrides: { root: { border: 0 } } },
    MuiDrawer: { styleOverrides: { paper: { border: 0 } } },
    MuiMenu: { styleOverrides: { paper: { border: 0 } } },
    MuiPopover: { defaultProps: { slotProps: { paper: { elevation: 4 } } } },
    MuiDialog: { styleOverrides: { paper: { border: 0 } } },
    MuiTextField: { defaultProps: { size: 'small' } },
    // Default unselected colour (action.active) is under 4.5:1 on the page background.
    MuiToggleButton: { styleOverrides: { root: ({ theme }) => ({ color: theme.vars.palette.text.secondary }) } },
    MuiChip: { defaultProps: { size: 'small' }, styleOverrides: { root: { fontWeight: 600 } } },
    MuiTableCell: {
      styleOverrides: {
        root: { paddingTop: 7, paddingBottom: 7 },
        head: ({ theme }) => ({ fontWeight: 600, color: theme.vars.palette.text.secondary, backgroundColor: theme.vars.palette.background.paper, whiteSpace: 'nowrap' }),
      },
    },
    // describeChild: the tooltip describes its trigger instead of renaming it (WCAG 2.5.3 label in name).
    MuiTooltip: { defaultProps: { arrow: true, describeChild: true } },
  },
});

export default theme;
