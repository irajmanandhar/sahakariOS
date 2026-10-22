import { Suspense } from 'react';
import LoanView from './LoanView';

export default function LoanPage() {
  return (
    <Suspense>
      <LoanView />
    </Suspense>
  );
}
