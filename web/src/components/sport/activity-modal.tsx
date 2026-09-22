"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation } from "@tanstack/react-query";
import { z } from "zod";
import { CaloriesField } from "@/components/sport/calories-field";
import { Chip, ChipGroup } from "@/components/sport/chips";
import { sportApi, useInvalidateSport, useSportVocab } from "@/components/sport/sport-api";
import { SportPickerModal } from "@/components/sport/sport-picker";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field, FieldWrapper, TextareaField } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { useToast } from "@/components/ui/toast";
import { getErrorMessage, isApiError } from "@/lib/api-client";
import { formatKcal, parseDecimal, todayIso } from "@/lib/format";
import type { CaloriesSource, Intensity, Sport } from "@/lib/types/api";

/** « Activité rapide » — logs a finished activity (`POST /sport/activities`). */

const schema = z.object({
  date: z.string().min(1, "Choisis une date."),
  duration_min: z
    .number({ error: "Indique une durée entre 5 et 600 minutes." })
    .int()
    .min(5, "Indique une durée entre 5 et 600 minutes.")
    .max(600, "Indique une durée entre 5 et 600 minutes."),
  distance_km: z.string().optional(),
  notes: z.string().max(500).optional(),
});

type FormValues = z.infer<typeof schema>;

export type ActivityModalProps = {
  open: boolean;
  onClose: () => void;
  date?: string;
};

export function ActivityModal({ open, onClose, date }: ActivityModalProps) {
  const { vocab } = useSportVocab();
  const invalidate = useInvalidateSport();
  const { success } = useToast();
  const [sport, setSport] = useState<Sport | null>(null);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [intensity, setIntensity] = useState<Intensity>("moderee");
  const [calories, setCalories] = useState("");
  const [caloriesSource, setCaloriesSource] = useState<CaloriesSource>("auto");
  const [banner, setBanner] = useState<string | null>(null);
  const [sportError, setSportError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    reset,
    watch,
    setError,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { date: date ?? todayIso(), duration_min: 30, distance_km: "", notes: "" },
  });

  useEffect(() => {
    if (!open) return;
    setSport(null);
    setIntensity("moderee");
    setCalories("");
    setCaloriesSource("auto");
    setBanner(null);
    setSportError(null);
    reset({ date: date ?? todayIso(), duration_min: 30, distance_km: "", notes: "" });
  }, [open, date, reset]);

  const durationMin = Number(watch("duration_min")) || 0;

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const distance = parseDecimal(values.distance_km ?? "");
      const manual = caloriesSource === "manuel" ? parseDecimal(calories) : null;
      return sportApi.logActivity({
        date: values.date,
        sport_id: sport?.id ?? null,
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
        "Activité enregistrée.",
        `${formatKcal(response.nutrition.calories_burned)} brûlées · +${formatKcal(
          response.nutrition.calories_bonus,
        )} dans ton budget du jour`,
      );
      onClose();
    },
    onError: (error) => {
      if (isApiError(error) && error.isValidation) {
        let mapped = false;
        for (const field of ["date", "duration_min", "distance_km", "notes"] as const) {
          const message = error.fieldError(field);
          if (message) {
            setError(field, { message });
            mapped = true;
          }
        }
        const sportMessage = error.fieldError("sport_id") ?? error.fieldError("sport_name");
        if (sportMessage) {
          setSportError(sportMessage);
          mapped = true;
        }
        setBanner(mapped ? null : error.message);
        return;
      }
      setBanner(getErrorMessage(error));
    },
  });

  function submit(values: FormValues) {
    if (!sport) {
      setSportError("Choisis d’abord un sport.");
      return;
    }
    setSportError(null);
    mutation.mutate(values);
  }

  return (
    <>
      <Modal
        open={open}
        onClose={mutation.isPending ? () => undefined : onClose}
        locked={mutation.isPending}
        title="Activité rapide"
        description="Enregistre un sport déjà pratiqué : les calories brûlées s’ajoutent à ton budget."
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
              Annuler
            </Button>
            <Button form="activity-form" type="submit" loading={mutation.isPending}>
              Enregistrer l’activité
            </Button>
          </>
        }
      >
        <form id="activity-form" className="space-y-4" noValidate onSubmit={handleSubmit(submit)}>
          {banner ? <Banner tone="error">{banner}</Banner> : null}

          <FieldWrapper label="Sport" required error={sportError ?? undefined}>
            <button
              type="button"
              onClick={() => setPickerOpen(true)}
              className="flex min-h-12 w-full items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-left text-slate-900 transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/20"
            >
              <span className={sport ? "text-sm font-medium" : "text-sm text-slate-400"}>
                {sport ? sport.name : "Choisis ton sport"}
              </span>
              <span aria-hidden="true" className="text-slate-400">
                ▾
              </span>
            </button>
          </FieldWrapper>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Date" type="date" required error={errors.date?.message} {...register("date")} />
            <Field
              label="Durée (min)"
              required
              type="number"
              min={5}
              max={600}
              error={errors.duration_min?.message}
              {...register("duration_min", { valueAsNumber: true })}
            />
          </div>

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
            sportId={sport?.id ?? null}
            sportName={sport?.name ?? null}
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

      <SportPickerModal
        open={pickerOpen}
        onClose={() => setPickerOpen(false)}
        onPick={(picked) => {
          setSport(picked);
          setSportError(null);
          setCaloriesSource("auto");
          setPickerOpen(false);
        }}
      />
    </>
  );
}

export default ActivityModal;
