import { Suspense } from 'react';
import JournalEntryView from './JournalEntryView';

// The entry id comes from the URL at request time, so the client part renders inside Suspense.
export default function JournalEntryPage() {
  return (
    <Suspense>
      <JournalEntryView />
    </Suspense>
  );
}
