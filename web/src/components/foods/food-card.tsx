"use client";

import { cn } from "@/lib/cn";
import { formatKcal } from "@/lib/format";
import type { Food } from "@/lib/types/api";
import { MacroPills } from "@/components/ui/macro-pills";
import { EstimatePill } from "@/components/ui/pill";
import { FoodSourcePill, FoodThumb, HeartIcon, perUnitLabel } from "./food-utils";

export type FoodCardProps = {
  food: Food;
  /** Effective favourite state (server value + optimistic override). */
  favorite: boolean;
  selected?: boolean;
  onSelect: () => void;
  onToggleFavorite: () => void;
  favoriteBusy?: boolean;
};

/** Result card of the food search grid: picture, name, kcal, macros, source pill, ♥. */
export function FoodCard({ food, favorite, selected = false, onSelect, onToggleFavorite, favoriteBusy = false }: FoodCardProps) {
  return (
    <article
      className={cn(
        "relative flex flex-col rounded-[1.5rem] border bg-white p-3 shadow-[0_10px_30px_rgba(15,23,42,0.05)] transition",
        selected ? "border-emerald-600 ring-4 ring-emerald-600/10" : "border-slate-200 hover:border-slate-300",
      )}
    >
      <button
        type="button"
        onClick={onSelect}
        aria-pressed={selected}
        aria-label={`Voir la fiche de ${food.name}`}
        className="flex min-w-0 flex-1 items-start gap-3 rounded-2xl text-left focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/20"
      >
        <FoodThumb food={food} size="md" />
        <span className="min-w-0 flex-1 pr-10">
          <span className="block truncate text-sm font-semibold text-slate-900">{food.name}</span>
          <span className="block truncate text-xs text-slate-500">{food.brand ?? "Marque inconnue"}</span>
          <span className="mt-1 block text-sm font-medium tabular-nums text-slate-800">
            {formatKcal(food.calories)} <span className="text-xs font-normal text-slate-500">/ {perUnitLabel(food)}</span>
          </span>
        </span>
      </button>

      <button
        type="button"
        onClick={onToggleFavorite}
        disabled={favoriteBusy}
        aria-pressed={favorite}
        aria-label={favorite ? `Retirer ${food.name} des favoris` : `Ajouter ${food.name} aux favoris`}
        className={cn(
          "absolute right-2 top-2 grid h-10 w-10 place-items-center rounded-xl border transition disabled:opacity-60",
          favorite
            ? "border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100"
            : "border-slate-200 bg-white text-slate-400 hover:border-rose-200 hover:text-rose-500",
        )}
      >
        <HeartIcon filled={favorite} />
      </button>

      <div className="mt-3 flex flex-wrap items-center gap-1.5">
        <MacroPills values={{ proteins: food.proteins, carbs: food.carbs, fat: food.fat }} />
      </div>
      <div className="mt-2 flex flex-wrap items-center gap-1.5">
        <FoodSourcePill food={food} />
        {food.is_estimate ? <EstimatePill /> : null}
      </div>
    </article>
  );
}

export default FoodCard;
