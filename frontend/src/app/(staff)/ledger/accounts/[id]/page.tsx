import { Suspense } from 'react';
import AccountView from './AccountView';

export default function AccountPage() {
  return (
    <Suspense>
      <AccountView />
    </Suspense>
  );
}
