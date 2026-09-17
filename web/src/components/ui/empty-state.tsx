import type { ReactNode } from "react";
import { cn } from "@/lib/cn";

export type EmptyStateProps = {
  icon?: ReactNode;
  title: ReactNode;
  message?: ReactNode;
  /** Primary call to action (Button or Link). */
  action?: ReactNode;
  compact?: boolean;
  className?: string;
};

export function EmptyState({ icon, title, message, action, compact = false, className }: EmptyStateProps) {
  return (
    <div
      className={cn(
        "flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 text-center",
        compact ? "px-4 py-6" : "px-6 py-12",
        className,
      )}
    >
      {icon ? (
        <div className="mb-3 grid h-12 w-12 place-items-center rounded-2xl bg-white text-slate-400 shadow-sm">
          {icon}
        </div>
      ) : null}
      <p className="text-base font-semibold text-slate-900">{title}</p>
      {message ? <p className="mt-1 max-w-md text-sm leading-6 text-slate-500">{message}</p> : null}
      {action ? <div className="mt-4">{action}</div> : null}
    </div>
  );
}

export default EmptyState;
