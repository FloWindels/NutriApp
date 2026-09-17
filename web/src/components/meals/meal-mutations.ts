"use client";

import type { QueryClient } from "@tanstack/react-query";
import { MEAL_MUTATION_KEYS } from "@/lib/query-keys";
import type { MealItem, MealItemInput, MealType } from "@/lib/types/api";

/** Canonical order of the four meals (brief §16.3). */
export const MEAL_ORDER: MealType[] = ["petit_dejeuner", "dejeuner", "diner", "collation"];

/** Share of the daily budget per meal (`App\Services\MealBudget`, brief §4.2). */
export const MEAL_SHARES: Record<MealType, number> = {
  petit_dejeuner: 0.25,
  dejeuner: 0.35,
  diner: 0.3,
  collation: 0.1,
};

/** Invalidate every family touched by a meal mutation (documented in the foundation notes). */
export function invalidateMealFamilies(queryClient: QueryClient): Promise<unknown> {
  return Promise.all(
    MEAL_MUTATION_KEYS.map((queryKey) => queryClient.invalidateQueries({ queryKey })),
  );
}

/**
 * Rebuild the `ItemInput` that recreates a deleted item (undo).
 * Custom items keep their absolute snapshot values.
 */
export function itemInputFromMealItem(item: MealItem): MealItemInput {
  const base = { quantity: item.quantity, unit: item.unit };
  if (item.source_type === "food" && item.food_id) {
    return { ...base, food_id: item.food_id };
  }
  if (item.source_type === "recipe" && item.recipe_id) {
    return { ...base, recipe_id: item.recipe_id };
  }
  return {
    ...base,
    custom: {
      label: item.label,
      per_100g: false,
      calories: item.calories,
      proteins: item.proteins,
      carbs: item.carbs,
      fat: item.fat,
    },
  };
}
