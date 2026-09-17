"use client";

import { formatGrams, formatKcal } from "@/lib/format";
import type { Food } from "@/lib/types/api";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { EstimatePill, Pill } from "@/components/ui/pill";
import { StatCard } from "@/components/ui/stat-card";
import { canEditFood, FoodSourcePill, FoodThumb, HeartIcon, perUnitLabel, servingText } from "./food-utils";

export type FoodDetailPanelProps = {
  food: Food;
  favorite: boolean;
  favoriteBusy?: boolean;
  onToggleFavorite: () => void;
  onAddToMeal: () => void;
  onAddToStock: () => void;
  onEdit: () => void;
};

function cleanAllergen(tag: string): string {
  return tag.replace(/^[a-z]{2}:/, "").replace(/-/g, " ");
}

/** Fiche détaillée : macros pour 100 g, portion, provenance et actions. */
export function FoodDetailPanel({
  food,
  favorite,
  favoriteBusy = false,
  onToggleFavorite,
  onAddToMeal,
  onAddToStock,
  onEdit,
}: FoodDetailPanelProps) {
  const basis = perUnitLabel(food);
  const serving = servingText(food);
  const extras: { label: string; value: number | null }[] = [
    { label: "Fibres", value: food.fiber },
    { label: "Sucres", value: food.sugar },
    { label: "Sel", value: food.salt },
  ].filter((extra) => extra.value !== null && extra.value !== undefined);

  return (
    <Card padding="md" className="space-y-5" aria-label={`Fiche de ${food.name}`}>
      <div className="flex items-start gap-4">
        <FoodThumb food={food} size="lg" />
        <div className="min-w-0 flex-1">
          <h2 className="text-lg font-semibold leading-6 text-slate-950">{food.name}</h2>
          <p className="mt-0.5 text-sm text-slate-500">{food.brand ?? "Marque inconnue"}</p>
          <div className="mt-2 flex flex-wrap items-center gap-1.5">
            <FoodSourcePill food={food} />
            {food.is_estimate ? <EstimatePill /> : null}
            {food.barcode ? (
              <Pill tone="slate" title="Code-barres">
                {food.barcode}
              </Pill>
            ) : null}
          </div>
        </div>
        <button
          type="button"
          onClick={onToggleFavorite}
          disabled={favoriteBusy}
          aria-pressed={favorite}
          aria-label={favorite ? "Retirer des favoris" : "Ajouter aux favoris"}
          className={
            favorite
              ? "grid h-11 w-11 shrink-0 place-items-center rounded-xl border border-rose-200 bg-rose-50 text-rose-600 transition hover:bg-rose-100 disabled:opacity-60"
              : "grid h-11 w-11 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-400 transition hover:border-rose-200 hover:text-rose-500 disabled:opacity-60"
          }
        >
          <HeartIcon filled={favorite} />
        </button>
      </div>

      <div>
        <p className="mb-2 text-xs font-medium uppercase tracking-[0.14em] text-slate-500">
          Valeurs pour {basis}
        </p>
        <div className="grid gap-3 sm:grid-cols-2">
          <StatCard label="Calories" value={formatKcal(food.calories)} caption={`pour ${basis}`} tone="cyan" estimate={food.is_estimate} />
          <StatCard label="Protéines" value={formatGrams(food.proteins)} caption={`pour ${basis}`} tone="emerald" />
          <StatCard label="Glucides" value={formatGrams(food.carbs)} caption={`pour ${basis}`} tone="lime" />
          <StatCard label="Lipides" value={formatGrams(food.fat)} caption={`pour ${basis}`} tone="amber" />
        </div>
        {extras.length > 0 ? (
          <div className="mt-3 flex flex-wrap items-center gap-1.5">
            {extras.map((extra) => (
              <Pill key={extra.label} tone="slate">
                {extra.label} {formatGrams(extra.value)}
              </Pill>
            ))}
          </div>
        ) : null}
      </div>

      {serving ? (
        <p className="rounded-2xl bg-slate-50 px-3 py-2 text-sm text-slate-600">{serving}</p>
      ) : (
        <p className="rounded-2xl bg-slate-50 px-3 py-2 text-sm text-slate-500">
          Aucune taille de portion connue : les quantités en pièce ou en portion seront des estimations.
        </p>
      )}

      {food.allergens && food.allergens.length > 0 ? (
        <div>
          <p className="mb-1.5 text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Allergènes déclarés</p>
          <div className="flex flex-wrap gap-1.5">
            {food.allergens.map((tag) => (
              <Pill key={tag} tone="rose">
                {cleanAllergen(tag)}
              </Pill>
            ))}
          </div>
        </div>
      ) : null}

      <div className="flex flex-col gap-2">
        <Button onClick={onAddToMeal} block>
          Ajouter à un repas
        </Button>
        <Button variant="secondary" onClick={onAddToStock} block>
          Ajouter au stock
        </Button>
        {canEditFood(food) ? (
          <Button variant="ghost" onClick={onEdit} block>
            Modifier
          </Button>
        ) : null}
      </div>
    </Card>
  );
}

export default FoodDetailPanel;
