"use client";

import { useEffect, useRef, useState } from "react";
import { sportApi } from "@/components/sport/sport-api";
import { Field } from "@/components/ui/field";
import { Pill } from "@/components/ui/pill";
import { getErrorMessage } from "@/lib/api-client";
import { formatNumber } from "@/lib/format";
import type { CaloriesSource, Intensity } from "@/lib/types/api";

/**
 * Calories burned input prefilled from `POST /sport/calories/estimate`.
 * Typing a value switches the pill from « auto » to « manuel »; the
 * « Revenir au calcul automatique » link goes back to the estimate.
 */

export type CaloriesFieldProps = {
  sportId?: number | null;
  sportName?: string | null;
  durationMin: number;
  intensity?: Intensity;
  /** Current raw text value of the field. */
  value: string;
  source: CaloriesSource;
  onChange: (value: string, source: CaloriesSource) => void;
  error?: string;
  disabled?: boolean;
};

export function CaloriesField({
  sportId,
  sportName,
  durationMin,
  intensity = "moderee",
  value,
  source,
  onChange,
  error,
  disabled = false,
}: CaloriesFieldProps) {
  const [met, setMet] = useState<number | null>(null);
  const [estimating, setEstimating] = useState(false);
  const [estimateError, setEstimateError] = useState<string | null>(null);
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;

  const canEstimate = Boolean(sportId || sportName) && durationMin >= 5 && durationMin <= 600;

  useEffect(() => {
    if (source === "manuel" || !canEstimate) return;
    const controller = new AbortController();
    const timer = setTimeout(async () => {
      setEstimating(true);
      setEstimateError(null);
      try {
        const response = await sportApi.estimateCalories(
          {
            ...(sportId ? { sport_id: sportId } : {}),
            ...(!sportId && sportName ? { sport_name: sportName } : {}),
            duration_min: durationMin,
            intensity,
          },
          { signal: controller.signal },
        );
        if (controller.signal.aborted) return;
        setMet(response.data.met);
        onChangeRef.current(String(Math.round(response.data.calories)), "auto");
      } catch (err) {
        if (controller.signal.aborted) return;
        setEstimateError(getErrorMessage(err));
      } finally {
        if (!controller.signal.aborted) setEstimating(false);
      }
    }, 350);
    return () => {
      controller.abort();
      clearTimeout(timer);
    };
  }, [source, canEstimate, sportId, sportName, durationMin, intensity]);

  const hint =
    source === "manuel"
      ? "Valeur saisie à la main : elle sera enregistrée telle quelle."
      : estimating
        ? "Estimation en cours…"
        : `Estimation à partir de ton poids, du sport et de la durée. Tu peux la corriger.${
            met ? ` (MET ${formatNumber(met)})` : ""
          }`;

  return (
    <div className="min-w-0">
      <div className="mb-1.5 flex items-center gap-2">
        <span className="text-sm font-medium text-slate-700">Calories brûlées</span>
        <Pill tone={source === "manuel" ? "slate" : "amber"}>{source === "manuel" ? "manuel" : "auto"}</Pill>
      </div>
      <Field
        aria-label="Calories brûlées"
        inputMode="numeric"
        placeholder="0"
        value={value}
        disabled={disabled}
        onChange={(event) => onChange(event.target.value, "manuel")}
        error={error ?? estimateError ?? undefined}
        hint={hint}
      />
      {source === "manuel" && canEstimate ? (
        <button
          type="button"
          onClick={() => onChange(value, "auto")}
          className="mt-2 inline-flex min-h-10 items-center rounded-xl px-2 text-xs font-semibold text-emerald-800 transition hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/20"
        >
          Revenir au calcul automatique
        </button>
      ) : null}
    </div>
  );
}

export default CaloriesField;
