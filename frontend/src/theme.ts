'use client';

import { createTheme } from '@mui/material/styles';

// Single app-wide MUI theme. Client module because themes may contain functions.
const theme = createTheme({
  cssVariables: true,
});

export default theme;
