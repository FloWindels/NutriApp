"use client";

import { useId, useState, type KeyboardEvent } from "react";
import { cn } from "@/lib/cn";
import { inputClassName, inputStateClassName } from "@/components/ui/field";

/**
 * Chip inputs used by the profile form: a free-text tag field (allergènes, aliments
 * exclus, préférences) and a multi-select chip group (matériel, focus, zones à éviter).
 */

export type ChipInputProps = {
  label: string;
  hint?: string;
  placeholder?: string;
  values: string[];
  onChange: (values: string[]) => void;
  /** Max number of chips accepted (defaults to 20). */
  max?: number;
  error?: string;
};

export function ChipInput({ label, hint, placeholder, values, onChange, max = 20, error }: ChipInputProps) {
  const inputId = useId();
  const [draft, setDraft] = useState("");

  function commit(raw: string) {
    const parts = raw
      .split(/[,;]/)
      .map((part) => part.trim())
      .filter(Boolean);
    if (parts.length === 0) return;
    const next = [...values];
    for (const part of parts) {
      if (next.length >= max) break;
      if (!next.some((value) => value.toLowerCase() === part.toLowerCase())) next.push(part);
    }
    onChange(next);
    setDraft("");
  }

  function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === "Enter" || event.key === "," || event.key === ";") {
      event.preventDefault();
      commit(draft);
      return;
    }
    if (event.key === "Backspace" && draft === "" && values.length > 0) {
      onChange(values.slice(0, -1));
    }
  }

  return (
    <div className="min-w-0">
      <label htmlFor={inputId} className="mb-1.5 block text-sm font-medium text-slate-700">
        {label}
      </label>
      {values.length > 0 ? (
        <ul className="mb-2 flex flex-wrap gap-2">
          {values.map((value) => (
            <li key={value}>
              <span className="inline-flex min-h-10 items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 py-1 pl-3 pr-1 text-sm text-slate-700">
                {value}
                <button
                  type="button"
                  onClick={() => onChange(values.filter((item) => item !== value))}
                  aria-label={`Retirer ${value}`}
                  className="grid h-8 w-8 place-items-center rounded-full text-slate-500 transition hover:bg-slate-200 hover:text-slate-800"
                >
                  <svg viewBox="0 0 20 20" className="h-3.5 w-3.5" fill="none" aria-hidden="true">
                    <path d="M5 5L15 15M15 5L5 15" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                  </svg>
                </button>
              </span>
            </li>
          ))}
        </ul>
      ) : null}
      <div className="flex gap-2">
        <input
          id={inputId}
          type="text"
          value={draft}
          placeholder={placeholder}
          onChange={(event) => setDraft(event.target.value)}
          onKeyDown={onKeyDown}
          onBlur={() => commit(draft)}
          aria-invalid={error ? true : undefined}
          aria-describedby={hint ? `${inputId}-hint` : undefined}
          className={cn(inputClassName, inputStateClassName(Boolean(error)))}
        />
        <button
          type="button"
          onClick={() => commit(draft)}
          disabled={draft.trim() === "" || values.length >= max}
          className="h-12 shrink-0 rounded-2xl border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
        >
          Ajouter
        </button>
      </div>
      {error ? (
        <p className="mt-1.5 text-xs font-medium text-rose-600" role="alert">
          {error}
        </p>
      ) : hint ? (
        <p id={`${inputId}-hint`} className="mt-1.5 text-xs text-slate-500">
          {hint}
        </p>
      ) : null}
    </div>
  );
}

export type ChipOption<T extends string> = { key: T; label: string; disabled?: boolean };

export type ChipGroupProps<T extends string> = {
  legend: string;
  hint?: string;
  options: ChipOption<T>[];
  values: T[];
  onChange: (values: T[]) => void;
  /** When set, selecting this key clears every other one (e.g. « aucun »). */
  exclusiveKey?: T;
  error?: string;
};

export function ChipGroup<T extends string>({
  legend,
  hint,
  options,
  values,
  onChange,
  exclusiveKey,
  error,
}: ChipGroupProps<T>) {
  const hintId = useId();

  function toggle(key: T) {
    if (values.includes(key)) {
      onChange(values.filter((value) => value !== key));
      return;
    }
    if (exclusiveKey && key === exclusiveKey) {
      onChange([key]);
      return;
    }
    const next = exclusiveKey ? values.filter((value) => value !== exclusiveKey) : values;
    onChange([...next, key]);
  }

  return (
    <fieldset className="min-w-0" aria-describedby={hint ? hintId : undefined}>
      <legend className="mb-1.5 text-sm font-medium text-slate-700">{legend}</legend>
      <div className="flex flex-wrap gap-2">
        {options.map((option) => {
          const active = values.includes(option.key);
          return (
            <button
              key={option.key}
              type="button"
              onClick={() => toggle(option.key)}
              disabled={option.disabled}
              aria-pressed={active}
              className={cn(
                "inline-flex min-h-10 items-center rounded-full border px-4 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50",
                active
                  ? "border-emerald-700 bg-emerald-700 text-white"
                  : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50",
              )}
            >
              {option.label}
            </button>
          );
        })}
      </div>
      {error ? (
        <p className="mt-1.5 text-xs font-medium text-rose-600" role="alert">
          {error}
        </p>
      ) : hint ? (
        <p id={hintId} className="mt-1.5 text-xs text-slate-500">
          {hint}
        </p>
      ) : null}
    </fieldset>
  );
}

export type RadioChipsProps<T extends string> = {
  legend: string;
  hint?: string;
  options: ChipOption<T>[];
  value: T;
  onChange: (value: T) => void;
};

/** Single-choice chip row (objectif, coefficient calories…). */
export function RadioChips<T extends string>({ legend, hint, options, value, onChange }: RadioChipsProps<T>) {
  const hintId = useId();
  return (
    <fieldset className="min-w-0" aria-describedby={hint ? hintId : undefined}>
      <legend className="mb-1.5 text-sm font-medium text-slate-700">{legend}</legend>
      <div className="flex flex-wrap gap-2">
        {options.map((option) => {
          const active = value === option.key;
          return (
            <button
              key={option.key}
              type="button"
              onClick={() => onChange(option.key)}
              disabled={option.disabled}
              aria-pressed={active}
              className={cn(
                "inline-flex min-h-10 items-center rounded-full border px-4 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50",
                active
                  ? "border-emerald-700 bg-emerald-700 text-white"
                  : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50",
              )}
            >
              {option.label}
            </button>
          );
        })}
      </div>
      {hint ? (
        <p id={hintId} className="mt-1.5 text-xs text-slate-500">
          {hint}
        </p>
      ) : null}
    </fieldset>
  );
}
