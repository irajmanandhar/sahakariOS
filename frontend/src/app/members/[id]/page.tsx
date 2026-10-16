import Container from '@mui/material/Container';
import Typography from '@mui/material/Typography';
import { Suspense } from 'react';
import MemberDetail from './MemberDetail';

// The member id comes from the URL at request time, so the client part renders inside Suspense.
export default function MemberPage() {
  return (
    <Suspense fallback={<Container sx={{ py: 4 }}><Typography>Loading…</Typography></Container>}>
      <MemberDetail />
    </Suspense>
  );
}
