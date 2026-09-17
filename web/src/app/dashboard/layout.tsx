import type { ReactNode } from "react";
import DashboardShell from "@/components/dashboard-shell";

/** Mounts the sidebar shell once for every `/dashboard/**` page. */
export default function DashboardLayout({ children }: { children: ReactNode }) {
  return <DashboardShell>{children}</DashboardShell>;
}
