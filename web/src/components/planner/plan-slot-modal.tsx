"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useMutation } from "@tanstack/react-query";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field, TextareaField } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { RecipePicker, type RecipeOption } from "@/components/planner/recipe-picker";
import { apiPost, apiPut, getErrorMessage, isApiError } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { capitalize, formatDay, formatNumber } from "@/lib/format";
import type { DataEnvelope, MealPlan, MealPlanInput, MealType } from "@/lib/types/api";
import { MEAL_TYPE_LABELS, labelFor } from "@/lib/vocab";
import { useResetOnChange } from "@/lib/use-reset-on-change";

const MIN_SERVINGS = 0.5;
const MAX_SERVINGS = 20;

const planSchema = z.object({
  title: z.string().max(150, "150 caractères maximum."),
  notes: z.string().max(500, "500 caractères maximum."),
});

type PlanFormValues = z.infer<typeof planSchema>;

type Tab = "recipe" | "title";

export type PlanSlotModalProps = {
  open: boolean;
  onClose: () => void;
  date: string;
  mealType: MealType;
  /** Existing plan when editing; null when adding into an empty slot. */
  plan?: MealPlan | null;
  onSaved: (mode: "create" | "update") => void;
};

/**
 * Add or edit a planned meal: tabs « Recette » (search `GET /recipes?q=`) and
 * « Titre libre », a 0,5 portions stepper and free notes.
 * The modal never closes before a 2xx; 422 are mapped back onto the fields.
 */
export function PlanSlotModal({ open, onClose, date, mealType, plan = null, onSaved }: PlanSlotModalProps) {
  const isEdit = Boolean(plan);
  const [tab, setTab] = useState<Tab>(plan?.recipe_id ? "recipe" : "title");
  const [recipe, setRecipe] = useState<RecipeOption | null>(plan?.recipe ?? null);
  const [servings, setServings] = useState<number>(plan?.servings ?? 1);
  const [formError, setFormError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<PlanFormValues>({
    resolver: zodResolver(planSchema),
    defaultValues: { title: "", notes: "" },
    values: {
      title: plan && !plan.recipe_id ? plan.title : "",
      notes: plan?.notes ?? "",
    },
  });

  // Réamorçage à chaque ouverture sur un autre créneau : ajustement pendant le rendu
  // (React 19 interdit un setState synchrone dans un effet). Le formulaire lui-même est
  // piloté par la prop `values` de react-hook-form, qui se resynchronise toute seule.
  useResetOnChange(`${open}:${plan?.id ?? ""}`, () => {
    if (!open) return;
    setTab(plan?.recipe_id ? "recipe" : "title");
    setRecipe(plan?.recipe ?? null);
    setServings(plan?.servings ?? 1);
    setFormError(null);
  });

  const save = useMutation({
    mutationFn: (payload: MealPlanInput) =>
      isEdit && plan
        ? apiPut<DataEnvelope<MealPlan>>(`/planner/${plan.id}`, payload)
        : apiPost<DataEnvelope<MealPlan>>("/planner", payload),
    onSuccess: () => {
      onSaved(isEdit ? "update" : "create");
      onClose();
    },
    onError: (error) => {
      if (isApiError(error) && error.isValidation) {
        const titleError = error.fieldError("title");
        if (titleError) setError("title", { message: titleError });
        const notesError = error.fieldError("notes");
        if (notesError) setError("notes", { message: notesError });
        setFormError(
          error.fieldError("recipe_id") ??
            error.fieldError("servings") ??
            error.fieldError("date") ??
            error.fieldError("meal_type") ??
            (titleError || notesError ? null : error.message),
        );
        return;
      }
      setFormError(getErrorMessage(error));
    },
  });

  function onSubmit(values: PlanFormValues) {
    setFormError(null);
    if (tab === "recipe" && !recipe) {
      setFormError("Choisis une recette ou passe à l’onglet « Titre libre ».");
      return;
    }
    if (tab === "title" && !values.title.trim()) {
      setError("title", { message: "Donne un titre à ce repas." });
      return;
    }

    const base = { date, meal_type: mealType, servings, notes: values.notes.trim() || null };
    const payload: MealPlanInput =
      tab === "recipe" && recipe
        ? { ...base, recipe_id: recipe.id }
        : { ...base, title: values.title.trim(), recipe_id: null };

    save.mutate(payload);
  }

  function stepServings(delta: number) {
    setServings((current) => {
      const next = Math.round((current + delta) * 2) / 2;
      return Math.min(MAX_SERVINGS, Math.max(MIN_SERVINGS, next));
    });
  }

  const mealLabel = labelFor(MEAL_TYPE_LABELS, mealType);

  return (
    <Modal
      open={open}
      onClose={save.isPending ? () => {} : onClose}
      locked={save.isPending}
      title={isEdit ? "Modifier le repas prévu" : "Ajouter au planning"}
      description={`${mealLabel} · ${capitalize(formatDay(date))}`}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={save.isPending}>
            Annuler
          </Button>
          <Button type="submit" form="plan-slot-form" loading={save.isPending}>
            {isEdit ? "Enregistrer" : "Ajouter au planning"}
          </Button>
        </>
      }
    >
      <form id="plan-slot-form" noValidate onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        {formError ? <Banner tone="error">{formError}</Banner> : null}

        <div role="tablist" aria-label="Type de repas prévu" className="flex gap-1 rounded-2xl border border-slate-200 bg-slate-50 p-1">
          {(
            [
              { key: "recipe" as const, label: "Recette" },
              { key: "title" as const, label: "Titre libre" },
            ]
          ).map((option) => (
            <button
              key={option.key}
              type="button"
              role="tab"
              aria-selected={tab === option.key}
              onClick={() => {
                setTab(option.key);
                setFormError(null);
              }}
              className={cn(
                "inline-flex h-10 flex-1 items-center justify-center rounded-xl px-4 text-sm font-medium transition",
                tab === option.key
                  ? "bg-emerald-700 text-white"
                  : "text-slate-600 hover:bg-white hover:text-slate-900",
              )}
            >
              {option.label}
            </button>
          ))}
        </div>

        {tab === "recipe" ? (
          <RecipePicker
            value={recipe}
            onChange={setRecipe}
            mealType={mealType}
            hint="Cherche parmi tes recettes et celles de Mavi’oh."
            disabled={save.isPending}
          />
        ) : (
          <Field
            label="Titre du repas"
            placeholder="Ex. : poulet riz brocolis"
            autoComplete="off"
            error={errors.title?.message}
            disabled={save.isPending}
            {...register("title")}
          />
        )}

        <fieldset className="min-w-0">
          <legend className="mb-1.5 block text-sm font-medium text-slate-700">Portions</legend>
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => stepServings(-0.5)}
              disabled={save.isPending || servings <= MIN_SERVINGS}
              aria-label="Retirer une demi-portion"
              className="grid h-11 w-11 place-items-center rounded-2xl border border-slate-200 bg-white text-lg font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
            >
              −
            </button>
            <output
              aria-live="polite"
              className="min-w-[5rem] rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-center text-base font-semibold text-slate-900"
            >
              {formatNumber(servings, 1)}
            </output>
            <button
              type="button"
              onClick={() => stepServings(0.5)}
              disabled={save.isPending || servings >= MAX_SERVINGS}
              aria-label="Ajouter une demi-portion"
              className="grid h-11 w-11 place-items-center rounded-2xl border border-slate-200 bg-white text-lg font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
            >
              +
            </button>
            <p className="text-xs text-slate-500">Par pas de 0,5 portion.</p>
          </div>
        </fieldset>

        <TextareaField
          label="Notes (facultatif)"
          placeholder="Ex. : prévoir de sortir la viande la veille"
          rows={2}
          error={errors.notes?.message}
          disabled={save.isPending}
          {...register("notes")}
        />
      </form>
    </Modal>
  );
}

export default PlanSlotModal;
