'use client';

import Alert from '@mui/material/Alert';
import Snackbar from '@mui/material/Snackbar';
import { createContext, type ReactNode, useCallback, useContext, useMemo, useState } from 'react';

type Toast = { id: number; message: string; severity: 'success' | 'error' | 'info' | 'warning' };
type Api = { success: (message: string) => void; error: (message: string) => void; info: (message: string) => void };

const ToastContext = createContext<Api>({ success: () => {}, error: () => {}, info: () => {} });

export function useToast(): Api {
  return useContext(ToastContext);
}

/** One toast at a time, announced politely (role=status) except errors (role=alert). */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [queue, setQueue] = useState<Toast[]>([]);
  const push = useCallback((severity: Toast['severity']) => (message: string) => setQueue((q) => [...q, { id: Date.now() + Math.random(), message, severity }]), []);
  const api = useMemo(() => ({ success: push('success'), error: push('error'), info: push('info') }), [push]);
  const current = queue[0];
  const dismiss = () => setQueue((q) => q.slice(1));

  return (
    <ToastContext.Provider value={api}>
      {children}
      <Snackbar key={current?.id} open={!!current} autoHideDuration={current?.severity === 'error' ? 8000 : 4000} onClose={(_, reason) => reason !== 'clickaway' && dismiss()} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}>
        {current ? (
          <Alert severity={current.severity} variant="filled" onClose={dismiss} role={current.severity === 'error' ? 'alert' : 'status'} sx={{ minWidth: 280 }}>
            {current.message}
          </Alert>
        ) : undefined}
      </Snackbar>
    </ToastContext.Provider>
  );
}
