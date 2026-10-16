import Chip from '@mui/material/Chip';

const colors = { applied: 'warning', active: 'success', rejected: 'default', exited: 'default' } as const;
const riskColors = { low: 'success', medium: 'warning', high: 'error' } as const;

export function MemberStatusChip({ status }: { status: keyof typeof colors }) {
  return <Chip size="small" color={colors[status]} label={status} />;
}

export function RiskChip({ level }: { level: keyof typeof riskColors | null }) {
  return level ? <Chip size="small" variant="outlined" color={riskColors[level]} label={`${level} risk`} /> : <Chip size="small" variant="outlined" label="risk not assessed" />;
}
