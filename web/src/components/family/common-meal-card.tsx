"use client";

import { useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { EstimatePill, Pill } from "@/components/ui/pill";
import { Field, SelectField } from "@/components/ui/field";
import { apiGet, apiPost, getErrorMessage } from "@/lib/api-client";
import { formatKcal, todayIso } from "@/lib/format";
import { queryKeys } from "@/lib/query-keys";
import { MEAL_TYPE_LABELS } from "@/lib/vocab";
import { MEAL_TYPES } from "@/lib/types/api";
import type {
  CommonMealPreview,
  DataEnvelope,
  Household,
  MealType,
  PaginatedEnvelope,
  Recipe,
} from "@/lib/types/api";

export type CommonMealCardProps = {
  household: Household;
  onCreated: () => Promise<void>;
  onSuccess: (message: string) => void;
};

/**
 * « Repas commun » — une même recette pour tout le foyer, avec des portions adaptées
 * au budget de chaque membre (brief §10).
 */
export function CommonMealCard({ household, onCreated, onSuccess }: CommonMealCardProps) {
  const [query, setQuery] = useState("");
  const [recipe, setRecipe] = useState<Recipe | null>(null);
  const [mealType, setMealType] = useState<MealType>("diner");
  const [portions, setPortions] = useState<Record<string, number>>({});
  const [preview, setPreview] = useState<CommonMealPreview | null>(null);
  const [error, setError] = useState<string | null>(null);

  const recipesQuery = useQuery({
    queryKey: queryKeys.recipes.list({ q: query }),
    queryFn: () => apiGet<PaginatedEnvelope<Recipe>>("/recipes", { q: query, per_page: 8 }),
    enabled: query.trim().length >= 2,
  });

  const loadPreview = useMutation({
    mutationFn: (input: { recipe_id: number; meal_type: MealType }) =>
      apiPost<DataEnvelope<CommonMealPreview>>("/household/common-meal/preview", input),
    onSuccess: (response) => {
      setError(null);
      setPreview(response.data);
      setPortions(
        Object.fromEntries(
          response.data.members.map((member) => [String(member.user_id), member.portions]),
        ),
      );
    },
    onError: (err) => setError(getErrorMessage(err)),
  });

  const create = useMutation({
    mutationFn: () =>
      apiPost<DataEnvelope<unknown>>("/household/common-meal", {
        recipe_id: recipe?.id,
        meal_type: mealType,
        date: todayIso(),
        portions,
      }),
    onSuccess: async () => {
      setPreview(null);
      setRecipe(null);
      setQuery("");
      await onCreated();
      onSuccess("Repas commun enregistré pour tout le foyer.");
    },
    onError: (err) => setError(getErrorMessage(err)),
  });

  function step(userId: number, delta: number) {
    setPortions((current) => {
      const next = Math.min(2, Math.max(0.5, (current[String(userId)] ?? 1) + delta));
      return { ...current, [String(userId)]: Math.round(next * 2) / 2 };
    });
  }

  return (
    <Card padding="md">
      <p className="mb-1 text-sm font-semibold text-slate-900">Repas commun</p>
      <p className="mb-3 text-sm text-slate-600">
        Une seule recette pour {household.members.length} personnes, avec des portions adaptées à
        chacun.
      </p>

      {error ? (
        <Banner tone="error" className="mb-3" onClose={() => setError(null)}>
          {error}
        </Banner>
      ) : null}

      <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
        <Field
          label="Recette"
          placeholder="Cherche une recette…"
          value={recipe ? recipe.title : query}
          onChange={(event) => {
            setRecipe(null);
            setPreview(null);
            setQuery(event.target.value);
          }}
        />
        <SelectField
          label="Repas"
          value={mealType}
          onChange={(event) => setMealType(event.target.value as MealType)}
        >
          {MEAL_TYPES.map((type) => (
            <option key={type} value={type}>
              {MEAL_TYPE_LABELS[type]}
            </option>
          ))}
        </SelectField>
        <Button
          disabled={!recipe}
          loading={loadPreview.isPending}
          onClick={() => recipe && loadPreview.mutate({ recipe_id: recipe.id, meal_type: mealType })}
        >
          Calculer les portions
        </Button>
      </div>

      {!recipe && query.trim().length >= 2 && recipesQuery.data ? (
        <ul className="mt-2 max-h-48 divide-y divide-slate-100 overflow-auto rounded-2xl border border-slate-200">
          {recipesQuery.data.data.map((item) => (
            <li key={item.id}>
              <button
                type="button"
                className="w-full px-4 py-2 text-left text-sm hover:bg-slate-50"
                onClick={() => {
                  setRecipe(item);
                  setQuery("");
                }}
              >
                {item.title}
                {item.per_serving.calories !== null ? (
                  <span className="ml-2 text-xs text-slate-500">
                    {formatKcal(item.per_serving.calories)} / portion
                  </span>
                ) : null}
              </button>
            </li>
          ))}
          {recipesQuery.data.data.length === 0 ? (
            <li className="px-4 py-2 text-sm text-slate-500">Aucune recette trouvée.</li>
          ) : null}
        </ul>
      ) : null}

      {preview ? (
        <div className="mt-4">
          <p className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
            {preview.recipe.title}
          </p>
          <ul className="divide-y divide-slate-100 rounded-2xl border border-slate-200">
            {preview.members.map((member) => {
              const value = portions[String(member.user_id)] ?? member.portions;
              const perServing = preview.recipe.per_serving.calories ?? 0;
              return (
                <li key={member.user_id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-slate-900">{member.name}</p>
                    <p className="text-xs text-slate-500">
                      Budget du repas : {formatKcal(member.target_kcal)}
                      {member.label ? ` · ${member.label}` : ""}
                    </p>
                  </div>
                  {member.is_estimate ? <EstimatePill /> : null}
                  <div className="flex items-center gap-2">
                    <Button variant="ghost" onClick={() => step(member.user_id, -0.5)} aria-label="Moins">
                      −
                    </Button>
                    <span className="w-16 text-center text-sm font-medium tabular-nums">
                      {value.toString().replace(".", ",")} port.
                    </span>
                    <Button variant="ghost" onClick={() => step(member.user_id, 0.5)} aria-label="Plus">
                      +
                    </Button>
                    <Pill tone="slate">{formatKcal(perServing * value)}</Pill>
                  </div>
                </li>
              );
            })}
          </ul>
          <Button className="mt-3" loading={create.isPending} onClick={() => create.mutate()}>
            Enregistrer pour tout le monde
          </Button>
        </div>
      ) : null}
    </Card>
  );
}

export default CommonMealCard;
