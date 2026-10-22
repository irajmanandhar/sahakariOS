import { Suspense } from 'react';
import LoanApplication from './LoanApplication';

export default function NewLoanPage() {
  return (
    <Suspense>
      <LoanApplication />
    </Suspense>
  );
}
