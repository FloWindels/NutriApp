"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";
import { Field } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { useToast } from "@/components/ui/toast";
import { apiPost, getErrorMessage, isApiError } from "@/lib/api-client";
import { formatDate, formatKg, formatSigned, parseDecimal, todayIso } from "@/lib/format";
import { messages } from "@/lib/messages";
import { queryKeys } from "@/lib/query-keys";
import type { DataEnvelope, Dashboard, WeightLog } from "@/lib/types/api";
import { Sparkline } from "./sparkline";

export type WeightCardProps = {
  weight: Dashboard["weight"];
  /** Date of the dashboard (the modal pre-fills it). */
  date?: string;
};

const schema = z.object({
  date: z.string().min(1, "La date est requise."),
  weight_kg: z
    .string()
    .min(1, "Le poids est requis.")
    .refine((value) => {
      const parsed = parseDecimal(value);
      return parsed !== null && parsed >= 20 && parsed <= 400;
    }, "Saisis un poids entre 20 et 400 kg."),
});

type FormValues = z.infer<typeof schema>;

/** Current weight against the target, sparkline of the history and the « Peser » modal. */
export function WeightCard({ weight, date }: WeightCardProps) {
  const queryClient = useQueryClient();
  const { success } = useToast();
  const [open, setOpen] = useState(false);
  const [globalError, setGlobalError] = useState<string | null>(null);

  const history = [...(weight?.history ?? [])].sort((a, b) => a.date.localeCompare(b.date));
  const current = weight?.current ?? history.at(-1)?.weight_kg ?? null;
  const target = weight?.target ?? null;
  const delta = current !== null && target !== null ? current - target : null;

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { date: date ?? todayIso(), weight_kg: current !== null ? String(current).replace(".", ",") : "" },
  });

  function openModal() {
    setGlobalError(null);
    reset({
      date: date ?? todayIso(),
      weight_kg: current !== null ? String(current).replace(".", ",") : "",
    });
    setOpen(true);
  }

  async function onSubmit(values: FormValues) {
    setGlobalError(null);
    const weightKg = parseDecimal(values.weight_kg);
    if (weightKg === null) {
      setError("weight_kg", { message: "Saisis un poids valide." });
      return;
    }
    try {
      await apiPost<DataEnvelope<WeightLog>>("/weights", { date: values.date, weight_kg: weightKg });
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all }),
        queryClient.invalidateQueries({ queryKey: queryKeys.weights() }),
        queryClient.invalidateQueries({ queryKey: queryKeys.profile }),
        queryClient.invalidateQueries({ queryKey: queryKeys.history() }),
      ]);
      success("Poids enregistré", `${formatKg(weightKg)} le ${formatDate(values.date)}`);
      setOpen(false);
    } catch (error) {
      if (isApiError(error) && error.isValidation) {
        const fieldMessage = error.fieldError("weight_kg") ?? error.fieldError("date");
        if (error.fieldError("weight_kg")) {
          setError("weight_kg", { message: error.fieldError("weight_kg") });
          return;
        }
        if (error.fieldError("date")) {
          setError("date", { message: error.fieldError("date") });
          return;
        }
        setGlobalError(fieldMessage ?? error.message);
        return;
      }
      setGlobalError(getErrorMessage(error));
    }
  }

  return (
    <Card>
      <CardHeader
        title="Poids"
        subtitle="Suivi de ta courbe et de ton objectif."
        actions={
          <Button variant="secondary" onClick={openModal}>
            Peser
          </Button>
        }
      />

      {current === null ? (
        <EmptyState
          compact
          className="mt-4"
          title="Aucune pesée enregistrée"
          message="Enregistre ton poids pour suivre ta progression semaine après semaine."
          action={<Button onClick={openModal}>Me peser</Button>}
        />
      ) : (
        <div className="mt-4 space-y-3">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <div>
              <p className="text-3xl font-semibold tracking-tight text-slate-950">{formatKg(current)}</p>
              <p className="mt-0.5 text-sm text-slate-500">
                {target !== null ? `objectif ${formatKg(target)}` : "aucun objectif défini"}
                {delta !== null ? ` · ${formatSigned(Math.round(delta * 10) / 10)} kg` : ""}
              </p>
            </div>
            {weight?.variation_hebdo_kg !== null && weight?.variation_hebdo_kg !== undefined ? (
              <p className="text-sm text-slate-500">
                Variation visée : {formatSigned(weight.variation_hebdo_kg)} kg/semaine
              </p>
            ) : null}
          </div>

          {history.length > 1 ? (
            <Sparkline
              points={history.map((entry) => ({ label: entry.date, value: entry.weight_kg }))}
              target={target}
              ariaLabel={`Évolution du poids sur les ${history.length} dernières pesées`}
            />
          ) : (
            <p className="text-xs text-slate-500">
              Enregistre une deuxième pesée pour afficher ta courbe.
            </p>
          )}
        </div>
      )}

      <Modal
        open={open}
        onClose={() => (isSubmitting ? undefined : setOpen(false))}
        title="Enregistrer une pesée"
        description="Pèse-toi de préférence le matin, à jeun."
        size="sm"
        locked={isSubmitting}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)} disabled={isSubmitting}>
              {messages.cancel}
            </Button>
            <Button type="submit" form="weight-form" loading={isSubmitting}>
              {messages.save}
            </Button>
          </>
        }
      >
        <form id="weight-form" onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
          <Field
            label="Poids (kg)"
            inputMode="decimal"
            autoComplete="off"
            placeholder="76,5"
            error={errors.weight_kg?.message}
            {...register("weight_kg")}
          />
          <Field
            label="Date"
            type="date"
            max={todayIso()}
            error={errors.date?.message}
            {...register("date")}
          />
          {globalError ? <Banner tone="error">{globalError}</Banner> : null}
        </form>
      </Modal>
    </Card>
  );
}

export default WeightCard;
