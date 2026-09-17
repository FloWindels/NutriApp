"use client";

import { useMemo } from "react";
import { cn } from "@/lib/cn";
import { capitalize, formatLongDate, formatMonthYear, parseIsoDate, toIsoDate, todayIso } from "@/lib/format";
import { WEEKDAY_LABELS, WEEKDAY_SHORT } from "@/lib/vocab";

export type CalendarMarkerTone = "planned" | "done" | "cancelled" | "info" | "warning";

export type MonthCalendarProps = {
  /** Any ISO date inside the displayed month. */
  month: string;
  onMonthChange: (firstOfMonth: string) => void;
  selected?: string | null;
  onSelect?: (date: string) => void;
  /** Dot markers per ISO date (max 3 shown). */
  markers?: Record<string, CalendarMarkerTone[]>;
  /** Disable days after today (history views). */
  maxToday?: boolean;
  className?: string;
};

const markerClasses: Record<CalendarMarkerTone, string> = {
  planned: "bg-lime-500",
  done: "bg-emerald-700",
  cancelled: "bg-slate-300",
  info: "bg-sky-500",
  warning: "bg-amber-500",
};

function firstOfMonth(iso: string): Date {
  const d = parseIsoDate(iso) ?? new Date();
  return new Date(d.getFullYear(), d.getMonth(), 1);
}

function shiftMonth(iso: string, delta: number): string {
  const first = firstOfMonth(iso);
  return toIsoDate(new Date(first.getFullYear(), first.getMonth() + delta, 1));
}

/** Monday-first month grid (7 columns), French weekday initials, dot markers. */
export function MonthCalendar({
  month,
  onMonthChange,
  selected,
  onSelect,
  markers = {},
  maxToday = false,
  className,
}: MonthCalendarProps) {
  const today = todayIso();

  const cells = useMemo(() => {
    const first = firstOfMonth(month);
    const offset = (first.getDay() + 6) % 7; // Monday = 0
    const start = new Date(first);
    start.setDate(first.getDate() - offset);
    const days: { iso: string; inMonth: boolean }[] = [];
    for (let i = 0; i < 42; i += 1) {
      const d = new Date(start);
      d.setDate(start.getDate() + i);
      days.push({ iso: toIsoDate(d), inMonth: d.getMonth() === first.getMonth() });
    }
    // Drop a trailing empty week when the month fits in 5 rows.
    if (days.slice(35).every((cell) => !cell.inMonth)) days.length = 35;
    return days;
  }, [month]);

  return (
    <div className={cn("rounded-2xl border border-slate-200 bg-white p-3", className)}>
      <div className="mb-2 flex items-center justify-between gap-2">
        <button
          type="button"
          onClick={() => onMonthChange(shiftMonth(month, -1))}
          aria-label="Mois précédent"
          className="grid h-9 w-9 place-items-center rounded-xl text-slate-600 transition hover:bg-slate-100"
        >
          <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
            <path d="M12.5 4L6.5 10L12.5 16" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </button>
        <p className="text-sm font-semibold text-slate-900">{capitalize(formatMonthYear(month))}</p>
        <button
          type="button"
          onClick={() => onMonthChange(shiftMonth(month, 1))}
          aria-label="Mois suivant"
          className="grid h-9 w-9 place-items-center rounded-xl text-slate-600 transition hover:bg-slate-100"
        >
          <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
            <path d="M7.5 4L13.5 10L7.5 16" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </button>
      </div>

      <div role="grid" aria-label={capitalize(formatMonthYear(month))}>
        <div role="row" className="grid grid-cols-7 gap-1">
          {WEEKDAY_SHORT.map((initial, index) => (
            <div
              key={WEEKDAY_LABELS[index]}
              role="columnheader"
              aria-label={WEEKDAY_LABELS[index]}
              className="py-1 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-400"
            >
              {initial}
            </div>
          ))}
        </div>
        <div role="row" className="grid grid-cols-7 gap-1">
          {cells.map((cell) => {
            const dayMarkers = (markers[cell.iso] ?? []).slice(0, 3);
            const isSelected = selected === cell.iso;
            const isToday = cell.iso === today;
            const disabled = maxToday && cell.iso > today;
            const dayNumber = Number(cell.iso.slice(8, 10));
            return (
              <button
                key={cell.iso}
                type="button"
                role="gridcell"
                aria-selected={isSelected || undefined}
                aria-current={isToday ? "date" : undefined}
                aria-label={capitalize(formatLongDate(cell.iso))}
                disabled={disabled || !onSelect}
                onClick={() => onSelect?.(cell.iso)}
                className={cn(
                  "flex aspect-square flex-col items-center justify-center gap-1 rounded-xl text-sm transition",
                  cell.inMonth ? "text-slate-900" : "text-slate-300",
                  onSelect && !disabled && "hover:bg-slate-100",
                  isSelected && "bg-emerald-700 text-white hover:bg-emerald-700",
                  isToday && !isSelected && "border border-emerald-600 font-semibold",
                  disabled && "cursor-not-allowed opacity-40",
                )}
              >
                <span className="leading-none tabular-nums">{dayNumber}</span>
                <span className="flex h-1.5 items-center gap-0.5">
                  {dayMarkers.map((tone, index) => (
                    <span
                      key={`${tone}-${index}`}
                      aria-hidden="true"
                      className={cn(
                        "h-1.5 w-1.5 rounded-full",
                        isSelected ? "bg-white/90" : markerClasses[tone],
                      )}
                    />
                  ))}
                </span>
              </button>
            );
          })}
        </div>
      </div>
    </div>
  );
}

export default MonthCalendar;
