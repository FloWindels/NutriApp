"use client";

import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";
import { formatKcal, formatPercent } from "@/lib/format";
import type { Meal, MealType, Portion } from "@/lib/types/api";
import { MEAL_TYPE_LABELS } from "@/lib/vocab";
import { MEAL_SHARES } from "./meal-mutations";
import { MealItemRow } from "./meal-item-row";

export type MealCardProps = {
  type: MealType;
  meal?: Meal | null;
  /** Daily budget (targets + sport bonus) used for the share hint. */
  budgetCalories: number;
  portions: Portion[];
  onAdd: (type: MealType) => void;
};

/** One of the four meals of the day with its items and its « + Ajouter » action. */
export function MealCard({ type, meal, budgetCalories, portions, onAdd }: MealCardProps) {
  const share = MEAL_SHARES[type];
  const shareKcal = Math.round(budgetCalories * share);
  const items = meal?.items ?? [];
  const calories = meal?.totals?.calories ?? 0;

  return (
    <Card padding="sm">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 className="text-base font-semibold text-slate-900">{MEAL_TYPE_LABELS[type]}</h3>
          <p className="mt-0.5 text-xs text-slate-500">
            {formatKcal(calories)} · ~{formatPercent(share * 100)} de ton budget
            {budgetCalories > 0 ? ` (≈ ${formatKcal(shareKcal)})` : ""}
          </p>
        </div>
        <Button
          variant="secondary"
          onClick={() => onAdd(type)}
          aria-label={`Ajouter un aliment au ${MEAL_TYPE_LABELS[type].toLowerCase()}`}
        >
          + Ajouter
        </Button>
      </div>

      {!meal || items.length === 0 ? (
        <EmptyState
          compact
          className="mt-3"
          title="Rien d’enregistré"
          message="Ajoute un aliment, une recette ou une entrée personnalisée."
          action={<Button onClick={() => onAdd(type)}>Ajouter</Button>}
        />
      ) : (
        <ul className="mt-2 divide-y divide-slate-100">
          {items.map((item) => (
            <MealItemRow key={item.id} item={item} mealId={meal.id} portions={portions} />
          ))}
        </ul>
      )}
    </Card>
  );
}

export default MealCard;
