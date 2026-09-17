"use client";

import { EstimatePill, Pill, type PillTone } from "@/components/ui/pill";
import { cn } from "@/lib/cn";
import { formatKcal, formatNumber } from "@/lib/format";
import type { MealPlan, PlanStatus } from "@/lib/types/api";
import { PLAN_STATUS_LABELS, labelFor } from "@/lib/vocab";

const statusTone: Record<PlanStatus, PillTone> = {
  prevu: "slate",
  realise: "emerald",
  annule: "rose",
};

/** Calories of a plan: server value, else recipe per serving × servings (estimate). */
export function planCalories(plan: MealPlan): { calories: number | null; isEstimate: boolean } {
  if (typeof plan.calories === "number" && Number.isFinite(plan.calories)) {
    return { calories: plan.calories, isEstimate: false };
  }
  const perServing = plan.recipe?.per_serving?.calories;
  if (typeof perServing === "number" && Number.isFinite(perServing)) {
    return { calories: perServing * (plan.servings || 1), isEstimate: true };
  }
  return { calories: null, isEstimate: false };
}

function IconButton({
  label,
  onClick,
  tone = "slate",
  children,
}: {
  label: string;
  onClick: () => void;
  tone?: "slate" | "emerald" | "rose";
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      title={label}
      aria-label={label}
      className={cn(
        "grid h-10 w-10 shrink-0 place-items-center rounded-xl border transition focus-visible:outline-none focus-visible:ring-4",
        tone === "emerald" &&
          "border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 focus-visible:ring-emerald-600/20",
        tone === "rose" &&
          "border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 focus-visible:ring-rose-500/20",
        tone === "slate" &&
          "border-slate-200 bg-white text-slate-600 hover:bg-slate-50 focus-visible:ring-slate-400/20",
      )}
    >
      {children}
    </button>
  );
}

export type PlanTileProps = {
  plan: MealPlan;
  onLog: (plan: MealPlan) => void;
  onEdit: (plan: MealPlan) => void;
  onDelete: (plan: MealPlan) => void;
};

export function PlanTile({ plan, onLog, onEdit, onDelete }: PlanTileProps) {
  const { calories, isEstimate } = planCalories(plan);
  const done = plan.status === "realise";

  return (
    <article
      className={cn(
        "rounded-2xl border p-3 text-left transition",
        done ? "border-emerald-200 bg-emerald-50/70" : "border-slate-200 bg-white",
      )}
    >
      <p className="line-clamp-2 text-sm font-semibold leading-5 text-slate-900" title={plan.title}>
        {plan.title}
      </p>

      <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
        <span>
          {formatNumber(plan.servings, 1)} {plan.servings > 1 ? "portions" : "portion"}
        </span>
        {calories !== null ? (
          <>
            <span aria-hidden="true">·</span>
            <span className="font-medium text-slate-700">{formatKcal(calories)}</span>
          </>
        ) : null}
      </div>

      <div className="mt-2 flex flex-wrap items-center gap-1.5">
        <Pill tone={statusTone[plan.status]} dot>
          {labelFor(PLAN_STATUS_LABELS, plan.status)}
        </Pill>
        {isEstimate ? <EstimatePill /> : null}
      </div>

      {plan.notes ? <p className="mt-2 line-clamp-2 text-xs italic text-slate-500">{plan.notes}</p> : null}

      <div className="mt-3 flex flex-wrap gap-1.5">
        {!done ? (
          <IconButton label={`Réaliser « ${plan.title} »`} tone="emerald" onClick={() => onLog(plan)}>
            <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" aria-hidden="true">
              <path
                d="M5 12.5L10 17.5L19 7"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
              />
            </svg>
          </IconButton>
        ) : null}
        <IconButton label={`Modifier « ${plan.title} »`} onClick={() => onEdit(plan)}>
          <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" aria-hidden="true">
            <path
              d="M4 20H8L18.5 9.5C19.6 8.4 19.6 6.6 18.5 5.5C17.4 4.4 15.6 4.4 14.5 5.5L4 16V20Z"
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinejoin="round"
            />
          </svg>
        </IconButton>
        <IconButton label={`Supprimer « ${plan.title} »`} tone="rose" onClick={() => onDelete(plan)}>
          <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" aria-hidden="true">
            <path
              d="M5 7H19M10 11V17M14 11V17M6 7L7 20H17L18 7M9.5 7V4.5H14.5V7"
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
        </IconButton>
      </div>
    </article>
  );
}

export default PlanTile;
