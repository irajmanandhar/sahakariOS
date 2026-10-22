import { Suspense } from 'react';
import ReportView from './ReportView';

export default function ReportPage() {
  return (
    <Suspense>
      <ReportView />
    </Suspense>
  );
}
