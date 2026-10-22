import type { ReactNode } from 'react';
import StaffFrame from './StaffFrame';

/** Every cooperative staff page: session, permissions and the application shell. */
export default function StaffLayout({ children }: { children: ReactNode }) {
  return <StaffFrame>{children}</StaffFrame>;
}
