'use client';

import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import TextField from '@mui/material/TextField';
import { useRouter } from 'next/navigation';
import { type FormEvent, type ReactNode, useId, useState } from 'react';
import { useT } from '@/i18n';
import { redirectForAuthError } from '@/lib/api';
import { FormError } from './States';

/**
 * Confirmation for an irreversible action. With `reasonLabel` the user must write a reason
 * (reversals, rejections); the reason is passed to onConfirm. Errors stay inside the dialog.
 */
export default function ConfirmDialog({
  open, title, children, confirmLabel, danger = false, reasonLabel, reasonMin = 5, onConfirm, onClose,
}: {
  open: boolean;
  title: string;
  children?: ReactNode;
  confirmLabel: string;
  danger?: boolean;
  reasonLabel?: string;
  reasonMin?: number;
  onConfirm: (reason: string) => Promise<unknown> | unknown;
  onClose: () => void;
}) {
  const t = useT();
  const router = useRouter();
  const id = useId();
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>();
  const reasonOk = !reasonLabel || reason.trim().length >= reasonMin;

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!reasonOk || busy) return;
    setBusy(true);
    setError(undefined);
    try {
      await onConfirm(reason.trim());
      setReason('');
      onClose();
    } catch (e) {
      if (!redirectForAuthError(e, router.replace)) setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} aria-labelledby={`${id}-title`} fullWidth maxWidth="sm">
      <form method="post" onSubmit={submit} noValidate>
        <DialogTitle id={`${id}-title`}>{title}</DialogTitle>
        <DialogContent>
          <FormError error={error} />
          {children && <DialogContentText component="div" sx={{ mb: reasonLabel ? 2 : 0 }}>{children}</DialogContentText>}
          {reasonLabel && (
            <TextField
              autoFocus
              fullWidth
              required
              multiline
              minRows={2}
              label={reasonLabel}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              helperText={t('common.reasonRequired', { min: reasonMin })}
              slotProps={{ htmlInput: { maxLength: 300 } }}
            />
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose} disabled={busy}>{t('common.cancel')}</Button>
          <Button type="submit" variant="contained" color={danger ? 'error' : 'primary'} disabled={!reasonOk || busy} autoFocus={!reasonLabel}>
            {confirmLabel}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
}
