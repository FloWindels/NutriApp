"use client";

import { useId } from "react";
import { Pill, type PillTone } from "@/components/ui/pill";
import { cn } from "@/lib/cn";
import { formatQty } from "@/lib/format";
import type { ShoppingItem, ShoppingSource } from "@/lib/types/api";
import { SHOPPING_SOURCE_LABELS, labelFor } from "@/lib/vocab";
import { unitLabel } from "@/lib/units";

/** Provenance of a line (brief §11). */
const SOURCE_TONES: Record<ShoppingSource, PillTone> = {
  manuel: "slate",
  auto_stock: "amber",
  planificateur: "sky",
  recommandation: "violet",
};

export type ShoppingRowProps = {
  item: ShoppingItem;
  onToggle: (item: ShoppingItem, checked: boolean) => void;
  onToStock: (item: ShoppingItem) => void;
  onDelete: (item: ShoppingItem) => void;
  disabled?: boolean;
};

export function ShoppingRow({ item, onToggle, onToStock, onDelete, disabled = false }: ShoppingRowProps) {
  const checkboxId = useId();
  const quantityLabel =
    item.quantity !== null ? formatQty(item.quantity, item.unit ? unitLabel(item.unit) : "") : null;

  return (
    <li
      className={cn(
        "flex items-center gap-3 rounded-2xl border px-3 py-2.5 transition",
        item.checked ? "border-slate-200 bg-slate-50" : "border-slate-200 bg-white",
      )}
    >
      <input
        id={checkboxId}
        type="checkbox"
        checked={item.checked}
        disabled={disabled}
        onChange={(event) => onToggle(item, event.target.checked)}
        className="h-6 w-6 shrink-0 rounded-md border-slate-300 accent-emerald-700"
      />

      <label htmlFor={checkboxId} className="min-w-0 flex-1 cursor-pointer">
        <span
          className={cn(
            "block truncate text-sm font-medium",
            item.checked ? "text-slate-400 line-through" : "text-slate-900",
          )}
        >
          {item.label}
        </span>
        <span className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
          {quantityLabel ? <span className="font-medium text-slate-600">{quantityLabel}</span> : null}
          <Pill tone={SOURCE_TONES[item.source] ?? "slate"} dot>
            {labelFor(SHOPPING_SOURCE_LABELS, item.source)}
          </Pill>
        </span>
      </label>

      <button
        type="button"
        onClick={() => onToStock(item)}
        disabled={disabled}
        title={`Mettre « ${item.label} » au stock`}
        aria-label={`Mettre « ${item.label} » au stock`}
        className="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-400/20 disabled:opacity-50"
      >
        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" aria-hidden="true">
          <path
            d="M4 7H20V20H4V7Z M4 7L6 3H18L20 7 M10 11H14"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>
      </button>

      <button
        type="button"
        onClick={() => onDelete(item)}
        disabled={disabled}
        title={`Supprimer « ${item.label} »`}
        aria-label={`Supprimer « ${item.label} »`}
        className="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-rose-200 bg-rose-50 text-rose-700 transition hover:bg-rose-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rose-500/20 disabled:opacity-50"
      >
        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" aria-hidden="true">
          <path
            d="M5 7H19M10 11V17M14 11V17M6 7L7 20H17L18 7M9.5 7V4.5H14.5V7"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>
      </button>
    </li>
  );
}

export default ShoppingRow;
