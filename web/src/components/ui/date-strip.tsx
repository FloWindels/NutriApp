"use client";

import { cn } from "@/lib/cn";
import { addDays, capitalize, formatDate, formatRelativeDay, todayIso } from "@/lib/format";

export type DateStripProps = {
  /** ISO date `YYYY-MM-DD`. */
  value: string;
  onChange: (date: string) => void;
  /** Disallow navigating after today (default true for journals). */
  maxToday?: boolean;
  className?: string;
};

/** ‹ Aujourd’hui › strip with a native date picker in the middle. */
export function DateStrip({ value, onChange, maxToday = true, className }: DateStripProps) {
  const today = todayIso();
  const isToday = value === today;
  const nextDisabled = maxToday && value >= today;

  return (
    <div
      className={cn(
        "flex items-center justify-between gap-2 rounded-2xl border border-slate-200 bg-white p-1.5",
        className,
      )}
    >
      <button
        type="button"
        onClick={() => onChange(addDays(value, -1))}
        aria-label="Jour précédent"
        className="grid h-10 w-10 place-items-center rounded-xl text-slate-600 transition hover:bg-slate-100"
      >
        <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
          <path d="M12.5 4L6.5 10L12.5 16" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>

      <label className="relative flex min-w-0 flex-1 cursor-pointer flex-col items-center px-2 text-center">
        <span className="truncate text-sm font-semibold text-slate-900">{capitalize(formatRelativeDay(value))}</span>
        <span className="text-xs text-slate-500">{formatDate(value)}</span>
        <input
          type="date"
          value={value}
          max={maxToday ? today : undefined}
          onChange={(event) => event.target.value && onChange(event.target.value)}
          aria-label="Choisir une date"
          className="absolute inset-0 cursor-pointer opacity-0"
        />
      </label>

      {!isToday ? (
        <button
          type="button"
          onClick={() => onChange(today)}
          className="hidden h-10 shrink-0 rounded-xl border border-slate-200 px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 sm:block"
        >
          Aujourd’hui
        </button>
      ) : null}

      <button
        type="button"
        onClick={() => onChange(addDays(value, 1))}
        disabled={nextDisabled}
        aria-label="Jour suivant"
        className="grid h-10 w-10 place-items-center rounded-xl text-slate-600 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
      >
        <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
          <path d="M7.5 4L13.5 10L7.5 16" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>
    </div>
  );
}

export default DateStrip;
