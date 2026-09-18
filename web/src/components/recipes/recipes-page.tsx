"use client";

import { useMemo, useState } from "react";
import Image from "next/image";
import { useInfiniteQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiDelete, apiGet, getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { formatGrams, formatKcal, formatMinutes } from "@/lib/format";
import { queryKeys } from "@/lib/query-keys";
import type { MealType, PaginatedEnvelope, Recipe, RecipeTag } from "@/lib/types/api";
import { MEAL_TYPES } from "@/lib/types/api";
import { MEAL_TYPE_LABELS, RECIPE_TAG_LABELS } from "@/lib/vocab";
import { useDebounced } from "@/components/foods/hooks";
import { AddToMealDialog } from "@/components/ui/add-to-meal-dialog";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { MacroPills } from "@/components/ui/macro-pills";
import { Modal } from "@/components/ui/modal";
import { EstimatePill, Pill } from "@/components/ui/pill";
import { SectionHeader } from "@/components/ui/section-header";
import { Skeleton } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { RecipeEditor } from "./recipe-editor";

type Scope = "publiques" | "mine" | "toutes";

const SCOPES: { key: Scope; label: string }[] = [
  { key: "publiques", label: "Publiques" },
  { key: "mine", label: "Mes recettes" },
  { key: "toutes", label: "Toutes" },
];

const TAGS = Object.keys(RECIPE_TAG_LABELS) as RecipeTag[];
const PER_PAGE = 12;

function perServingCalories(recipe: Recipe): number {
  if (recipe.per_serving && typeof recipe.per_serving.calories === "number") return recipe.per_serving.calories;
  const servings = recipe.servings && recipe.servings > 0 ? recipe.servings : 1;
  return recipe.calories / servings;
}

function RecipeThumb({ recipe, className }: { recipe: Recipe; className?: string }) {
  return (
    <span
      className={cn("relative block overflow-hidden rounded-2xl border border-slate-200 bg-slate-50", className)}
      aria-hidden="true"
    >
      {recipe.image_url ? (
        <Image src={recipe.image_url} alt="" fill unoptimized sizes="320px" className="object-cover" />
      ) : (
        <span className="grid h-full w-full place-items-center text-slate-300">
          <svg viewBox="0 0 24 24" className="h-8 w-8" fill="none">
            <path d="M5 9H19L18 20H6L5 9Z" stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round" />
            <path d="M8 9C8 6.8 9.8 5 12 5C14.2 5 16 6.8 16 9" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
          </svg>
        </span>
      )}
    </span>
  );
}

function RecipeCard({
  recipe,
  onOpen,
  onAddToMeal,
  onEdit,
  onDelete,
}: {
  recipe: Recipe;
  onOpen: () => void;
  onAddToMeal: () => void;
  onEdit: () => void;
  onDelete: () => void;
}) {
  const perServing = recipe.per_serving;
  return (
    <Card padding="sm" className="flex flex-col gap-3">
      <button
        type="button"
        onClick={onOpen}
        className="flex min-w-0 items-start gap-3 rounded-2xl text-left focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/20"
        aria-label={`Ouvrir la recette ${recipe.title}`}
      >
        <RecipeThumb recipe={recipe} className="h-20 w-20 shrink-0" />
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-semibold text-slate-900">{recipe.title}</span>
          <span className="mt-0.5 block text-xs text-slate-500">
            {recipe.prep_time_minutes ? `${formatMinutes(recipe.prep_time_minutes)} · ` : ""}
            {formatKcal(perServingCalories(recipe))} / portion
          </span>
          <span className="mt-1 block text-xs text-slate-500">
            {recipe.ingredients_count} ingrédient{recipe.ingredients_count > 1 ? "s" : ""} ·{" "}
            {recipe.servings} portion{recipe.servings > 1 ? "s" : ""}
          </span>
        </span>
      </button>

      <MacroPills
        values={{
          proteins: perServing?.proteins ?? null,
          carbs: perServing?.carbs ?? null,
          fat: perServing?.fat ?? null,
        }}
      />

      <div className="flex flex-wrap items-center gap-1.5">
        {(recipe.tags ?? []).map((tag) => (
          <Pill key={tag} tone="lime">
            {RECIPE_TAG_LABELS[tag] ?? tag}
          </Pill>
        ))}
        {recipe.is_public ? null : (
          <Pill tone="slate" dot>
            Privée
          </Pill>
        )}
        {recipe.is_estimate ? <EstimatePill /> : null}
      </div>

      <div className="mt-auto flex flex-wrap items-center gap-2">
        <Button size="md" onClick={onAddToMeal}>
          Ajouter à un repas
        </Button>
        {recipe.is_owner ? (
          <>
            <Button size="md" variant="secondary" onClick={onEdit}>
              Modifier
            </Button>
            <Button size="md" variant="danger" onClick={onDelete}>
              Supprimer
            </Button>
          </>
        ) : null}
      </div>
    </Card>
  );
}

/** « Recettes » (brief §5 et §17) : filtres, recherche, éditeur et ajout au repas. */
export function RecipesPage() {
  const queryClient = useQueryClient();
  const { success } = useToast();

  const [scope, setScope] = useState<Scope>("toutes");
  const [term, setTerm] = useState("");
  const debouncedTerm = useDebounced(term.trim(), 400);
  const [tag, setTag] = useState<RecipeTag | null>(null);
  const [mealType, setMealType] = useState<MealType | "">("");
  const [detail, setDetail] = useState<Recipe | null>(null);
  const [editor, setEditor] = useState<{ recipe: Recipe | null } | null>(null);
  const [toDelete, setToDelete] = useState<Recipe | null>(null);
  const [toAdd, setToAdd] = useState<Recipe | null>(null);

  const params = useMemo(
    () => ({
      q: debouncedTerm || undefined,
      mine: scope === "mine" ? (1 as const) : undefined,
      tag: tag ?? undefined,
      meal_type: mealType === "" ? undefined : mealType,
    }),
    [debouncedTerm, scope, tag, mealType],
  );

  const recipesQuery = useInfiniteQuery({
    queryKey: queryKeys.recipes.list(params),
    initialPageParam: 1,
    queryFn: ({ pageParam }) =>
      apiGet<PaginatedEnvelope<Recipe>>("/recipes", { ...params, page: pageParam, per_page: PER_PAGE }),
    getNextPageParam: (lastPage) => {
      const meta = lastPage.meta;
      if (!meta || meta.current_page >= meta.last_page) return undefined;
      return meta.current_page + 1;
    },
  });

  const recipes = useMemo(() => {
    const all = recipesQuery.data?.pages.flatMap((page) => page.data) ?? [];
    // « Publiques » n’a pas de paramètre serveur : on masque les recettes privées.
    return scope === "publiques" ? all.filter((recipe) => recipe.is_public) : all;
  }, [recipesQuery.data, scope]);

  const deleteMutation = useMutation({
    mutationFn: (id: number) => apiDelete(`/recipes/${id}`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.recipes.all });
      setDetail(null);
      success("Recette supprimée");
    },
  });

  function handleSaved(recipe: Recipe, message?: string) {
    setEditor(null);
    setDetail(recipe);
    void queryClient.invalidateQueries({ queryKey: queryKeys.recipes.all });
    success(message ?? "Recette enregistrée", recipe.title);
  }

  return (
    <div className="space-y-6">
      <SectionHeader
        level="page"
        tone="emerald"
        eyebrow="Nutrition"
        title="Recettes"
        subtitle="Tes recettes et celles de la communauté, avec les calories et les macros par portion."
        actions={
          <Button onClick={() => setEditor({ recipe: null })}>Nouvelle recette</Button>
        }
      />

      <div className="space-y-3">
        <div className="flex flex-wrap items-center gap-2" role="tablist" aria-label="Portée des recettes">
          {SCOPES.map((entry) => (
            <button
              key={entry.key}
              type="button"
              role="tab"
              aria-selected={scope === entry.key}
              onClick={() => setScope(entry.key)}
              className={cn(
                "h-10 rounded-full border px-4 text-sm font-medium transition",
                scope === entry.key
                  ? "border-emerald-700 bg-emerald-700 text-white"
                  : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50",
              )}
            >
              {entry.label}
            </button>
          ))}
        </div>

        <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_16rem]">
          <div>
            <label htmlFor="recipe-search" className="mb-1.5 block text-sm font-medium text-slate-700">
              Rechercher une recette
            </label>
            <input
              id="recipe-search"
              type="search"
              value={term}
              onChange={(event) => setTerm(event.target.value)}
              placeholder="Ex. : curry de lentilles"
              autoComplete="off"
              className="h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-lime-600 focus:ring-4 focus:ring-lime-600/10"
            />
          </div>
          <div>
            <label htmlFor="recipe-meal-type" className="mb-1.5 block text-sm font-medium text-slate-700">
              Moment de la journée
            </label>
            <select
              id="recipe-meal-type"
              value={mealType}
              onChange={(event) => setMealType(event.target.value as MealType | "")}
              className="h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition focus:border-lime-600 focus:ring-4 focus:ring-lime-600/10"
            >
              <option value="">Tous les repas</option>
              {MEAL_TYPES.map((type) => (
                <option key={type} value={type}>
                  {MEAL_TYPE_LABELS[type]}
                </option>
              ))}
            </select>
          </div>
        </div>

        <div className="flex flex-wrap gap-1.5" role="group" aria-label="Filtrer par tag">
          {TAGS.map((entry) => {
            const active = tag === entry;
            return (
              <button
                key={entry}
                type="button"
                aria-pressed={active}
                onClick={() => setTag(active ? null : entry)}
                className={cn(
                  "h-10 rounded-full border px-4 text-sm font-medium transition",
                  active
                    ? "border-slate-900 bg-slate-900 text-white"
                    : "border-slate-200 bg-white text-slate-600 hover:bg-slate-50",
                )}
              >
                {RECIPE_TAG_LABELS[entry]}
              </button>
            );
          })}
        </div>
      </div>

      {recipesQuery.isPending ? (
        <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
          {Array.from({ length: 6 }).map((_, index) => (
            <Skeleton key={index} className="h-64 w-full rounded-[1.75rem]" />
          ))}
        </div>
      ) : recipesQuery.isError ? (
        <ErrorState
          message={getErrorMessage(recipesQuery.error)}
          onRetry={() => void recipesQuery.refetch()}
          retrying={recipesQuery.isFetching}
        />
      ) : recipes.length === 0 ? (
        <EmptyState
          title="Aucune recette"
          message="Crée ta première recette : Mavi’oh calculera les calories par portion et pourra l’ajouter à tes repas."
          action={<Button onClick={() => setEditor({ recipe: null })}>Nouvelle recette</Button>}
        />
      ) : (
        <>
          <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
            {recipes.map((recipe) => (
              <RecipeCard
                key={recipe.id}
                recipe={recipe}
                onOpen={() => setDetail(recipe)}
                onAddToMeal={() => setToAdd(recipe)}
                onEdit={() => setEditor({ recipe })}
                onDelete={() => setToDelete(recipe)}
              />
            ))}
          </div>
          {recipesQuery.hasNextPage ? (
            <div className="flex justify-center">
              <Button
                variant="secondary"
                onClick={() => void recipesQuery.fetchNextPage()}
                loading={recipesQuery.isFetchingNextPage}
              >
                Voir plus
              </Button>
            </div>
          ) : null}
        </>
      )}

      <Modal open={detail !== null} onClose={() => setDetail(null)} title={detail?.title} size="lg">
        {detail ? (
          <div className="space-y-4">
            <RecipeThumb recipe={detail} className="h-48 w-full" />
            <div className="flex flex-wrap items-center gap-1.5">
              {(detail.tags ?? []).map((entry) => (
                <Pill key={entry} tone="lime">
                  {RECIPE_TAG_LABELS[entry] ?? entry}
                </Pill>
              ))}
              {(detail.meal_types ?? []).map((type) => (
                <Pill key={type} tone="sky">
                  {MEAL_TYPE_LABELS[type]}
                </Pill>
              ))}
              {detail.is_estimate ? <EstimatePill /> : null}
            </div>
            <p className="text-sm text-slate-600">
              {detail.prep_time_minutes ? `${formatMinutes(detail.prep_time_minutes)} de préparation · ` : ""}
              {detail.servings} portion{detail.servings > 1 ? "s" : ""} · {formatKcal(perServingCalories(detail))} par
              portion
            </p>
            <div className="flex flex-wrap items-center gap-1.5">
              <Pill tone="slate">
                P {detail.per_serving?.proteins === null || detail.per_serving?.proteins === undefined ? "—" : formatGrams(detail.per_serving.proteins)}
              </Pill>
              <Pill tone="slate">
                G {detail.per_serving?.carbs === null || detail.per_serving?.carbs === undefined ? "—" : formatGrams(detail.per_serving.carbs)}
              </Pill>
              <Pill tone="slate">
                L {detail.per_serving?.fat === null || detail.per_serving?.fat === undefined ? "—" : formatGrams(detail.per_serving.fat)}
              </Pill>
            </div>
            {detail.description ? (
              <p className="whitespace-pre-line text-sm leading-6 text-slate-700">{detail.description}</p>
            ) : null}
            <div>
              <h3 className="mb-2 text-sm font-semibold text-slate-900">Ingrédients</h3>
              {detail.ingredients.length === 0 ? (
                <p className="text-sm text-slate-500">Aucun ingrédient enregistré.</p>
              ) : (
                <ul className="space-y-1 text-sm text-slate-700">
                  {detail.ingredients.map((ingredient, index) => (
                    <li key={`${ingredient.name}-${index}`} className="flex flex-wrap items-baseline gap-2">
                      <span className="font-medium">{ingredient.name}</span>
                      {ingredient.amount !== null ? (
                        <span className="text-slate-500">
                          {ingredient.amount} {ingredient.unit ?? ""}
                        </span>
                      ) : null}
                      {ingredient.ean ? <span className="text-xs text-slate-400">{ingredient.ean}</span> : null}
                    </li>
                  ))}
                </ul>
              )}
            </div>
            <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
              <Button onClick={() => setToAdd(detail)}>Ajouter à un repas</Button>
              {detail.is_owner ? (
                <>
                  <Button variant="secondary" onClick={() => setEditor({ recipe: detail })}>
                    Modifier
                  </Button>
                  <Button variant="danger" onClick={() => setToDelete(detail)}>
                    Supprimer
                  </Button>
                </>
              ) : null}
            </div>
          </div>
        ) : null}
      </Modal>

      {editor ? (
        <RecipeEditor open onClose={() => setEditor(null)} recipe={editor.recipe} onSaved={handleSaved} />
      ) : null}

      <ConfirmDialog
        open={toDelete !== null}
        onClose={() => setToDelete(null)}
        title="Supprimer cette recette ?"
        message={toDelete ? `« ${toDelete.title} » sera supprimée définitivement.` : undefined}
        confirmLabel="Supprimer"
        danger
        onConfirm={async () => {
          if (toDelete) await deleteMutation.mutateAsync(toDelete.id);
        }}
      />

      <AddToMealDialog
        open={toAdd !== null}
        onClose={() => setToAdd(null)}
        preset={toAdd ? { kind: "recipe", recipe: toAdd } : null}
        mode="portionOnly"
      />
    </div>
  );
}

export default RecipesPage;
