import StaffFrame from './StaffFrame';

/** Every cooperative staff page: session, permissions and the application shell. */
export default function StaffLayout({ children }: LayoutProps<'/'>) {
  return <StaffFrame>{children}</StaffFrame>;
}
