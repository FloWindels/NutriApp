"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { decimalOptional, numOrNull, textOrNull } from "@/components/foods/form-helpers";

/**
 * Mini formulaire « l’aliment n’existe pas encore » utilisé dans le dialogue
 * d’ajout au stock : il ne crée rien côté serveur, il prépare la charge utile
 * envoyée à `POST /stocks/items` (le backend crée l’aliment au passage, §6.2).
 */

const schema = z.object({
  name: z.string().trim().min(2, "Indique un nom d’au moins 2 caractères."),
  brand: z.string(),
  calories: decimalOptional,
  proteins: decimalOptional,
  carbs: decimalOptional,
  fat: decimalOptional,
});

type FormValues = z.infer<typeof schema>;

export type ManualFood = {
  name: string;
  brand: string | null;
  barcode: string | null;
  calories: number | null;
  proteins: number | null;
  carbs: number | null;
  fat: number | null;
};

export type ManualFoodFormProps = {
  defaultName?: string;
  defaultBarcode?: string | null;
  onConfirm: (food: ManualFood) => void;
  onCancel: () => void;
};

export function ManualFoodForm({ defaultName = "", defaultBarcode = null, onConfirm, onCancel }: ManualFoodFormProps) {
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: defaultName, brand: "", calories: "", proteins: "", carbs: "", fat: "" },
  });

  const onSubmit = handleSubmit((values) => {
    onConfirm({
      name: values.name.trim(),
      brand: textOrNull(values.brand),
      barcode: defaultBarcode,
      calories: numOrNull(values.calories),
      proteins: numOrNull(values.proteins),
      carbs: numOrNull(values.carbs),
      fat: numOrNull(values.fat),
    });
  });

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
      <p className="text-sm text-slate-600">
        Ce produit n’est pas encore dans la base
        {defaultBarcode ? ` (code-barres ${defaultBarcode})` : ""}. Renseigne-le pour ne rien perdre de sa nutrition.
      </p>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Nom" required autoComplete="off" error={errors.name?.message} {...register("name")} />
        <Field label="Marque" autoComplete="off" error={errors.brand?.message} {...register("brand")} />
      </div>
      <fieldset className="space-y-3">
        <legend className="text-sm font-medium text-slate-700">Valeurs pour 100 g</legend>
        <div className="grid gap-4 sm:grid-cols-4">
          <Field label="kcal" inputMode="decimal" error={errors.calories?.message} {...register("calories")} />
          <Field label="Protéines" inputMode="decimal" error={errors.proteins?.message} {...register("proteins")} />
          <Field label="Glucides" inputMode="decimal" error={errors.carbs?.message} {...register("carbs")} />
          <Field label="Lipides" inputMode="decimal" error={errors.fat?.message} {...register("fat")} />
        </div>
      </fieldset>
      <div className="flex flex-wrap items-center justify-end gap-2">
        <Button type="button" variant="secondary" onClick={onCancel}>
          Annuler
        </Button>
        <Button type="submit">Utiliser cet aliment</Button>
      </div>
    </form>
  );
}

export default ManualFoodForm;
