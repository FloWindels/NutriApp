"use client";

import { useId, type ReactNode } from "react";
import { cn } from "@/lib/cn";

export type SwitchProps = {
  checked: boolean;
  onChange: (checked: boolean) => void;
  /** Accessible name when the switch is used without a visible label. */
  label: string;
  disabled?: boolean;
  id?: string;
  /** Element id describing the switch (`aria-describedby`). */
  describedBy?: string;
  className?: string;
};

/**
 * Accessible toggle (`role="switch"`) used by the settings, the planner and the
 * family pages. 44 px wide, 40 px tall hit area, keyboard reachable.
 */
export function Switch({
  checked,
  onChange,
  label,
  disabled = false,
  id,
  describedBy,
  className,
}: SwitchProps) {
  return (
    <button
      type="button"
      id={id}
      role="switch"
      aria-checked={checked}
      aria-label={label}
      aria-describedby={describedBy}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={cn(
        "relative inline-flex h-10 w-[60px] shrink-0 items-center rounded-full border px-1 transition focus-visible:outline-none focus-visible:ring-4 disabled:cursor-not-allowed disabled:opacity-50",
        checked
          ? "border-emerald-700 bg-emerald-700 focus-visible:ring-emerald-600/30"
          : "border-slate-200 bg-slate-200 focus-visible:ring-slate-400/30",
        className,
      )}
    >
      <span
        aria-hidden="true"
        className={cn(
          "h-7 w-7 rounded-full bg-white shadow-sm transition-transform duration-200",
          checked ? "translate-x-[20px]" : "translate-x-0",
        )}
      />
    </button>
  );
}

export type SwitchRowProps = {
  title: ReactNode;
  description?: ReactNode;
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
  className?: string;
};

/** Labelled row: title + helper text on the left, the switch on the right. */
export function SwitchRow({
  title,
  description,
  checked,
  onChange,
  disabled = false,
  className,
}: SwitchRowProps) {
  const labelId = useId();
  const descriptionId = useId();
  const plainLabel = typeof title === "string" ? title : "Activer";

  return (
    <div className={cn("flex items-start justify-between gap-4 py-3", className)}>
      <div className="min-w-0">
        <p id={labelId} className="text-sm font-medium text-slate-900">
          {title}
        </p>
        {description ? (
          <p id={descriptionId} className="mt-0.5 text-xs leading-5 text-slate-500">
            {description}
          </p>
        ) : null}
      </div>
      <Switch
        checked={checked}
        onChange={onChange}
        label={plainLabel}
        disabled={disabled}
        describedBy={description ? descriptionId : undefined}
      />
    </div>
  );
}

export default Switch;
