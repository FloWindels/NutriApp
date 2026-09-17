import type { ReactNode } from "react";
import { cn } from "@/lib/cn";
import { toneStyles, type DashboardTone } from "@/lib/dashboard-sections";
import { Pill } from "./pill";

export type StatCardProps = {
  label: ReactNode;
  value: ReactNode;
  /** Small text under the value (« objectif 2 500 »). */
  caption?: ReactNode;
  icon?: ReactNode;
  tone?: DashboardTone;
  /** Shows the amber « estimation » pill. */
  estimate?: boolean;
  className?: string;
};

export function StatCard({ label, value, caption, icon, tone = "slate", estimate = false, className }: StatCardProps) {
  return (
    <article
      className={cn(
        "flex min-w-0 flex-col justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4",
        className,
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <p className="text-xs font-medium uppercase tracking-[0.14em] text-slate-500">{label}</p>
        {icon ? (
          <span className={cn("grid h-8 w-8 shrink-0 place-items-center rounded-xl", toneStyles[tone].soft)}>
            {icon}
          </span>
        ) : null}
      </div>
      <div className="min-w-0">
        <p className="truncate text-2xl font-semibold tracking-tight text-slate-950">{value}</p>
        {caption || estimate ? (
          <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
            {caption ? <span>{caption}</span> : null}
            {estimate ? <Pill tone="amber">estimation</Pill> : null}
          </div>
        ) : null}
      </div>
    </article>
  );
}

export default StatCard;
