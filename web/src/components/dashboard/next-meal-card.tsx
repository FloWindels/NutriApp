"use client";

import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { formatKcal } from "@/lib/format";
import type { DashboardMeal, MealType } from "@/lib/types/api";
import { MEAL_TYPE_LABELS } from "@/lib/vocab";

export type NextMealCardProps = {
  mealType: MealType;
  meal?: DashboardMeal | null;
  onAdd: () => void;
};

/** « Déjeuner · rien enregistré » / « Déjeuner · 680 kcal · 3 aliments » + CTA Ajouter. */
export function NextMealCard({ mealType, meal, onAdd }: NextMealCardProps) {
  const label = MEAL_TYPE_LABELS[mealType];
  const itemsCount = meal?.items_count ?? 0;
  const detail =
    meal && itemsCount > 0
      ? `${formatKcal(meal.calories)} · ${itemsCount} ${itemsCount > 1 ? "aliments" : "aliment"}`
      : "rien enregistré";

  return (
    <Card>
      <CardHeader
        title="Prochain repas"
        subtitle="Ajoute ce que tu manges en quelques secondes."
        actions={
          <Link
            href="/dashboard/historique-repas-journee"
            className="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
          >
            Repas du jour
          </Link>
        }
      />
      <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <div className="min-w-0">
          <p className="text-lg font-semibold text-slate-900">{label}</p>
          <p className="mt-0.5 text-sm text-slate-600">{detail}</p>
        </div>
        <Button onClick={onAdd} aria-label={`Ajouter un aliment au ${MEAL_TYPE_LABELS[mealType].toLowerCase()}`}>
          Ajouter
        </Button>
      </div>
    </Card>
  );
}

export default NextMealCard;
