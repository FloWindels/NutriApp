"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { BudgetCard } from "@/components/dashboard/budget-card";
import { AddToMealDialog } from "@/components/ui/add-to-meal-dialog";
import { ErrorState } from "@/components/ui/error-state";
import { SkeletonCard } from "@/components/ui/skeleton";
import { usePortions } from "@/hooks/use-portions";
import { apiGet, getErrorMessage } from "@/lib/api-client";
import { queryKeys } from "@/lib/query-keys";
import type { DataEnvelope, DaySummary, MealType } from "@/lib/types/api";
import { MealCard } from "./meal-card";
import { MEAL_ORDER } from "./meal-mutations";

export type MealsJournalProps = {
  date: string;
};

/** Journal of the day: budget + the four meals with their items (brief §16.3). */
export function MealsJournal({ date }: MealsJournalProps) {
  const { portions } = usePortions();
  const [dialog, setDialog] = useState<{ open: boolean; mealType?: MealType }>({ open: false });

  const query = useQuery({
    queryKey: queryKeys.meals.day(date),
    queryFn: () => apiGet<DataEnvelope<DaySummary>>("/meals", { date }),
  });

  if (query.isPending) {
    return (
      <div className="space-y-4">
        <SkeletonCard lines={4} />
        <div className="grid gap-4 lg:grid-cols-2">
          <SkeletonCard lines={3} />
          <SkeletonCard lines={3} />
          <SkeletonCard lines={3} />
          <SkeletonCard lines={3} />
        </div>
      </div>
    );
  }

  const day = query.data?.data;

  if (query.isError || !day) {
    return (
      <ErrorState
        message={getErrorMessage(query.error)}
        onRetry={() => void query.refetch()}
        retrying={query.isFetching}
      />
    );
  }

  const budgetCalories = (day.targets?.calories ?? 0) + (day.sport?.calories_bonus ?? 0);

  return (
    <div className="space-y-4">
      <BudgetCard
        targets={day.targets}
        consumed={day.totals}
        remaining={day.remaining}
        caloriesBonus={day.sport?.calories_bonus ?? 0}
        isEstimate={day.sport?.is_estimate || day.totals?.is_partial}
      />

      <div className="grid gap-4 lg:grid-cols-2">
        {MEAL_ORDER.map((type) => (
          <MealCard
            key={type}
            type={type}
            meal={day.meals.find((meal) => meal.type === type) ?? null}
            budgetCalories={budgetCalories}
            portions={portions}
            onAdd={(mealType) => setDialog({ open: true, mealType })}
          />
        ))}
      </div>

      <AddToMealDialog
        open={dialog.open}
        onClose={() => setDialog({ open: false })}
        date={date}
        mealType={dialog.mealType}
        mode="search"
      />
    </div>
  );
}

export default MealsJournal;
