"use client";

import { useRef, useState } from "react";
import Image from "next/image";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation } from "@tanstack/react-query";
import { useFieldArray, useForm } from "react-hook-form";
import { z } from "zod";
import { apiPost, apiPut, getErrorMessage, isApiError } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { formatGrams, formatKcal } from "@/lib/format";
import type {
  DataEnvelope,
  MealType,
  Recipe,
  RecipeEstimate,
  RecipeIngredient,
  RecipeInput,
  RecipeTag,
} from "@/lib/types/api";
import { MEAL_TYPES } from "@/lib/types/api";
import { MEAL_TYPE_LABELS, RECIPE_TAG_LABELS } from "@/lib/vocab";
import { decimalOptional, decimalRequired, numOrNull, textOrNull, toText } from "@/components/foods/form-helpers";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { CheckboxField, Field, TextareaField } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { EstimatePill } from "@/components/ui/pill";

/* ------------------------------------------------------------------ */
/* Schema                                                              */
/* ------------------------------------------------------------------ */

const ingredientSchema = z.object({
  uid: z.string(),
  name: z.string().trim().min(1, "Indique le nom de l’ingrédient."),
  ean: z
    .string()
    .refine((value) => value.trim() === "" || /^\d{8,14}$/.test(value.trim()), {
      message: "8 à 14 chiffres.",
    }),
  amount: decimalOptional,
  unit: z.string(),
});

const schema = z.object({
  title: z.string().trim().min(2, "Indique un titre d’au moins 2 caractères."),
  description: z.string(),
  prep_time_minutes: decimalOptional,
  servings: z.number().min(0.5, "Au moins une demi-portion.").max(50, "50 portions maximum."),
  calories: decimalRequired,
  proteins: decimalOptional,
  carbs: decimalOptional,
  fat: decimalOptional,
  tags: z.array(z.string()),
  meal_types: z.array(z.string()),
  is_public: z.boolean(),
  image_url: z.string(),
  ingredients: z.array(ingredientSchema),
});

type FormValues = z.infer<typeof schema>;

const TOP_LEVEL_FIELDS: (keyof FormValues)[] = [
  "title",
  "description",
  "prep_time_minutes",
  "servings",
  "calories",
  "proteins",
  "carbs",
  "fat",
  "tags",
  "meal_types",
  "is_public",
  "image_url",
  "ingredients",
];

const TAGS = Object.keys(RECIPE_TAG_LABELS) as RecipeTag[];
const UNITS = ["g", "ml", "piece", "cas", "cac", "tranche", "portion", "poignee"];

const MAX_IMAGE_BYTES = 350 * 1024;
const MAX_IMAGE_SIDE = 1200;
const IMAGE_QUALITY = 0.75;

function newUid(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") return crypto.randomUUID();
  return `ing-${Math.random().toString(36).slice(2)}-${Date.now()}`;
}

function emptyIngredient() {
  return { uid: newUid(), name: "", ean: "", amount: "", unit: "g" };
}

function dataUriBytes(dataUri: string): number {
  const base64 = dataUri.split(",")[1] ?? "";
  return Math.ceil((base64.length * 3) / 4);
}

/** Redimensionne la photo côté client : 1200 px maximum, qualité 0,75. */
async function resizeImage(file: File): Promise<string> {
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, MAX_IMAGE_SIDE / Math.max(bitmap.width, bitmap.height));
  const width = Math.max(1, Math.round(bitmap.width * scale));
  const height = Math.max(1, Math.round(bitmap.height * scale));
  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  const context = canvas.getContext("2d");
  if (!context) throw new Error("Impossible de préparer l’image.");
  context.drawImage(bitmap, 0, 0, width, height);
  bitmap.close();
  return canvas.toDataURL("image/jpeg", IMAGE_QUALITY);
}

function defaultsFor(recipe: Recipe | null | undefined): FormValues {
  return {
    title: recipe?.title ?? "",
    description: recipe?.description ?? "",
    prep_time_minutes: toText(recipe?.prep_time_minutes),
    servings: recipe?.servings ?? 1,
    calories: toText(recipe?.calories),
    proteins: toText(recipe?.proteins),
    carbs: toText(recipe?.carbs),
    fat: toText(recipe?.fat),
    tags: recipe?.tags ?? [],
    meal_types: recipe?.meal_types ?? [],
    is_public: recipe?.is_public ?? false,
    image_url: recipe?.image_url ?? "",
    ingredients:
      recipe?.ingredients && recipe.ingredients.length > 0
        ? recipe.ingredients.map((ingredient) => ({
            uid: newUid(),
            name: ingredient.name ?? "",
            ean: ingredient.ean ?? "",
            amount: toText(ingredient.amount),
            unit: ingredient.unit ?? "g",
          }))
        : [emptyIngredient()],
  };
}

export type RecipeEditorProps = {
  open: boolean;
  onClose: () => void;
  /** `null` → création. */
  recipe?: Recipe | null;
  onSaved: (recipe: Recipe, message?: string) => void;
};

/**
 * Éditeur de recette (brief §5) : champs séparés nom / code-barres par
 * ingrédient, estimation serveur des macros, photo redimensionnée en local.
 * Aucun appel réseau à la frappe, aucun appel direct à Open Food Facts.
 */
export function RecipeEditor({ open, onClose, recipe = null, onSaved }: RecipeEditorProps) {
  const [formError, setFormError] = useState<string | null>(null);
  const [imageError, setImageError] = useState<string | null>(null);
  const [imageBusy, setImageBusy] = useState(false);
  const [estimate, setEstimate] = useState<RecipeEstimate | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const {
    register,
    handleSubmit,
    control,
    setValue,
    setError,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultsFor(recipe),
  });

  const { fields, append, remove } = useFieldArray({ control, name: "ingredients" });

  const servings = watch("servings");
  const tags = watch("tags");
  const mealTypes = watch("meal_types");
  const imageUrl = watch("image_url");
  const ingredients = watch("ingredients");

  function toggleIn(list: string[], value: string): string[] {
    return list.includes(value) ? list.filter((entry) => entry !== value) : [...list, value];
  }

  function ingredientPayload(): RecipeIngredient[] {
    return (ingredients ?? [])
      .filter((ingredient) => ingredient.name.trim() !== "")
      .map((ingredient) => ({
        name: ingredient.name.trim(),
        ean: textOrNull(ingredient.ean),
        amount: numOrNull(ingredient.amount),
        unit: textOrNull(ingredient.unit) ?? "g",
      }));
  }

  const estimateMutation = useMutation({
    mutationFn: (payload: RecipeIngredient[]) =>
      apiPost<DataEnvelope<RecipeEstimate>>("/recipes/estimate", { ingredients: payload }),
    onSuccess: (response) => {
      const data = response.data;
      setEstimate(data);
      setValue("calories", toText(data.calories), { shouldDirty: true });
      setValue("proteins", toText(data.proteins), { shouldDirty: true });
      setValue("carbs", toText(data.carbs), { shouldDirty: true });
      setValue("fat", toText(data.fat), { shouldDirty: true });
    },
    onError: (error) => setFormError(getErrorMessage(error)),
  });

  async function handleImageFile(file: File | undefined) {
    if (!file) return;
    setImageError(null);
    setImageBusy(true);
    try {
      const dataUri = await resizeImage(file);
      if (dataUriBytes(dataUri) > MAX_IMAGE_BYTES) {
        setImageError("Photo trop lourde même après compression (350 Ko maximum). Choisis une image plus petite.");
        return;
      }
      setValue("image_url", dataUri, { shouldDirty: true });
    } catch (error) {
      setImageError(getErrorMessage(error, "Impossible de lire cette image."));
    } finally {
      setImageBusy(false);
      if (fileInputRef.current) fileInputRef.current.value = "";
    }
  }

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    const payload: RecipeInput = {
      title: values.title.trim(),
      description: textOrNull(values.description),
      prep_time_minutes: numOrNull(values.prep_time_minutes),
      calories: numOrNull(values.calories) ?? 0,
      image_url: textOrNull(values.image_url),
      ingredients: ingredientPayload(),
      is_public: values.is_public,
      servings: values.servings,
      proteins: numOrNull(values.proteins),
      carbs: numOrNull(values.carbs),
      fat: numOrNull(values.fat),
      tags: values.tags as RecipeTag[],
      meal_types: values.meal_types as MealType[],
    };

    try {
      const response = recipe
        ? await apiPut<DataEnvelope<Recipe>>(`/recipes/${recipe.id}`, payload)
        : await apiPost<DataEnvelope<Recipe>>("/recipes", payload);
      onSaved(response.data, response.message);
    } catch (error) {
      if (isApiError(error) && error.isValidation) {
        let mapped = false;
        for (const field of TOP_LEVEL_FIELDS) {
          const message = error.fieldError(field);
          if (message) {
            setError(field, { message });
            mapped = true;
          }
        }
        const rest = Object.entries(error.fieldErrors)
          .filter(([field]) => !(TOP_LEVEL_FIELDS as string[]).includes(field))
          .flatMap(([, list]) => list);
        if (rest.length > 0) setFormError(rest.join(" "));
        else if (!mapped) setFormError(error.message);
        return;
      }
      setFormError(getErrorMessage(error));
    }
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={recipe ? "Modifier la recette" : "Nouvelle recette"}
      size="xl"
      locked={isSubmitting}
    >
      <form onSubmit={onSubmit} noValidate className="space-y-5">
        {formError ? <Banner tone="error">{formError}</Banner> : null}

        <div className="grid gap-4 lg:grid-cols-2">
          <Field label="Titre" required autoComplete="off" error={errors.title?.message} {...register("title")} />
          <Field
            label="Temps de préparation (min)"
            inputMode="numeric"
            error={errors.prep_time_minutes?.message}
            {...register("prep_time_minutes")}
          />
        </div>

        <TextareaField
          label="Description"
          rows={3}
          placeholder="Les étapes, les astuces, ce que tu veux retenir."
          error={errors.description?.message}
          {...register("description")}
        />

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <div>
            <label htmlFor="recipe-servings" className="mb-1.5 block text-sm font-medium text-slate-700">
              Portions
            </label>
            <div className="flex items-stretch gap-2">
              <button
                type="button"
                onClick={() => setValue("servings", Math.max(0.5, Number((servings - 0.5).toFixed(1))), { shouldDirty: true })}
                aria-label="Diminuer le nombre de portions"
                className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl border border-slate-200 bg-white text-xl text-slate-700 transition hover:bg-slate-50"
              >
                −
              </button>
              <input
                id="recipe-servings"
                type="number"
                step="0.5"
                min="0.5"
                className="h-12 w-full min-w-0 rounded-2xl border border-slate-200 bg-white px-3 text-center text-lg font-semibold tabular-nums text-slate-900 outline-none focus:border-lime-600 focus:ring-4 focus:ring-lime-600/10"
                {...register("servings", { valueAsNumber: true })}
              />
              <button
                type="button"
                onClick={() => setValue("servings", Math.min(50, Number((servings + 0.5).toFixed(1))), { shouldDirty: true })}
                aria-label="Augmenter le nombre de portions"
                className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl border border-slate-200 bg-white text-xl text-slate-700 transition hover:bg-slate-50"
              >
                +
              </button>
            </div>
            {errors.servings ? (
              <p className="mt-1.5 text-xs font-medium text-rose-600" role="alert">
                {errors.servings.message}
              </p>
            ) : null}
          </div>
          <Field label="Calories (total)" required inputMode="decimal" error={errors.calories?.message} {...register("calories")} />
          <Field label="Protéines (g)" inputMode="decimal" error={errors.proteins?.message} {...register("proteins")} />
          <Field label="Glucides (g)" inputMode="decimal" error={errors.carbs?.message} {...register("carbs")} />
          <Field label="Lipides (g)" inputMode="decimal" error={errors.fat?.message} {...register("fat")} />
        </div>

        <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
          <Button
            type="button"
            variant="secondary"
            onClick={() => estimateMutation.mutate(ingredientPayload())}
            loading={estimateMutation.isPending}
            disabled={ingredientPayload().length === 0}
          >
            Estimer depuis les ingrédients
          </Button>
          {estimate ? (
            <>
              <EstimatePill />
              <span className="text-sm text-slate-600">
                {estimate.resolved_count}/{estimate.total_count} reconnus · {formatKcal(estimate.calories)} · P{" "}
                {formatGrams(estimate.proteins)} · G {formatGrams(estimate.carbs)} · L {formatGrams(estimate.fat)}
              </span>
            </>
          ) : (
            <span className="text-sm text-slate-500">
              Les macros sont calculées par le serveur à partir des ingrédients reconnus.
            </span>
          )}
        </div>

        <fieldset>
          <legend className="mb-2 text-sm font-medium text-slate-700">Tags</legend>
          <div className="flex flex-wrap gap-1.5">
            {TAGS.map((tag) => {
              const active = tags.includes(tag);
              return (
                <button
                  key={tag}
                  type="button"
                  aria-pressed={active}
                  onClick={() => setValue("tags", toggleIn(tags, tag), { shouldDirty: true })}
                  className={cn(
                    "h-10 rounded-full border px-4 text-sm font-medium transition",
                    active
                      ? "border-emerald-700 bg-emerald-700 text-white"
                      : "border-slate-200 bg-white text-slate-700 hover:bg-slate-50",
                  )}
                >
                  {RECIPE_TAG_LABELS[tag]}
                </button>
              );
            })}
          </div>
        </fieldset>

        <fieldset>
          <legend className="mb-2 text-sm font-medium text-slate-700">Moments de la journée</legend>
          <div className="flex flex-wrap gap-1.5">
            {MEAL_TYPES.map((type) => {
              const active = mealTypes.includes(type);
              return (
                <button
                  key={type}
                  type="button"
                  aria-pressed={active}
                  onClick={() => setValue("meal_types", toggleIn(mealTypes, type), { shouldDirty: true })}
                  className={cn(
                    "h-10 rounded-full border px-4 text-sm font-medium transition",
                    active
                      ? "border-emerald-700 bg-emerald-700 text-white"
                      : "border-slate-200 bg-white text-slate-700 hover:bg-slate-50",
                  )}
                >
                  {MEAL_TYPE_LABELS[type]}
                </button>
              );
            })}
          </div>
        </fieldset>

        <fieldset className="space-y-3">
          <legend className="text-sm font-medium text-slate-700">Ingrédients</legend>
          <div className="space-y-2">
            {fields.map((field, index) => (
              <div key={field.uid} className="grid gap-2 rounded-2xl border border-slate-200 bg-white p-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1.4fr)_minmax(0,0.8fr)_minmax(0,0.9fr)_auto]">
                <Field
                  label={index === 0 ? "Nom" : undefined}
                  aria-label={`Nom de l’ingrédient ${index + 1}`}
                  autoComplete="off"
                  error={errors.ingredients?.[index]?.name?.message}
                  {...register(`ingredients.${index}.name`)}
                />
                <Field
                  label={index === 0 ? "Code-barres" : undefined}
                  aria-label={`Code-barres de l’ingrédient ${index + 1}`}
                  inputMode="numeric"
                  autoComplete="off"
                  error={errors.ingredients?.[index]?.ean?.message}
                  {...register(`ingredients.${index}.ean`)}
                />
                <Field
                  label={index === 0 ? "Quantité" : undefined}
                  aria-label={`Quantité de l’ingrédient ${index + 1}`}
                  inputMode="decimal"
                  error={errors.ingredients?.[index]?.amount?.message}
                  {...register(`ingredients.${index}.amount`)}
                />
                <div>
                  {index === 0 ? (
                    <label htmlFor={`ingredient-unit-${field.id}`} className="mb-1.5 block text-sm font-medium text-slate-700">
                      Unité
                    </label>
                  ) : null}
                  <select
                    id={`ingredient-unit-${field.id}`}
                    aria-label={`Unité de l’ingrédient ${index + 1}`}
                    className="h-[3.25rem] w-full rounded-2xl border border-slate-200 bg-white px-3 text-slate-900 outline-none focus:border-lime-600 focus:ring-4 focus:ring-lime-600/10"
                    {...register(`ingredients.${index}.unit`)}
                  >
                    {UNITS.map((unit) => (
                      <option key={unit} value={unit}>
                        {unit}
                      </option>
                    ))}
                  </select>
                </div>
                <div className={cn("flex items-end", index === 0 && "sm:pb-0")}>
                  <Button
                    type="button"
                    variant="ghost"
                    onClick={() => remove(index)}
                    disabled={fields.length === 1}
                    aria-label={`Supprimer l’ingrédient ${index + 1}`}
                  >
                    Retirer
                  </Button>
                </div>
              </div>
            ))}
          </div>
          <Button type="button" variant="secondary" onClick={() => append(emptyIngredient())}>
            Ajouter un ingrédient
          </Button>
        </fieldset>

        <div className="space-y-3">
          <p className="text-sm font-medium text-slate-700">Photo</p>
          {imageError ? <Banner tone="warning">{imageError}</Banner> : null}
          <div className="flex flex-wrap items-center gap-3">
            {imageUrl ? (
              <span className="relative block h-24 w-24 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
                <Image src={imageUrl} alt="Aperçu de la recette" fill unoptimized sizes="96px" className="object-cover" />
              </span>
            ) : null}
            <input
              ref={fileInputRef}
              type="file"
              accept="image/*"
              aria-label="Choisir une photo"
              onChange={(event) => void handleImageFile(event.target.files?.[0])}
              className="block h-11 max-w-full text-sm text-slate-600 file:mr-3 file:h-11 file:rounded-2xl file:border file:border-slate-200 file:bg-white file:px-4 file:text-sm file:font-medium file:text-slate-700"
            />
            {imageBusy ? <span className="text-sm text-slate-500">Compression…</span> : null}
            {imageUrl ? (
              <Button type="button" variant="ghost" onClick={() => setValue("image_url", "", { shouldDirty: true })}>
                Retirer la photo
              </Button>
            ) : null}
          </div>
          <Field
            label="… ou l’adresse d’une image"
            placeholder="https://…"
            autoComplete="off"
            error={errors.image_url?.message}
            {...register("image_url")}
          />
          <p className="text-xs text-slate-500">
            La photo est réduite à 1 200 px (qualité 0,75) dans ton navigateur, 350 Ko maximum.
          </p>
        </div>

        <CheckboxField
          label="Publier cette recette pour la communauté"
          hint="Une recette publique demande une photo et au moins un ingrédient."
          error={errors.is_public?.message}
          {...register("is_public")}
        />

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
          <Button type="button" variant="secondary" onClick={onClose} disabled={isSubmitting}>
            Annuler
          </Button>
          <Button type="submit" loading={isSubmitting}>
            {recipe ? "Enregistrer" : "Créer la recette"}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

export default RecipeEditor;
