"use client";

import { useEffect, useId, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { EstimatePill } from "@/components/ui/pill";
import { FieldWrapper, inputClassName, inputStateClassName } from "@/components/ui/field";
import { SkeletonList } from "@/components/ui/skeleton";
import { apiGet, getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { formatKcal } from "@/lib/format";
import { queryKeys } from "@/lib/query-keys";
import type { PaginatedEnvelope, Recipe } from "@/lib/types/api";

/** Debounce any value (search inputs) — 350 ms by default. */
export function useDebouncedValue<T>(value: T, delay = 350): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delay);
    return () => window.clearTimeout(timer);
  }, [value, delay]);
  return debounced;
}

export type RecipePickerProps = {
  /** Currently selected recipe (controlled). */
  value: Recipe | null;
  onChange: (recipe: Recipe | null) => void;
  label?: string;
  hint?: string;
  error?: string;
  /** Extra query filter sent to `GET /recipes`. */
  mealType?: string | null;
  disabled?: boolean;
};

/**
 * Recipe search + selection used by the planner slot form and the household
 * common meal card. Searches `GET /api/recipes?q=` (debounced, never Open Food Facts).
 */
export function RecipePicker({
  value,
  onChange,
  label = "Recette",
  hint,
  error,
  mealType = null,
  disabled = false,
}: RecipePickerProps) {
  const inputId = useId();
  const listId = useId();
  const [term, setTerm] = useState("");
  const debounced = useDebouncedValue(term.trim());

  const params = { q: debounced || undefined, meal_type: mealType ?? undefined, per_page: 12 };
  const query = useQuery({
    queryKey: queryKeys.recipes.list(params),
    queryFn: () =>
      apiGet<PaginatedEnvelope<Recipe>>("/recipes", {
        q: debounced || undefined,
        meal_type: mealType ?? undefined,
        per_page: 12,
      }),
    enabled: !disabled && !value,
    staleTime: 60_000,
  });

  const recipes = query.data?.data ?? [];

  if (value) {
    return (
      <FieldWrapper label={label} hint={hint} error={error} htmlFor={inputId}>
        <div className="flex items-start justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50/70 p-3">
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold text-emerald-950">{value.title}</p>
            <p className="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-emerald-900/80">
              <span>{formatKcal(value.per_serving.calories)} / portion</span>
              {value.is_estimate ? <EstimatePill /> : null}
            </p>
          </div>
          <Button
            type="button"
            variant="secondary"
            onClick={() => onChange(null)}
            disabled={disabled}
            className="shrink-0"
          >
            Changer
          </Button>
        </div>
      </FieldWrapper>
    );
  }

  return (
    <FieldWrapper label={label} hint={hint} error={error} htmlFor={inputId}>
      <input
        id={inputId}
        type="search"
        value={term}
        onChange={(event) => setTerm(event.target.value)}
        placeholder="Chercher une recette…"
        autoComplete="off"
        disabled={disabled}
        aria-controls={listId}
        className={cn(inputClassName, inputStateClassName(Boolean(error)))}
      />

      <div id={listId} className="mt-2 max-h-56 overflow-y-auto rounded-2xl border border-slate-200 bg-white">
        {query.isPending ? (
          <div className="p-3">
            <SkeletonList rows={3} />
          </div>
        ) : query.isError ? (
          <div className="space-y-2 p-3 text-sm text-slate-600">
            <p>{getErrorMessage(query.error)}</p>
            <Button type="button" variant="secondary" onClick={() => void query.refetch()}>
              Réessayer
            </Button>
          </div>
        ) : recipes.length === 0 ? (
          <p className="p-3 text-sm text-slate-500">
            Aucune recette trouvée. Utilise l’onglet « Titre libre » ou crée une recette.
          </p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {recipes.map((recipe) => (
              <li key={recipe.id}>
                <button
                  type="button"
                  onClick={() => onChange(recipe)}
                  className="flex min-h-[44px] w-full items-center justify-between gap-3 px-3 py-2 text-left transition hover:bg-slate-50 focus-visible:bg-slate-50 focus-visible:outline-none"
                >
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-medium text-slate-900">{recipe.title}</span>
                    <span className="block text-xs text-slate-500">
                      {formatKcal(recipe.per_serving.calories)} / portion
                    </span>
                  </span>
                  <span className="shrink-0 text-xs font-medium text-emerald-700">Choisir</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </FieldWrapper>
  );
}

export default RecipePicker;
