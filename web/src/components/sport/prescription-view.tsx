"use client";

import { cibleChips, exerciseResume } from "@/components/sport/prescription";
import { Pill } from "@/components/ui/pill";
import { cn } from "@/lib/cn";
import type { ExercisePrescriptionFields } from "@/lib/types/api";

/**
 * Ce qu’il faut faire, en clair : la phrase `resume` d’abord, puis les cibles en pastilles
 * (effort, récupération), la consigne et le conseil de charge (docs/SPORT_V2.md §6).
 */
export function PrescriptionDetails({
  exercise,
  className,
}: {
  exercise: ExercisePrescriptionFields & {
    sets?: number | null;
    reps?: number | null;
    duration_sec?: number | null;
    rest_sec?: number | null;
    distance_km?: number | null;
  };
  className?: string;
}) {
  const prescription = exercise.prescription ?? null;
  const resume = exerciseResume(exercise);
  const effort = cibleChips(prescription?.cible);
  const recup = cibleChips(prescription?.recup_cible);
  const force: { key: string; label: string; title: string }[] = [];
  if (typeof prescription?.rir === "number") {
    force.push({
      key: "rir",
      label: `RIR ${prescription.rir}`,
      title: `Garde ${prescription.rir} répétition${prescription.rir > 1 ? "s" : ""} en réserve`,
    });
  }
  if (prescription?.tempo) {
    force.push({
      key: "tempo",
      label: `Tempo ${prescription.tempo}`,
      title: "Descente – pause – montée, en secondes",
    });
  }
  const consigne = prescription?.consigne?.trim();
  const charge = prescription?.charge_conseil?.trim();

  if (!resume && effort.length === 0 && force.length === 0 && !consigne && !charge) return null;

  return (
    <div className={cn("space-y-2", className)}>
      {resume ? <p className="text-sm font-semibold leading-6 text-slate-900">{resume}</p> : null}

      {effort.length > 0 || force.length > 0 ? (
        <div className="flex flex-wrap gap-1.5" aria-label="Cibles pendant l’effort">
          {effort.map((chip) => (
            <Pill key={chip.key} tone={chip.tone} title={chip.title}>
              {chip.label}
            </Pill>
          ))}
          {force.map((chip) => (
            <Pill key={chip.key} tone="indigo" title={chip.title}>
              {chip.label}
            </Pill>
          ))}
        </div>
      ) : null}

      {recup.length > 0 ? (
        <div className="flex flex-wrap items-center gap-1.5" aria-label="Cibles pendant la récupération">
          <span className="text-xs font-medium text-slate-500">Récup :</span>
          {recup.map((chip) => (
            <Pill key={chip.key} tone="slate" title={chip.title}>
              {chip.label}
            </Pill>
          ))}
        </div>
      ) : null}

      {consigne ? (
        <p className="rounded-2xl border border-emerald-100 bg-emerald-50/70 px-3 py-2 text-sm leading-6 text-emerald-900">
          {consigne}
        </p>
      ) : null}
      {charge ? <p className="text-xs leading-5 text-slate-500">{charge}</p> : null}
    </div>
  );
}

export default PrescriptionDetails;
