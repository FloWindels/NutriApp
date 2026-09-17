"use client";

import { useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { getErrorMessage, isApiError } from "@/lib/api-client";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";

const schema = z.object({
  name: z.string().trim().min(2, "Indique un nom d’au moins 2 caractères.").max(60, "60 caractères maximum."),
});

type FormValues = z.infer<typeof schema>;

export type LocationFormProps = {
  mode: "create" | "rename";
  initialName?: string;
  /** May reject with an `ApiError`: a 422 on `name` is mapped to the field. */
  submit: (name: string) => Promise<void>;
  onCancel: () => void;
};

/** Création / renommage d’un lieu de stockage. */
export function LocationForm({ mode, initialName = "", submit, onCancel }: LocationFormProps) {
  const [formError, setFormError] = useState<string | null>(null);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { name: initialName } });

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      await submit(values.name.trim());
    } catch (error) {
      if (isApiError(error) && error.isValidation) {
        const message = error.fieldError("name");
        if (message) {
          setError("name", { message });
          return;
        }
      }
      setFormError(getErrorMessage(error));
    }
  });

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      {formError ? <Banner tone="error">{formError}</Banner> : null}
      <Field
        label="Nom du lieu"
        required
        autoComplete="off"
        placeholder="Ex. : Frigo, Congélateur, Placard"
        error={errors.name?.message}
        {...register("name")}
      />
      <div className="flex flex-wrap items-center justify-end gap-2">
        <Button type="button" variant="secondary" onClick={onCancel} disabled={isSubmitting}>
          Annuler
        </Button>
        <Button type="submit" loading={isSubmitting}>
          {mode === "create" ? "Créer le lieu" : "Renommer"}
        </Button>
      </div>
    </form>
  );
}

export default LocationForm;
