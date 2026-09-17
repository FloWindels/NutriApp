"use client";

import { useId, type ReactNode } from "react";
import { cn } from "@/lib/cn";

/** Chip, chip group and switch primitives shared by every sport form. */

export type ChipProps = {
  selected?: boolean;
  onClick?: () => void;
  children: ReactNode;
  disabled?: boolean;
  title?: string;
  /** `radio` renders aria-checked, `toggle` aria-pressed (multi-select). */
  role?: "radio" | "toggle";
  className?: string;
};

export function Chip({
  selected = false,
  onClick,
  children,
  disabled = false,
  title,
  role = "toggle",
  className,
}: ChipProps) {
  const ariaProps =
    role === "radio" ? { role: "radio" as const, "aria-checked": selected } : { "aria-pressed": selected };
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      title={title}
      {...ariaProps}
      className={cn(
        "inline-flex min-h-10 items-center justify-center gap-1.5 rounded-full border px-4 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-4 disabled:cursor-not-allowed disabled:opacity-50",
        selected
          ? "border-emerald-700 bg-emerald-700 text-white focus-visible:ring-emerald-600/30"
          : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50 focus-visible:ring-slate-400/30",
        className,
      )}
    >
      {children}
    </button>
  );
}

export type ChipGroupProps = {
  legend: ReactNode;
  hint?: ReactNode;
  error?: ReactNode;
  /** `radio` groups get `role="radiogroup"` for screen readers. */
  multiple?: boolean;
  children: ReactNode;
  className?: string;
};

export function ChipGroup({ legend, hint, error, multiple = false, children, className }: ChipGroupProps) {
  return (
    <fieldset className={cn("min-w-0", className)}>
      <legend className="mb-1.5 block text-sm font-medium text-slate-700">{legend}</legend>
      <div className="flex flex-wrap gap-2" role={multiple ? undefined : "radiogroup"}>
        {children}
      </div>
      {error ? (
        <p className="mt-1.5 text-xs font-medium text-rose-600" role="alert">
          {error}
        </p>
      ) : hint ? (
        <p className="mt-1.5 text-xs text-slate-500">{hint}</p>
      ) : null}
    </fieldset>
  );
}

export type SwitchProps = {
  checked: boolean;
  onChange: (next: boolean) => void;
  label: ReactNode;
  hint?: ReactNode;
  disabled?: boolean;
  className?: string;
};

export function Switch({ checked, onChange, label, hint, disabled = false, className }: SwitchProps) {
  const labelId = useId();
  const hintId = useId();
  return (
    <div className={cn("flex items-start justify-between gap-4", className)}>
      <div className="min-w-0">
        <p id={labelId} className="text-sm font-medium text-slate-800">
          {label}
        </p>
        {hint ? (
          <p id={hintId} className="mt-0.5 text-xs leading-5 text-slate-500">
            {hint}
          </p>
        ) : null}
      </div>
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        aria-labelledby={labelId}
        aria-describedby={hint ? hintId : undefined}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        className={cn(
          "relative inline-flex h-10 w-[4.25rem] shrink-0 items-center rounded-full border px-1 transition focus-visible:outline-none focus-visible:ring-4 disabled:cursor-not-allowed disabled:opacity-50",
          checked
            ? "border-emerald-700 bg-emerald-700 focus-visible:ring-emerald-600/30"
            : "border-slate-200 bg-slate-100 focus-visible:ring-slate-400/30",
        )}
      >
        <span
          aria-hidden="true"
          className={cn(
            "h-7 w-7 rounded-full bg-white shadow transition-transform",
            checked ? "translate-x-[2.1rem]" : "translate-x-0",
          )}
        />
      </button>
    </div>
  );
}

/** Small inline disclosure used for exercise instructions. */
export function Disclosure({
  label,
  children,
  className,
}: {
  label: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <details className={cn("group", className)}>
      <summary className="inline-flex min-h-10 cursor-pointer list-none items-center gap-1.5 rounded-xl px-2 text-xs font-semibold text-emerald-800 transition hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/20">
        <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
          <circle cx="10" cy="10" r="7.25" stroke="currentColor" strokeWidth="1.6" />
          <path d="M10 9V13.5M10 6.6V6.7" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
        </svg>
        {label}
      </summary>
      <div className="mt-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm leading-6 text-slate-600">
        {children}
      </div>
    </details>
  );
}
