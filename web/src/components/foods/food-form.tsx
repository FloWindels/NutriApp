"use client";

import { useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { apiPost, apiPut, getErrorMessage, isApiError } from "@/lib/api-client";
import type { DataEnvelope, Food, FoodInput } from "@/lib/types/api";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { decimalOptional, numOrNull, textOrNull, toText } from "./form-helpers";

/**
 * Create (`POST /foods`) or edit (`PUT /foods/{id}`) a food.
 * The barcode is optional (brief §3.2): a food may exist without one.
 * A 200 answer carrying « Produit déjà présent dans la base. » is passed to
 * `onSaved` with its message so the caller can show the existing food.
 */

const schema = z.object({
  name: z.string().trim().min(2, "Indique un nom d’au moins 2 caractères."),
  brand: z.string(),
  barcode: z
    .string()
    .refine((value) => value.trim() === "" || /^\d{8,14}$/.test(value.trim()), {
      message: "Le code-barres doit contenir 8 à 14 chiffres.",
    }),
  image_url: z
    .string()
    .refine((value) => value.trim() === "" || value.trim().length <= 2048, {
      message: "Adresse d’image trop longue.",
    }),
  calories: decimalOptional,
  proteins: decimalOptional,
  carbs: decimalOptional,
  fat: decimalOptional,
  fiber: decimalOptional,
  sugar: decimalOptional,
  salt: decimalOptional,
  serving_size_g: decimalOptional,
  serving_label: z.string(),
  category: z.string(),
});

type FormValues = z.infer<typeof schema>;

const FIELDS: (keyof FormValues)[] = [
  "name",
  "brand",
  "barcode",
  "image_url",
  "calories",
  "proteins",
  "carbs",
  "fat",
  "fiber",
  "sugar",
  "salt",
  "serving_size_g",
  "serving_label",
  "category",
];

export type FoodFormProps = {
  mode: "create" | "edit";
  /** Existing food in `edit` mode (also used to prefill the form). */
  food?: Food | null;
  /** Prefilled barcode when the search term was an EAN with no result. */
  defaultBarcode?: string | null;
  /** Compact layout for the mini form inside another dialog. */
  compact?: boolean;
  onSaved: (food: Food, message?: string) => void;
  onCancel?: () => void;
};

function defaultsFor(food: Food | null | undefined, barcode: string | null | undefined): FormValues {
  return {
    name: food?.name ?? "",
    brand: food?.brand ?? "",
    barcode: food?.barcode ?? barcode ?? "",
    image_url: food?.image_url ?? "",
    calories: toText(food?.calories),
    proteins: toText(food?.proteins),
    carbs: toText(food?.carbs),
    fat: toText(food?.fat),
    fiber: toText(food?.fiber),
    sugar: toText(food?.sugar),
    salt: toText(food?.salt),
    serving_size_g: toText(food?.serving_size_g),
    serving_label: food?.serving_label ?? "",
    category: food?.category ?? "",
  };
}

export function FoodForm({ mode, food, defaultBarcode, compact = false, onSaved, onCancel }: FoodFormProps) {
  const [formError, setFormError] = useState<string | null>(null);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultsFor(food, defaultBarcode),
  });

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    const payload: FoodInput = {
      name: values.name.trim(),
      brand: textOrNull(values.brand),
      barcode: textOrNull(values.barcode),
      image_url: textOrNull(values.image_url),
      calories: numOrNull(values.calories),
      proteins: numOrNull(values.proteins),
      carbs: numOrNull(values.carbs),
      fat: numOrNull(values.fat),
      fiber: numOrNull(values.fiber),
      sugar: numOrNull(values.sugar),
      salt: numOrNull(values.salt),
      serving_size_g: numOrNull(values.serving_size_g),
      serving_label: textOrNull(values.serving_label),
      category: textOrNull(values.category),
    };

    try {
      const response =
        mode === "edit" && food
          ? await apiPut<DataEnvelope<Food>>(`/foods/${food.id}`, payload)
          : await apiPost<DataEnvelope<Food>>("/foods", payload);
      onSaved(response.data, response.message);
    } catch (error) {
      if (isApiError(error) && error.isValidation) {
        let mapped = false;
        for (const field of FIELDS) {
          const message = error.fieldError(field);
          if (message) {
            setError(field, { message });
            mapped = true;
          }
        }
        if (!mapped) setFormError(error.message);
        return;
      }
      setFormError(getErrorMessage(error));
    }
  });

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      {formError ? <Banner tone="error">{formError}</Banner> : null}

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          label="Nom"
          required
          placeholder="Ex. : yaourt nature"
          autoComplete="off"
          error={errors.name?.message}
          {...register("name")}
        />
        <Field label="Marque" placeholder="Ex. : Bio Village" autoComplete="off" error={errors.brand?.message} {...register("brand")} />
        <Field
          label="Code-barres"
          hint="Facultatif — 8 à 14 chiffres."
          inputMode="numeric"
          placeholder="3256540000000"
          autoComplete="off"
          error={errors.barcode?.message}
          {...register("barcode")}
        />
        <Field
          label="Catégorie"
          hint="Facultatif — aide à estimer une portion (yaourt, pain, fruit…)."
          autoComplete="off"
          error={errors.category?.message}
          {...register("category")}
        />
      </div>

      <fieldset className="space-y-3">
        <legend className="text-sm font-medium text-slate-700">Valeurs pour 100 g (ou 100 ml)</legend>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Calories (kcal)" inputMode="decimal" error={errors.calories?.message} {...register("calories")} />
          <Field label="Protéines (g)" inputMode="decimal" error={errors.proteins?.message} {...register("proteins")} />
          <Field label="Glucides (g)" inputMode="decimal" error={errors.carbs?.message} {...register("carbs")} />
          <Field label="Lipides (g)" inputMode="decimal" error={errors.fat?.message} {...register("fat")} />
        </div>
      </fieldset>

      {compact ? null : (
        <>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Fibres (g)" inputMode="decimal" error={errors.fiber?.message} {...register("fiber")} />
            <Field label="Sucres (g)" inputMode="decimal" error={errors.sugar?.message} {...register("sugar")} />
            <Field label="Sel (g)" inputMode="decimal" error={errors.salt?.message} {...register("salt")} />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              label="Portion (g)"
              hint="Poids d’une portion ou d’une pièce."
              inputMode="decimal"
              error={errors.serving_size_g?.message}
              {...register("serving_size_g")}
            />
            <Field
              label="Libellé de la portion"
              placeholder="Ex. : 1 pot de 125 g"
              autoComplete="off"
              error={errors.serving_label?.message}
              {...register("serving_label")}
            />
          </div>

          <Field
            label="Image (adresse)"
            hint="Facultatif — l’adresse d’une photo du produit."
            placeholder="https://…"
            autoComplete="off"
            error={errors.image_url?.message}
            {...register("image_url")}
          />
        </>
      )}

      <div className="flex flex-wrap items-center justify-end gap-2">
        {onCancel ? (
          <Button type="button" variant="secondary" onClick={onCancel} disabled={isSubmitting}>
            Annuler
          </Button>
        ) : null}
        <Button type="submit" loading={isSubmitting}>
          {mode === "edit" ? "Enregistrer" : "Créer l’aliment"}
        </Button>
      </div>
    </form>
  );
}

export default FoodForm;
