import type { ReactNode } from "react";
import { cn } from "@/lib/cn";

export type ProgressTone = "emerald" | "lime" | "amber" | "rose" | "sky" | "slate" | "indigo";

export type ProgressBarProps = {
  /** 0–100 (values above 100 are clamped visually and flagged). */
  value: number;
  tone?: ProgressTone;
  /** Turns rose when value > 100 (over budget). Default true. */
  autoOverflowTone?: boolean;
  size?: "sm" | "md" | "lg";
  label?: ReactNode;
  /** Text shown at the right end (e.g. « 1 260 / 2 500 kcal »). */
  caption?: ReactNode;
  className?: string;
};

const fillClasses: Record<ProgressTone, string> = {
  emerald: "bg-emerald-500",
  lime: "bg-lime-500",
  amber: "bg-amber-500",
  rose: "bg-rose-500",
  sky: "bg-sky-500",
  slate: "bg-slate-500",
  indigo: "bg-indigo-500",
};

const sizeClasses = { sm: "h-1.5", md: "h-2.5", lg: "h-3.5" } as const;

export function ProgressBar({
  value,
  tone = "emerald",
  autoOverflowTone = true,
  size = "md",
  label,
  caption,
  className,
}: ProgressBarProps) {
  const safe = Number.isFinite(value) ? value : 0;
  const clamped = Math.max(0, Math.min(100, safe));
  const over = safe > 100;
  const effectiveTone = over && autoOverflowTone ? "rose" : tone;

  return (
    <div className={cn("min-w-0", className)}>
      {label || caption ? (
        <div className="mb-1.5 flex items-center justify-between gap-3 text-xs text-slate-500">
          {label ? <span className="font-medium text-slate-700">{label}</span> : <span />}
          {caption ? <span>{caption}</span> : null}
        </div>
      ) : null}
      <div
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={Math.round(clamped)}
        className={cn("w-full overflow-hidden rounded-full bg-slate-200", sizeClasses[size])}
      >
        <div
          className={cn("h-full rounded-full transition-[width] duration-500", fillClasses[effectiveTone])}
          style={{ width: `${clamped}%` }}
        />
      </div>
    </div>
  );
}

export default ProgressBar;
