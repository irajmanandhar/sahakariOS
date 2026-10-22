import { Suspense } from 'react';
import AccountView from './SavingsAccountView';

export default function SavingsAccountPage() {
  return (
    <Suspense>
      <AccountView />
    </Suspense>
  );
}
