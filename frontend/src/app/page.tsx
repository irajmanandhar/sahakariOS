import Container from '@mui/material/Container';
import Typography from '@mui/material/Typography';
import { connection } from 'next/server';
import { Suspense } from 'react';
import HealthStatus from '@/components/HealthStatus';
import { fetchHealth } from '@/lib/health';

export default function Home() {
  return (
    <Container maxWidth="sm" sx={{ py: 6 }}>
      <Typography variant="h4" component="h1" gutterBottom>
        SahakariOS
      </Typography>
      {/* Static shell renders at build time; the live check streams in per request. */}
      <Suspense fallback={<Typography>Checking API…</Typography>}>
        <LiveHealth />
      </Suspense>
    </Container>
  );
}

async function LiveHealth() {
  await connection();
  return <HealthStatus health={await fetchHealth()} />;
}
