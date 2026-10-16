import Alert from '@mui/material/Alert';
import List from '@mui/material/List';
import ListItem from '@mui/material/ListItem';
import ListItemText from '@mui/material/ListItemText';
import type { HealthResult } from '@/lib/health';

const messages: Record<HealthResult['status'], string> = {
  ok: 'API is healthy',
  fail: 'API is running but a dependency is failing',
  unreachable: 'API is unreachable',
};

export default function HealthStatus({ health }: { health: HealthResult }) {
  return (
    <>
      <Alert severity={health.status === 'ok' ? 'success' : 'error'}>{messages[health.status]}</Alert>
      <List dense>
        {Object.entries(health.checks).map(([name, result]) => (
          <ListItem key={name}>
            <ListItemText primary={name} secondary={result} />
          </ListItem>
        ))}
      </List>
    </>
  );
}
