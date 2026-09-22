"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation } from "@tanstack/react-query";
import { z } from "zod";
import { CaloriesField } from "@/components/sport/calories-field";
import { Chip, ChipGroup } from "@/components/sport/chips";
import { sportApi, useInvalidateSport, useSportVocab } from "@/components/sport/sport-api";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field, TextareaField } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { useToast } from "@/components/ui/toast";
import { getErrorMessage, isApiError } from "@/lib/api-client";
import { formatKcal } from "@/lib/format";
import { parseDecimal } from "@/lib/format";
import type { CaloriesSource, Intensity, SportPlan } from "@/lib/types/api";

/** « Réaliser » — logs a planned session as done (`POST /sport/calendar/{id}/log`). */

const schema = z.object({
  duration_min: z
    .number({ error: "Indique une durée entre 5 et 600 minutes." })
    .int()
    .min(5, "Indique une durée entre 5 et 600 minutes.")
    .max(600, "Indique une durée entre 5 et 600 minutes."),
  distance_km: z.string().optional(),
  notes: z.string().max(500).optional(),
});

type FormValues = z.infer<typeof schema>;

export type LogPlanModalProps = {
  open: boolean;
  onClose: () => void;
  plan: SportPlan | null;
};

export function LogPlanModal({ open, onClose, plan }: LogPlanModalProps) {
  const { vocab } = useSportVocab();
  const invalidate = useInvalidateSport();
  const { toast, success } = useToast();
  const [intensity, setIntensity] = useState<Intensity>("moderee");
  const [calories, setCalories] = useState("");
  const [caloriesSource, setCaloriesSource] = useState<CaloriesSource>("auto");
  const [banner, setBanner] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    reset,
    watch,
    setError,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { duration_min: plan?.planned_duration_min ?? 30, distance_km: "", notes: "" },
  });

  useEffect(() => {
    if (!open) return;
    setIntensity("moderee");
    setCalories("");
    setCaloriesSource("auto");
    setBanner(null);
    reset({ duration_min: plan?.planned_duration_min ?? 30, distance_km: "", notes: "" });
  }, [open, plan, reset]);

  const durationMin = Number(watch("duration_min")) || 0;

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      if (!plan) throw new Error("Aucune séance sélectionnée.");
      const distance = parseDecimal(values.distance_km ?? "");
      const manual = caloriesSource === "manuel" ? parseDecimal(calories) : null;
      return sportApi.logPlan(plan.id, {
        duration_min: values.duration_min,
        intensity,
        ...(distance !== null ? { distance_km: distance } : {}),
        ...(manual !== null ? { calories_burned: Math.round(manual) } : {}),
        ...(values.notes?.trim() ? { notes: values.notes.trim() } : {}),
      });
    },
    onSuccess: async (response) => {
      await invalidate();
      success(
        "Séance enregistrée.",
        `${formatKcal(response.nutrition.calories_burned)} brûlées · +${formatKcal(
          response.nutrition.calories_bonus,
        )} dans ton budget du jour`,
      );
      onClose();
    },
    onError: (error) => {
      if (isApiError(error) && error.isValidation) {
        const duration = error.fieldError("duration_min");
        if (duration) setError("duration_min", { message: duration });
        const distance = error.fieldError("distance_km");
        if (distance) setError("distance_km", { message: distance });
        setBanner(duration || distance ? null : error.message);
        return;
      }
      setBanner(getErrorMessage(error));
      toast({ title: "Enregistrement impossible", description: getErrorMessage(error), tone: "error" });
    },
  });

  return (
    <Modal
      open={open}
      onClose={mutation.isPending ? () => undefined : onClose}
      locked={mutation.isPending}
      title="J’ai fait cette séance"
      description={plan ? `${plan.sport_name} · prévu ${plan.planned_duration_min} min` : undefined}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
            Annuler
          </Button>
          <Button
            form="log-plan-form"
            type="submit"
            loading={mutation.isPending}
            disabled={!plan}
          >
            Enregistrer la séance
          </Button>
        </>
      }
    >
      <form
        id="log-plan-form"
        className="space-y-4"
        noValidate
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
      >
        {banner ? <Banner tone="error">{banner}</Banner> : null}

        <Field
          label="Durée réelle (min)"
          required
          type="number"
          min={5}
          max={600}
          error={errors.duration_min?.message}
          {...register("duration_min", { valueAsNumber: true })}
        />

        <ChipGroup legend="Intensité">
          {vocab.intensites.map((entry) => (
            <Chip
              key={entry.key}
              role="radio"
              selected={intensity === entry.key}
              onClick={() => setIntensity(entry.key as Intensity)}
            >
              {entry.label}
            </Chip>
          ))}
        </ChipGroup>

        <Field
          label="Distance (km) — optionnel"
          inputMode="decimal"
          placeholder="5"
          error={errors.distance_km?.message}
          {...register("distance_km")}
        />

        <CaloriesField
          sportId={plan?.sport_id ?? null}
          sportName={plan?.sport_name ?? null}
          durationMin={durationMin}
          intensity={intensity}
          value={calories}
          source={caloriesSource}
          onChange={(next, source) => {
            setCalories(next);
            setCaloriesSource(source);
          }}
        />

        <TextareaField
          label="Notes — optionnel"
          rows={2}
          maxLength={500}
          error={errors.notes?.message}
          {...register("notes")}
        />
      </form>
    </Modal>
  );
}

export default LogPlanModal;
