"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation } from "@tanstack/react-query";
import { z } from "zod";
import { Chip, ChipGroup, Switch } from "@/components/sport/chips";
import {
  PLAN_DURATION_CHIPS,
  isoWeekday,
  sportApi,
  useInvalidateSport,
  useSportVocab,
} from "@/components/sport/sport-api";
import { SportPickerModal } from "@/components/sport/sport-picker";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field, FieldWrapper, TextareaField } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { useToast } from "@/components/ui/toast";
import { getErrorMessage, isApiError } from "@/lib/api-client";
import { capitalize, formatLongDate, todayIso } from "@/lib/format";
import type { Sport, SportLieu, SportPlan } from "@/lib/types/api";
import { WEEKDAY_LABELS } from "@/lib/vocab";

/** « Planifier » / « Modifier » a calendar plan (`POST|PUT /sport/calendar`). */

const schema = z.object({
  date: z.string().min(1, "Choisis une date."),
  planned_duration_min: z
    .number({ error: "Indique une durée entre 5 et 600 minutes." })
    .int()
    .min(5, "Indique une durée entre 5 et 600 minutes.")
    .max(600, "Indique une durée entre 5 et 600 minutes."),
  planned_at: z.string().optional(),
  notes: z.string().max(500).optional(),
});

type FormValues = z.infer<typeof schema>;

export type PlanModalProps = {
  open: boolean;
  onClose: () => void;
  /** Day preselected in the calendar. */
  date: string;
  /** Existing plan → edit mode. */
  plan?: SportPlan | null;
};

export function PlanModal({ open, onClose, date, plan }: PlanModalProps) {
  const { vocab } = useSportVocab();
  const invalidate = useInvalidateSport();
  const { success } = useToast();
  const editing = Boolean(plan);

  const [sport, setSport] = useState<Sport | null>(null);
  const [sportLabel, setSportLabel] = useState<string>("");
  const [pickerOpen, setPickerOpen] = useState(false);
  const [lieu, setLieu] = useState<SportLieu | null>(null);
  const [repeat, setRepeat] = useState(false);
  const [weeks, setWeeks] = useState(4);
  const [banner, setBanner] = useState<string | null>(null);
  const [sportError, setSportError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    reset,
    watch,
    setValue,
    setError,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { date, planned_duration_min: 45, planned_at: "", notes: "" },
  });

  useEffect(() => {
    if (!open) return;
    setSport(null);
    setSportLabel(plan?.sport_name ?? "");
    setLieu(plan?.lieu ?? null);
    setRepeat(false);
    setWeeks(4);
    setBanner(null);
    setSportError(null);
    reset({
      date: plan?.date ?? date ?? todayIso(),
      planned_duration_min: plan?.planned_duration_min ?? 45,
      planned_at: plan?.planned_at ? plan.planned_at.slice(0, 5) : "",
      notes: plan?.notes ?? "",
    });
  }, [open, plan, date, reset]);

  const duration = Number(watch("planned_duration_min")) || 0;
  const currentDate = watch("date") || date;

  const mutation = useMutation({
    mutationFn: async (values: FormValues): Promise<void> => {
      const sportId = sport?.id ?? plan?.sport_id ?? null;
      const base = {
        ...(sportId ? { sport_id: sportId } : {}),
        ...(!sportId && sportLabel ? { sport_name: sportLabel } : {}),
        planned_duration_min: values.planned_duration_min,
        planned_at: values.planned_at?.trim() ? values.planned_at : null,
        lieu,
        notes: values.notes?.trim() ? values.notes.trim() : null,
      };
      if (plan) {
        await sportApi.updatePlan(plan.id, { date: values.date, ...base });
        return;
      }
      if (repeat) {
        await sportApi.createRecurring({
          weekday: isoWeekday(values.date),
          weeks,
          start_date: values.date,
          ...base,
        });
        return;
      }
      await sportApi.createPlan({ date: values.date, ...base });
    },
    onSuccess: async () => {
      await invalidate();
      success(
        editing ? "Séance prévue mise à jour." : repeat ? "Séances planifiées." : "Séance planifiée.",
      );
      onClose();
    },
    onError: (error) => {
      if (isApiError(error) && error.isValidation) {
        let mapped = false;
        for (const field of ["date", "planned_duration_min", "planned_at", "notes"] as const) {
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
    if (!sport && !sportLabel) {
      setSportError("Choisis d’abord un sport.");
      return;
    }
    setSportError(null);
    mutation.mutate(values);
  }

  const weekdayLabel = WEEKDAY_LABELS[isoWeekday(currentDate) - 1] ?? "";

  return (
    <>
      <Modal
        open={open}
        onClose={mutation.isPending ? () => undefined : onClose}
        locked={mutation.isPending}
        title={editing ? "Modifier la séance prévue" : "Planifier une séance"}
        description={capitalize(formatLongDate(currentDate))}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
              Annuler
            </Button>
            <Button form="plan-form" type="submit" loading={mutation.isPending}>
              {editing ? "Enregistrer" : "Planifier"}
            </Button>
          </>
        }
      >
        <form id="plan-form" className="space-y-4" noValidate onSubmit={handleSubmit(submit)}>
          {banner ? <Banner tone="error">{banner}</Banner> : null}

          <FieldWrapper label="Sport" required error={sportError ?? undefined}>
            <button
              type="button"
              onClick={() => setPickerOpen(true)}
              className="flex min-h-12 w-full items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/20"
            >
              <span className={sportLabel ? "text-sm font-medium text-slate-900" : "text-sm text-slate-400"}>
                {sportLabel || "Choisis ton sport"}
              </span>
              <span aria-hidden="true" className="text-slate-400">
                ▾
              </span>
            </button>
          </FieldWrapper>

          <Field label="Date de la séance" type="date" required error={errors.date?.message} {...register("date")} />

          <ChipGroup legend="Durée" hint="Choisis une durée ou saisis la tienne.">
            {PLAN_DURATION_CHIPS.map((value) => (
              <Chip
                key={value}
                role="radio"
                selected={duration === value}
                onClick={() => setValue("planned_duration_min", value, { shouldValidate: true })}
              >
                {`${value} min`}
              </Chip>
            ))}
          </ChipGroup>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              label="Durée (min)"
              type="number"
              min={5}
              max={600}
              error={errors.planned_duration_min?.message}
              {...register("planned_duration_min", { valueAsNumber: true })}
            />
            <Field
              label="Heure de la séance"
              type="time"
              error={errors.planned_at?.message}
              {...register("planned_at")}
            />
          </div>

          <ChipGroup legend="Lieu">
            {vocab.lieux.map((entry) => (
              <Chip
                key={entry.key}
                role="radio"
                selected={lieu === entry.key}
                onClick={() => setLieu(lieu === entry.key ? null : (entry.key as SportLieu))}
              >
                {entry.label}
              </Chip>
            ))}
          </ChipGroup>

          <TextareaField
            label="Notes — optionnel"
            rows={2}
            maxLength={500}
            error={errors.notes?.message}
            {...register("notes")}
          />

          {editing ? null : (
            <div className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <Switch
                checked={repeat}
                onChange={setRepeat}
                label="Répéter chaque semaine"
                hint={
                  repeat
                    ? `Chaque ${weekdayLabel} pendant ${weeks} semaine${weeks > 1 ? "s" : ""}.`
                    : "Crée la même séance sur plusieurs semaines."
                }
              />
              {repeat ? (
                <Field
                  label="Nombre de semaines"
                  type="number"
                  min={1}
                  max={12}
                  value={weeks}
                  onChange={(event) => {
                    const next = Number(event.target.value);
                    setWeeks(Number.isFinite(next) ? Math.min(12, Math.max(1, next)) : 1);
                  }}
                />
              ) : null}
            </div>
          )}
        </form>
      </Modal>

      <SportPickerModal
        open={pickerOpen}
        onClose={() => setPickerOpen(false)}
        onPick={(picked) => {
          setSport(picked);
          setSportLabel(picked.name);
          setSportError(null);
          setPickerOpen(false);
        }}
      />
    </>
  );
}

export default PlanModal;
