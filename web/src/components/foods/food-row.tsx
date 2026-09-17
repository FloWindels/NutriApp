import type { ReactNode } from "react";
import { cn } from "@/lib/cn";
import { formatKcal } from "@/lib/format";
import type { Food } from "@/lib/types/api";
import { EstimatePill } from "@/components/ui/pill";
import { FoodSourcePill, FoodThumb, perUnitLabel } from "./food-utils";

/** Compact selectable row (pickers, dialogs). */
export function FoodRow({
  food,
  onClick,
  selected = false,
  trailing,
  disabled = false,
}: {
  food: Food;
  onClick: () => void;
  selected?: boolean;
  trailing?: ReactNode;
  disabled?: boolean;
}) {
  return (
    <div className="flex items-center gap-2">
      <button
        type="button"
        onClick={onClick}
        disabled={disabled}
        aria-pressed={selected}
        className={cn(
          "flex min-h-[3.5rem] min-w-0 flex-1 items-center gap-3 rounded-2xl border bg-white px-3 py-2 text-left transition hover:border-emerald-300 hover:bg-emerald-50/40 disabled:opacity-60",
          selected ? "border-emerald-600 ring-4 ring-emerald-600/10" : "border-slate-200",
        )}
      >
        <FoodThumb food={food} size="sm" />
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-semibold text-slate-900">{food.name}</span>
          <span className="block truncate text-xs text-slate-500">
            {[food.brand, `${formatKcal(food.calories)} / ${perUnitLabel(food)}`].filter(Boolean).join(" · ")}
          </span>
        </span>
        <span className="hidden shrink-0 items-center gap-1.5 sm:flex">
          {food.is_estimate ? <EstimatePill /> : null}
          <FoodSourcePill food={food} />
        </span>
      </button>
      {trailing}
    </div>
  );
}

export default FoodRow;
