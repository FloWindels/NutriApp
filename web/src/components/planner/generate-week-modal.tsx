"use client";

import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { SwitchRow } from "@/components/settings/switch";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Modal } from "@/components/ui/modal";
import { apiPost, getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { formatDay } from "@/lib/format";
import { messages } from "@/lib/messages";
import { MEAL_TYPES, type MealType, type PlannerGenerateResponse } from "@/lib/types/api";
import { MEAL_TYPE_LABELS, labelFor } from "@/lib/vocab";
import { useResetOnChange } from "@/lib/use-reset-on-change";

const DEFAULT_TYPES: MealType[] = ["dejeuner", "diner"];

export type GenerateWeekModalProps = {
  open: boolean;
  onClose: () => void;
  weekStart: string;
  onGenerated: (count: number) => void;
};

/**
 * « Générer la semaine » — `POST /planner/generate` with the meal types to fill
 * and a « Remplacer l’existant » switch.
 */
export function GenerateWeekModal({ open, onClose, weekStart, onGenerated }: GenerateWeekModalProps) {
  const [mealTypes, setMealTypes] = useState<MealType[]>(DEFAULT_TYPES);
  const [replace, setReplace] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useResetOnChange(String(open), () => {
    if (!open) return;
    setMealTypes(DEFAULT_TYPES);
    setReplace(false);
    setError(null);
  });

  const generate = useMutation({
    mutationFn: () =>
      apiPost<PlannerGenerateResponse>("/planner/generate", {
        week_start: weekStart,
        meal_types: MEAL_TYPES.filter((type) => mealTypes.includes(type)),
        replace,
      }),
    onSuccess: (response) => {
      onGenerated(response.generated_count ?? 0);
      onClose();
    },
    onError: (err) => setError(getErrorMessage(err)),
  });

  function toggle(type: MealType) {
    setMealTypes((current) =>
      current.includes(type) ? current.filter((item) => item !== type) : [...current, type],
    );
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      locked={generate.isPending}
      title="Générer la semaine"
      description={`Semaine du ${formatDay(weekStart)} · recettes compatibles avec ton régime et tes cibles (estimation).`}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={generate.isPending}>
            {messages.cancel}
          </Button>
          <Button
            onClick={() => generate.mutate()}
            loading={generate.isPending}
            disabled={mealTypes.length === 0}
          >
            Générer la semaine
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error ? <Banner tone="error">{error}</Banner> : null}

        <fieldset className="min-w-0">
          <legend className="mb-2 block text-sm font-medium text-slate-700">Repas à remplir</legend>
          <div className="flex flex-wrap gap-2">
            {MEAL_TYPES.map((type) => {
              const selected = mealTypes.includes(type);
              return (
                <button
                  key={type}
                  type="button"
                  role="checkbox"
                  aria-checked={selected}
                  onClick={() => toggle(type)}
                  disabled={generate.isPending}
                  className={cn(
                    "inline-flex h-10 items-center rounded-2xl border px-4 text-sm font-medium transition disabled:opacity-60",
                    selected
                      ? "border-emerald-700 bg-emerald-700 text-white"
                      : "border-slate-200 bg-white text-slate-700 hover:bg-slate-50",
                  )}
                >
                  {labelFor(MEAL_TYPE_LABELS, type)}
                </button>
              );
            })}
          </div>
          {mealTypes.length === 0 ? (
            <p className="mt-2 text-xs font-medium text-rose-600" role="alert">
              Choisis au moins un type de repas.
            </p>
          ) : null}
        </fieldset>

        <SwitchRow
          title="Remplacer l’existant"
          description="Les repas déjà prévus sur ces créneaux seront remplacés."
          checked={replace}
          onChange={setReplace}
          disabled={generate.isPending}
          className="rounded-2xl border border-slate-200 bg-slate-50 px-4"
        />
      </div>
    </Modal>
  );
}

export default GenerateWeekModal;
