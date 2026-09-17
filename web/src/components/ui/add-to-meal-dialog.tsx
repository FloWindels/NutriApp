"use client";

import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiDelete, apiGet, apiPost, apiPut, getErrorMessage, isApiError } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { formatGrams, formatKcal, formatNumber, formatQty, parseDecimal, todayIso } from "@/lib/format";
import { messages } from "@/lib/messages";
import { MEAL_MUTATION_KEYS, queryKeys } from "@/lib/query-keys";
import type {
  DaySummary,
  Food,
  FrequentItem,
  ListEnvelope,
  MealCreateInput,
  MealCreateResponse,
  MealItem,
  MealItemInput,
  MealType,
  PaginatedEnvelope,
  Recipe,
  StockConsumeResponse,
  StockItem,
  DataEnvelope,
} from "@/lib/types/api";
import { MEAL_TYPE_IN_SENTENCE, MEAL_TYPE_LABELS } from "@/lib/vocab";
import { unitLabel } from "@/lib/units";
import { usePortions } from "@/hooks/use-portions";
import { Banner } from "./banner";
import { Button } from "./button";
import { CheckboxField, Field } from "./field";
import { Modal } from "./modal";
import { EstimatePill, Pill } from "./pill";
import { QuantityUnitPicker, type QuantityUnitValue } from "./quantity-unit-picker";
import { SkeletonList } from "./skeleton";
import { useToast } from "./toast";

/* ------------------------------------------------------------------ */
/* Public API                                                          */
/* ------------------------------------------------------------------ */

export type AddToMealPreset =
  | { kind: "food"; food: Food }
  | { kind: "recipe"; recipe: Recipe }
  | { kind: "stock"; item: StockItem };

export type AddToMealResult = {
  day: DaySummary | null;
  item: MealItem | null;
  mealId: number | null;
};

export type AddToMealDialogProps = {
  open: boolean;
  onClose: () => void;
  /** ISO date; default today. */
  date?: string;
  /** Pre-selected meal type (usually `next_meal_type`). */
  mealType?: MealType;
  /** Skip the search step (food card, recipe card, recommendation, stock consume). */
  preset?: AddToMealPreset | null;
  /** `portionOnly` shows only type + picker + CTA (requires `preset`). */
  mode?: "search" | "portionOnly";
  onAdded?: (result: AddToMealResult) => void;
};

type Tab = "aliments" | "frequents" | "recettes" | "personnalise";

type Selection =
  | { kind: "food"; food: Food }
  | { kind: "recipe"; recipe: Recipe }
  | { kind: "stock"; item: StockItem }
  | { kind: "custom" }
  | { kind: "create-food"; barcode: string | null };

const MEAL_ORDER: MealType[] = ["petit_dejeuner", "dejeuner", "diner", "collation"];
const SEARCH_DEBOUNCE_MS = 350;
const BARCODE_RE = /^\d{8,14}$/;

function defaultMealType(): MealType {
  const hour = new Date().getHours();
  if (hour < 10) return "petit_dejeuner";
  if (hour < 15) return "dejeuner";
  if (hour < 18) return "collation";
  return "diner";
}

function initialValueFor(selection: Selection | null): QuantityUnitValue {
  if (!selection) return { quantity: 100, unit: "g" };
  if (selection.kind === "food") {
    return selection.food.serving_size_g ? { quantity: 1, unit: "portion" } : { quantity: 100, unit: "g" };
  }
  if (selection.kind === "recipe") return { quantity: 1, unit: "portion" };
  if (selection.kind === "stock") {
    const qty = selection.item.quantity;
    // Default to one unit when at least one is left, otherwise whatever remains.
    return { quantity: qty >= 1 ? 1 : qty > 0 ? qty : 1, unit: selection.item.unit };
  }
  return { quantity: 1, unit: "portion" };
}

/**
 * The single « add to meal » flow (brief §16.3, web version).
 * Search → pick → quantity → **one call** `POST /meals {date, type, items:[item]}` → toast with undo.
 */
export function AddToMealDialog({
  open,
  onClose,
  date,
  mealType,
  preset = null,
  mode = preset ? "portionOnly" : "search",
  onAdded,
}: AddToMealDialogProps) {
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const { portions } = usePortions();

  const effectiveDate = date ?? todayIso();
  const [type, setType] = useState<MealType>(mealType ?? defaultMealType());
  const [tab, setTab] = useState<Tab>("aliments");
  const [query, setQuery] = useState("");
  const [selection, setSelection] = useState<Selection | null>(null);
  const [value, setValue] = useState<QuantityUnitValue>(() => initialValueFor(null));
  const [decrementStock, setDecrementStock] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Reset when (re)opened.
  useEffect(() => {
    if (!open) return;
    setType(mealType ?? defaultMealType());
    setTab("aliments");
    setQuery("");
    setError(null);
    setDecrementStock(true);
    const initial: Selection | null = preset
      ? preset.kind === "food"
        ? { kind: "food", food: preset.food }
        : preset.kind === "recipe"
          ? { kind: "recipe", recipe: preset.recipe }
          : { kind: "stock", item: preset.item }
      : null;
    setSelection(initial);
    setValue(initialValueFor(initial));
  }, [open, mealType, preset]);

  function select(next: Selection) {
    setSelection(next);
    setValue(initialValueFor(next));
    setError(null);
  }

  function invalidateMealFamilies() {
    for (const key of MEAL_MUTATION_KEYS) {
      void queryClient.invalidateQueries({ queryKey: key });
    }
  }

  function successToast(item: MealItem | null, mealId: number | null, extraUndo?: () => Promise<void>) {
    const kcal = item ? formatKcal(item.calories) : "";
    toast({
      title: messages.addedToMeal(MEAL_TYPE_IN_SENTENCE[type], kcal || "ajouté"),
      tone: "success",
      action:
        item && mealId
          ? {
              label: messages.undo,
              onClick: async () => {
                try {
                  await apiDelete(`/meals/${mealId}/items/${item.id}`);
                  if (extraUndo) await extraUndo();
                } finally {
                  invalidateMealFamilies();
                  void queryClient.invalidateQueries({ queryKey: queryKeys.stocks.all });
                }
              },
            }
          : undefined,
    });
  }

  const addMutation = useMutation({
    mutationFn: async (input: MealItemInput) => {
      const body: MealCreateInput = { date: effectiveDate, type, items: [input] };
      return apiPost<MealCreateResponse>("/meals", body);
    },
    onSuccess: (response) => {
      const item = response.data.items.at(-1) ?? null;
      invalidateMealFamilies();
      if (response.stock_decrements?.length) {
        void queryClient.invalidateQueries({ queryKey: queryKeys.stocks.all });
      }
      successToast(item, response.data.id);
      onAdded?.({ day: response.day, item, mealId: response.data.id });
      onClose();
    },
    onError: (err) => setError(getErrorMessage(err)),
  });

  const consumeMutation = useMutation({
    mutationFn: async (input: { item: StockItem; quantity: number; unit: string; addToMeal: boolean }) =>
      apiPost<StockConsumeResponse>(`/stocks/items/${input.item.id}/consume`, {
        quantity: input.quantity,
        unit: input.unit,
        meal_type: type,
        date: effectiveDate,
        add_to_meal: input.addToMeal,
      }),
    onSuccess: (response, variables) => {
      invalidateMealFamilies();
      void queryClient.invalidateQueries({ queryKey: queryKeys.stocks.all });
      const mealItem = response.data.meal_item;
      const previous = response.data.stock_item.previous_quantity;
      if (mealItem) {
        // The consume payload is lite: fetch nothing, build a minimal item for the toast.
        const lite = {
          id: mealItem.id,
          meal_id: mealItem.meal_id,
          calories: mealItem.calories,
        } as MealItem;
        successToast(lite, mealItem.meal_id, async () => {
          await apiPut(`/stocks/items/${variables.item.id}`, { quantity: previous });
        });
      } else {
        toast({
          title: `Stock mis à jour · ${formatQty(response.data.stock_item.quantity, unitLabel(variables.unit, portions))} restant`,
          tone: "success",
          action: {
            label: messages.undo,
            onClick: async () => {
              await apiPut(`/stocks/items/${variables.item.id}`, { quantity: previous });
              void queryClient.invalidateQueries({ queryKey: queryKeys.stocks.all });
            },
          },
        });
      }
      onAdded?.({ day: null, item: null, mealId: mealItem?.meal_id ?? null });
      onClose();
    },
    onError: (err) => setError(getErrorMessage(err)),
  });

  const busy = addMutation.isPending || consumeMutation.isPending;

  function submit() {
    if (!selection) return;
    setError(null);
    if (value.quantity <= 0) {
      setError("Indique une quantité supérieure à zéro.");
      return;
    }
    if (selection.kind === "food") {
      addMutation.mutate({ food_id: selection.food.id, quantity: value.quantity, unit: value.unit });
    } else if (selection.kind === "recipe") {
      addMutation.mutate({ recipe_id: selection.recipe.id, quantity: value.quantity, unit: "portion" });
    } else if (selection.kind === "stock") {
      const item = selection.item;
      if (decrementStock) {
        consumeMutation.mutate({ item, quantity: value.quantity, unit: value.unit, addToMeal: item.food_id !== null });
      } else if (item.food_id !== null) {
        addMutation.mutate({ food_id: item.food_id, quantity: value.quantity, unit: value.unit, stock_item_id: item.id });
      }
    }
  }

  const stockSelection = selection?.kind === "stock" ? selection.item : null;
  const canSubmit =
    selection !== null &&
    selection.kind !== "custom" &&
    selection.kind !== "create-food" &&
    !(stockSelection && stockSelection.food_id === null && !decrementStock);

  const showSearch = mode === "search" && (!selection || selection.kind === "custom" || selection.kind === "create-food");

  return (
    <Modal
      open={open}
      onClose={busy ? () => {} : onClose}
      locked={busy}
      size="lg"
      title="Ajouter à un repas"
      description={
        selection && selection.kind !== "custom" && selection.kind !== "create-food"
          ? selectionTitle(selection)
          : "Cherche un aliment, une recette ou saisis un plat personnalisé."
      }
      bodyClassName="space-y-4"
      footer={
        selection && selection.kind !== "custom" && selection.kind !== "create-food" ? (
          <>
            {mode === "search" ? (
              <Button variant="ghost" onClick={() => setSelection(null)} disabled={busy}>
                {messages.back}
              </Button>
            ) : null}
            <Button onClick={submit} loading={busy} disabled={!canSubmit}>
              {messages.add}
            </Button>
          </>
        ) : undefined
      }
    >
      <MealTypeSegments value={type} onChange={setType} disabled={busy} />

      {error ? (
        <Banner tone="error" onClose={() => setError(null)}>
          {error}
        </Banner>
      ) : null}

      {showSearch ? (
        <SearchStep
          tab={tab}
          onTab={(next) => {
            setTab(next);
            setSelection(next === "personnalise" ? { kind: "custom" } : null);
          }}
          query={query}
          onQuery={setQuery}
          onPickFood={(food) => select({ kind: "food", food })}
          onPickRecipe={(recipe) => select({ kind: "recipe", recipe })}
          onPickFrequent={(item) => {
            setError(null);
            if (item.kind === "food") {
              addMutation.mutate({ food_id: item.id, quantity: item.last_quantity, unit: item.last_unit });
            } else {
              addMutation.mutate({ recipe_id: item.id, quantity: item.last_quantity, unit: "portion" });
            }
          }}
          onCreateFood={(barcode) => select({ kind: "create-food", barcode })}
          busy={busy}
          customPanel={
            selection?.kind === "custom" ? (
              <CustomItemForm
                busy={busy}
                onSubmit={(input) => addMutation.mutate(input)}
              />
            ) : null
          }
          createFoodPanel={
            selection?.kind === "create-food" ? (
              <CreateFoodForm
                barcode={selection.barcode}
                busy={busy}
                onCreated={(food) => select({ kind: "food", food })}
                onCancel={() => setSelection(null)}
              />
            ) : null
          }
        />
      ) : null}

      {selection && (selection.kind === "food" || selection.kind === "recipe" || selection.kind === "stock") ? (
        <QuantityStep
          selection={selection}
          value={value}
          onChange={setValue}
          portions={portions}
          decrementStock={decrementStock}
          onDecrementStock={setDecrementStock}
          disabled={busy}
        />
      ) : null}
    </Modal>
  );
}

export default AddToMealDialog;

/* ------------------------------------------------------------------ */
/* Sub-components                                                      */
/* ------------------------------------------------------------------ */

function selectionTitle(selection: Selection): string {
  if (selection.kind === "food") {
    return [selection.food.name, selection.food.brand].filter(Boolean).join(" · ");
  }
  if (selection.kind === "recipe") return selection.recipe.title;
  if (selection.kind === "stock") {
    return `${selection.item.food_name} · ${formatQty(selection.item.quantity, selection.item.unit)} en stock`;
  }
  return "";
}

function MealTypeSegments({
  value,
  onChange,
  disabled,
}: {
  value: MealType;
  onChange: (type: MealType) => void;
  disabled?: boolean;
}) {
  return (
    <div role="radiogroup" aria-label="Repas" className="grid grid-cols-4 gap-1 rounded-2xl bg-slate-100 p-1">
      {MEAL_ORDER.map((mealType) => (
        <button
          key={mealType}
          type="button"
          role="radio"
          aria-checked={value === mealType}
          disabled={disabled}
          onClick={() => onChange(mealType)}
          className={cn(
            "h-10 truncate rounded-xl px-2 text-xs font-semibold transition sm:text-sm",
            value === mealType ? "bg-white text-emerald-800 shadow-sm" : "text-slate-600 hover:text-slate-900",
          )}
        >
          {MEAL_TYPE_LABELS[mealType]}
        </button>
      ))}
    </div>
  );
}

type SearchStepProps = {
  tab: Tab;
  onTab: (tab: Tab) => void;
  query: string;
  onQuery: (query: string) => void;
  onPickFood: (food: Food) => void;
  onPickRecipe: (recipe: Recipe) => void;
  onPickFrequent: (item: FrequentItem) => void;
  onCreateFood: (barcode: string | null) => void;
  busy: boolean;
  customPanel: ReactNode;
  createFoodPanel: ReactNode;
};

const TABS: { key: Tab; label: string }[] = [
  { key: "aliments", label: "Aliments" },
  { key: "frequents", label: "Fréquents" },
  { key: "recettes", label: "Recettes" },
  { key: "personnalise", label: "Personnalisé" },
];

function SearchStep({
  tab,
  onTab,
  query,
  onQuery,
  onPickFood,
  onPickRecipe,
  onPickFrequent,
  onCreateFood,
  busy,
  customPanel,
  createFoodPanel,
}: SearchStepProps) {
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-1.5" role="tablist" aria-label="Source">
        {TABS.map((item) => (
          <button
            key={item.key}
            type="button"
            role="tab"
            aria-selected={tab === item.key}
            onClick={() => onTab(item.key)}
            className={cn(
              "h-9 rounded-full border px-3 text-sm font-medium transition",
              tab === item.key
                ? "border-slate-900 bg-slate-900 text-white"
                : "border-slate-200 bg-white text-slate-700 hover:bg-slate-50",
            )}
          >
            {item.label}
          </button>
        ))}
      </div>

      {createFoodPanel ? (
        createFoodPanel
      ) : tab === "personnalise" ? (
        customPanel
      ) : tab === "frequents" ? (
        <FrequentList onPick={onPickFrequent} busy={busy} />
      ) : tab === "recettes" ? (
        <RecipeSearch query={query} onQuery={onQuery} onPick={onPickRecipe} />
      ) : (
        <FoodSearch query={query} onQuery={onQuery} onPick={onPickFood} onPickFrequent={onPickFrequent} onCreateFood={onCreateFood} busy={busy} />
      )}
    </div>
  );
}

function useDebounced<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);
  return debounced;
}

function FoodSearch({
  query,
  onQuery,
  onPick,
  onPickFrequent,
  onCreateFood,
  busy,
}: {
  query: string;
  onQuery: (q: string) => void;
  onPick: (food: Food) => void;
  onPickFrequent: (item: FrequentItem) => void;
  onCreateFood: (barcode: string | null) => void;
  busy: boolean;
}) {
  const debounced = useDebounced(query.trim(), SEARCH_DEBOUNCE_MS);
  const requestId = useRef(0);
  const [results, setResults] = useState<Food[]>([]);
  const [loading, setLoading] = useState(false);
  const [searchError, setSearchError] = useState<string | null>(null);
  const [barcodeMiss, setBarcodeMiss] = useState<string | null>(null);
  const isBarcode = BARCODE_RE.test(debounced);

  useEffect(() => {
    const term = debounced;
    setBarcodeMiss(null);
    if (term.length < 2) {
      setResults([]);
      setLoading(false);
      setSearchError(null);
      return;
    }
    const id = ++requestId.current;
    setLoading(true);
    setSearchError(null);

    const run = async () => {
      try {
        if (BARCODE_RE.test(term)) {
          try {
            const response = await apiGet<DataEnvelope<Food>>(`/foods/barcode/${encodeURIComponent(term)}`);
            if (requestId.current !== id) return;
            setResults(response.data ? [response.data] : []);
          } catch (err) {
            if (requestId.current !== id) return;
            if (isApiError(err) && err.status === 404) {
              setResults([]);
              setBarcodeMiss(term);
            } else {
              throw err;
            }
          }
        } else {
          const response = await apiGet<PaginatedEnvelope<Food>>("/foods/search", { q: term, off: 1, per_page: 20 });
          if (requestId.current !== id) return;
          setResults(response.data ?? []);
        }
      } catch (err) {
        if (requestId.current !== id) return;
        setSearchError(getErrorMessage(err));
      } finally {
        if (requestId.current === id) setLoading(false);
      }
    };
    void run();
  }, [debounced]);

  return (
    <div className="space-y-3">
      <Field
        autoFocus
        type="search"
        inputMode="search"
        placeholder="Nom, marque ou code-barres"
        value={query}
        onChange={(event) => onQuery(event.target.value)}
        aria-label="Rechercher un aliment"
        autoComplete="off"
      />

      {debounced.length < 2 ? (
        <>
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Fréquents</p>
          <FrequentList onPick={onPickFrequent} busy={busy} compact />
        </>
      ) : loading ? (
        <SkeletonList rows={4} />
      ) : searchError ? (
        <Banner tone="error">{searchError}</Banner>
      ) : results.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-slate-200 p-4 text-center text-sm text-slate-500">
          <p>{barcodeMiss ? messages.foodNotFound : messages.noResult}</p>
          <Button variant="secondary" size="sm" className="mt-3" onClick={() => onCreateFood(isBarcode ? debounced : null)}>
            {messages.createFood}
          </Button>
        </div>
      ) : (
        <ul className="max-h-80 space-y-1 overflow-y-auto pr-1">
          {results.map((food) => (
            <li key={food.id}>
              <FoodRow food={food} onClick={() => onPick(food)} />
            </li>
          ))}
          <li className="pt-1">
            <button
              type="button"
              onClick={() => onCreateFood(isBarcode ? debounced : null)}
              className="w-full rounded-2xl border border-dashed border-slate-200 px-4 py-2 text-sm text-slate-500 transition hover:bg-slate-50"
            >
              Pas le bon produit ? {messages.createFood}
            </button>
          </li>
        </ul>
      )}
    </div>
  );
}

function FoodRow({ food, onClick }: { food: Food; onClick: () => void }) {
  const sourcePill =
    food.is_verified ? (
      <Pill tone="emerald">Vérifié</Pill>
    ) : food.source_type === "open_food_facts" ? (
      <Pill tone="sky">Open Food Facts</Pill>
    ) : (
      <Pill tone="slate">Communauté</Pill>
    );
  return (
    <button
      type="button"
      onClick={onClick}
      className="flex w-full items-center gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-left transition hover:border-emerald-300 hover:bg-emerald-50/40"
    >
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-semibold text-slate-900">{food.name}</span>
        <span className="block truncate text-xs text-slate-500">
          {[food.brand, `${formatKcal(food.calories)} / ${food.per_unit === "100ml" ? "100 ml" : "100 g"}`]
            .filter(Boolean)
            .join(" · ")}
        </span>
      </span>
      <span className="flex shrink-0 items-center gap-1.5">
        {food.is_estimate ? <EstimatePill /> : null}
        {sourcePill}
      </span>
    </button>
  );
}

function FrequentList({ onPick, busy, compact = false }: { onPick: (item: FrequentItem) => void; busy: boolean; compact?: boolean }) {
  const query = useQuery({
    queryKey: queryKeys.meals.frequent,
    queryFn: () => apiGet<ListEnvelope<FrequentItem>>("/meals/frequent"),
    staleTime: 60_000,
  });

  if (query.isPending) return <SkeletonList rows={compact ? 3 : 5} />;
  if (query.isError) return <Banner tone="error">{getErrorMessage(query.error)}</Banner>;
  const items = query.data.data;
  if (items.length === 0) {
    return (
      <p className="rounded-2xl border border-dashed border-slate-200 px-4 py-3 text-sm text-slate-500">
        Tes aliments les plus fréquents apparaîtront ici.
      </p>
    );
  }
  return (
    <ul className={cn("space-y-1 overflow-y-auto pr-1", compact ? "max-h-56" : "max-h-80")}>
      {items.map((item) => {
        const kcal =
          item.kind === "recipe"
            ? item.calories_per_serving != null
              ? `${formatKcal(item.calories_per_serving)} / portion`
              : null
            : item.calories_per_100g != null
              ? `${formatKcal(item.calories_per_100g)} / 100 g`
              : null;
        return (
          <li key={`${item.kind}-${item.id}`}>
            <button
              type="button"
              disabled={busy}
              onClick={() => onPick(item)}
              className="flex w-full items-center gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-left transition hover:border-emerald-300 hover:bg-emerald-50/40 disabled:opacity-60"
            >
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-semibold text-slate-900">{item.label}</span>
                <span className="block truncate text-xs text-slate-500">
                  {[item.brand, kcal, `${formatQty(item.last_quantity, unitLabel(item.last_unit))} la dernière fois`]
                    .filter(Boolean)
                    .join(" · ")}
                </span>
              </span>
              <Pill tone={item.kind === "recipe" ? "emerald" : "slate"}>{item.kind === "recipe" ? "Recette" : `×${item.count}`}</Pill>
            </button>
          </li>
        );
      })}
    </ul>
  );
}

function RecipeSearch({ query, onQuery, onPick }: { query: string; onQuery: (q: string) => void; onPick: (recipe: Recipe) => void }) {
  const debounced = useDebounced(query.trim(), SEARCH_DEBOUNCE_MS);
  const recipes = useQuery({
    queryKey: queryKeys.recipes.list({ q: debounced, per_page: 30, scope: "add-to-meal" }),
    queryFn: () => apiGet<PaginatedEnvelope<Recipe>>("/recipes", { q: debounced || undefined, per_page: 30 }),
    staleTime: 60_000,
  });

  return (
    <div className="space-y-3">
      <Field
        autoFocus
        type="search"
        placeholder="Nom de la recette"
        value={query}
        onChange={(event) => onQuery(event.target.value)}
        aria-label="Rechercher une recette"
        autoComplete="off"
      />
      {recipes.isPending ? (
        <SkeletonList rows={4} />
      ) : recipes.isError ? (
        <Banner tone="error">{getErrorMessage(recipes.error)}</Banner>
      ) : recipes.data.data.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-slate-200 px-4 py-3 text-sm text-slate-500">{messages.noResult}</p>
      ) : (
        <ul className="max-h-80 space-y-1 overflow-y-auto pr-1">
          {recipes.data.data.map((recipe) => (
            <li key={recipe.id}>
              <button
                type="button"
                onClick={() => onPick(recipe)}
                className="flex w-full items-center gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-left transition hover:border-emerald-300 hover:bg-emerald-50/40"
              >
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-semibold text-slate-900">{recipe.title}</span>
                  <span className="block truncate text-xs text-slate-500">
                    {formatKcal(recipe.per_serving?.calories ?? recipe.calories)} / portion
                    {recipe.servings ? ` · ${formatNumber(recipe.servings, 1)} portion${recipe.servings > 1 ? "s" : ""}` : ""}
                  </span>
                </span>
                {recipe.is_estimate ? <EstimatePill /> : null}
                {recipe.is_owner ? <Pill tone="emerald">Ma recette</Pill> : null}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function QuantityStep({
  selection,
  value,
  onChange,
  portions,
  decrementStock,
  onDecrementStock,
  disabled,
}: {
  selection: Extract<Selection, { kind: "food" | "recipe" | "stock" }>;
  value: QuantityUnitValue;
  onChange: (value: QuantityUnitValue) => void;
  portions: ReturnType<typeof usePortions>["portions"];
  decrementStock: boolean;
  onDecrementStock: (checked: boolean) => void;
  disabled: boolean;
}) {
  if (selection.kind === "recipe") {
    return (
      <QuantityUnitPicker
        kind="recipe"
        value={value}
        onChange={onChange}
        portions={portions}
        perServing={selection.recipe.per_serving}
        disabled={disabled}
      />
    );
  }

  if (selection.kind === "food") {
    return (
      <QuantityUnitPicker
        kind="food"
        value={value}
        onChange={onChange}
        portions={portions}
        food={selection.food}
        disabled={disabled}
      />
    );
  }

  const item = selection.item;
  const food = item.food
    ? {
        calories: item.food.calories ?? undefined,
        proteins: item.food.proteins ?? undefined,
        carbs: item.food.carbs ?? undefined,
        fat: item.food.fat ?? undefined,
        serving_size_g: item.food.serving_size_g,
      }
    : null;

  return (
    <div className="space-y-3">
      <QuantityUnitPicker
        kind="stock"
        value={value}
        onChange={onChange}
        portions={portions}
        food={food}
        stockUnit={item.unit}
        maxQuantity={decrementStock ? item.quantity : null}
        disabled={disabled}
      />
      <CheckboxField
        label={messages.removeFromStock}
        checked={decrementStock}
        onChange={(event) => onDecrementStock(event.target.checked)}
        disabled={disabled}
        hint={item.food_id === null ? messages.stockNotLinked : `${formatQty(item.quantity, unitLabel(item.unit, portions))} disponible${item.stock_name ? ` · ${item.stock_name}` : ""}`}
      />
    </div>
  );
}

/* ------------------------------ custom item ------------------------------ */

type CustomDraft = { label: string; calories: string; proteins: string; carbs: string; fat: string };

function CustomItemForm({ busy, onSubmit }: { busy: boolean; onSubmit: (input: MealItemInput) => void }) {
  const [draft, setDraft] = useState<CustomDraft>({ label: "", calories: "", proteins: "", carbs: "", fat: "" });
  const [localError, setLocalError] = useState<string | null>(null);

  const numbers = useMemo(
    () => ({
      calories: parseDecimal(draft.calories),
      proteins: parseDecimal(draft.proteins) ?? 0,
      carbs: parseDecimal(draft.carbs) ?? 0,
      fat: parseDecimal(draft.fat) ?? 0,
    }),
    [draft],
  );

  function submit() {
    if (draft.label.trim().length < 2) {
      setLocalError("Donne un nom à ce plat (2 caractères minimum).");
      return;
    }
    if (numbers.calories === null || numbers.calories < 0) {
      setLocalError("Indique les calories du plat.");
      return;
    }
    setLocalError(null);
    onSubmit({
      custom: {
        label: draft.label.trim(),
        per_100g: false,
        calories: numbers.calories,
        proteins: numbers.proteins,
        carbs: numbers.carbs,
        fat: numbers.fat,
      },
      quantity: 1,
      unit: "portion",
    });
  }

  return (
    <form
      className="space-y-3"
      onSubmit={(event) => {
        event.preventDefault();
        submit();
      }}
    >
      <Field
        label="Nom du plat"
        placeholder="Ex. : lasagnes de mamie"
        value={draft.label}
        onChange={(event) => setDraft({ ...draft, label: event.target.value })}
        autoFocus
        required
      />
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Field label="Calories (kcal)" inputMode="decimal" placeholder="450" value={draft.calories} onChange={(event) => setDraft({ ...draft, calories: event.target.value })} required />
        <Field label="Protéines (g)" inputMode="decimal" placeholder="20" value={draft.proteins} onChange={(event) => setDraft({ ...draft, proteins: event.target.value })} />
        <Field label="Glucides (g)" inputMode="decimal" placeholder="50" value={draft.carbs} onChange={(event) => setDraft({ ...draft, carbs: event.target.value })} />
        <Field label="Lipides (g)" inputMode="decimal" placeholder="15" value={draft.fat} onChange={(event) => setDraft({ ...draft, fat: event.target.value })} />
      </div>
      <p className="text-xs text-slate-500">Valeurs pour la part que tu as mangée (pas pour 100 g).</p>
      {localError ? <Banner tone="error">{localError}</Banner> : null}
      <div className="flex justify-end">
        <Button type="submit" loading={busy}>
          {messages.add}
        </Button>
      </div>
    </form>
  );
}

/* ------------------------------ create food ------------------------------ */

type FoodDraft = { name: string; brand: string; calories: string; proteins: string; carbs: string; fat: string };

function CreateFoodForm({
  barcode,
  busy,
  onCreated,
  onCancel,
}: {
  barcode: string | null;
  busy: boolean;
  onCreated: (food: Food) => void;
  onCancel: () => void;
}) {
  const queryClient = useQueryClient();
  const [draft, setDraft] = useState<FoodDraft>({ name: "", brand: "", calories: "", proteins: "", carbs: "", fat: "" });
  const [localError, setLocalError] = useState<string | null>(null);

  const createMutation = useMutation({
    mutationFn: (input: Record<string, unknown>) => apiPost<DataEnvelope<Food>>("/foods", input),
    onSuccess: (response) => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.foods.all });
      onCreated(response.data);
    },
    onError: (err) => setLocalError(getErrorMessage(err)),
  });

  function submit() {
    const calories = parseDecimal(draft.calories);
    if (draft.name.trim().length < 2) {
      setLocalError("Le nom de l’aliment est requis.");
      return;
    }
    if (calories === null || calories < 0) {
      setLocalError("Indique les calories pour 100 g.");
      return;
    }
    setLocalError(null);
    createMutation.mutate({
      barcode: barcode ?? null,
      name: draft.name.trim(),
      brand: draft.brand.trim() || null,
      calories,
      proteins: parseDecimal(draft.proteins) ?? 0,
      carbs: parseDecimal(draft.carbs) ?? 0,
      fat: parseDecimal(draft.fat) ?? 0,
      source_type: "manual",
    });
  }

  const pending = busy || createMutation.isPending;

  return (
    <form
      className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50/60 p-4"
      onSubmit={(event) => {
        event.preventDefault();
        submit();
      }}
    >
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm font-semibold text-slate-900">{messages.createFood}</p>
        {barcode ? <Pill tone="slate">EAN {barcode}</Pill> : null}
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Nom" placeholder="Ex. : yaourt nature" value={draft.name} onChange={(event) => setDraft({ ...draft, name: event.target.value })} autoFocus required />
        <Field label="Marque" placeholder="Facultatif" value={draft.brand} onChange={(event) => setDraft({ ...draft, brand: event.target.value })} />
      </div>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Field label="kcal / 100 g" inputMode="decimal" value={draft.calories} onChange={(event) => setDraft({ ...draft, calories: event.target.value })} required />
        <Field label="Protéines (g)" inputMode="decimal" value={draft.proteins} onChange={(event) => setDraft({ ...draft, proteins: event.target.value })} />
        <Field label="Glucides (g)" inputMode="decimal" value={draft.carbs} onChange={(event) => setDraft({ ...draft, carbs: event.target.value })} />
        <Field label="Lipides (g)" inputMode="decimal" value={draft.fat} onChange={(event) => setDraft({ ...draft, fat: event.target.value })} />
      </div>
      {localError ? <Banner tone="error">{localError}</Banner> : null}
      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={onCancel} disabled={pending}>
          {messages.cancel}
        </Button>
        <Button type="submit" loading={pending}>
          {messages.createAndAdd}
        </Button>
      </div>
    </form>
  );
}

/** Small helper for consumers that need a compact label (« 150 g · 210 kcal »). */
export function describeMealItem(item: MealItem, portions?: ReturnType<typeof usePortions>["portions"]): string {
  const qty = formatQty(item.quantity, unitLabel(item.unit, portions));
  const grams = item.grams_equivalent ? ` (≈ ${formatGrams(item.grams_equivalent)})` : "";
  return `${qty}${grams} · ${formatKcal(item.calories)}`;
}
