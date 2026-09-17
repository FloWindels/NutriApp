import Link from "next/link";
import { Card } from "@/components/ui/card";
import { MacroPills } from "@/components/ui/macro-pills";
import { EstimatePill } from "@/components/ui/pill";
import { ProgressBar } from "@/components/ui/progress-bar";
import { cn } from "@/lib/cn";
import { formatKcal, formatSigned } from "@/lib/format";
import type { Totals } from "@/lib/types/api";

export type BudgetCardProps = {
  targets: Totals;
  consumed: Totals;
  remaining: Totals;
  /** Calories re-added by the sport sessions of the day (brief addendum §B). */
  caloriesBonus?: number;
  /** Server value when available (`/dashboard`), otherwise computed. */
  progressPct?: number;
  isEstimate?: boolean;
  /** Optional « Voir le détail » link (journal). */
  href?: string;
  linkLabel?: string;
  className?: string;
};

/**
 * Large remaining-calories card: progress bar, macro pills and the caption
 * « 1 260 consommées · objectif 2 500 · +105 sport » (brief §16.2).
 */
export function BudgetCard({
  targets,
  consumed,
  remaining,
  caloriesBonus = 0,
  progressPct,
  isEstimate = false,
  href,
  linkLabel = "Voir le détail",
  className,
}: BudgetCardProps) {
  const bonus = Math.max(0, Math.round(caloriesBonus));
  const targetKcal = Math.max(0, Math.round(targets?.calories ?? 0));
  const consumedKcal = Math.max(0, Math.round(consumed?.calories ?? 0));
  const budget = targetKcal + bonus;
  const remainingKcal = Math.round(remaining?.calories ?? budget - consumedKcal);
  const over = remainingKcal < 0;
  const progress = progressPct ?? (budget > 0 ? (consumedKcal / budget) * 100 : 0);

  return (
    <Card className={cn("flex flex-col gap-4", className)} aria-label="Budget calorique du jour">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
            Budget du jour
          </p>
          <p
            className={cn(
              "mt-1 text-4xl font-semibold tracking-tight sm:text-5xl",
              over ? "text-rose-700" : "text-slate-950",
            )}
          >
            {over ? `Dépassé de ${formatKcal(-remainingKcal)}` : formatKcal(remainingKcal)}
          </p>
          <p className="mt-1 text-sm text-slate-500">
            {over ? "Tu as dépassé ton budget du jour." : "restantes aujourd’hui"}
          </p>
        </div>
        {href ? (
          <Link
            href={href}
            className="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
          >
            {linkLabel}
          </Link>
        ) : null}
      </div>

      <ProgressBar
        value={progress}
        size="lg"
        label="Calories consommées"
        caption={`${formatKcal(consumedKcal, false)} / ${formatKcal(budget)}`}
      />

      <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-600">
        <span>{formatKcal(consumedKcal)} consommées</span>
        <span aria-hidden="true" className="text-slate-300">
          ·
        </span>
        <span>objectif {formatKcal(targetKcal)}</span>
        {bonus > 0 ? (
          <>
            <span aria-hidden="true" className="text-slate-300">
              ·
            </span>
            <span className="font-medium text-emerald-700">{formatSigned(bonus)} sport</span>
          </>
        ) : null}
        {isEstimate ? <EstimatePill /> : null}
      </div>

      <MacroPills
        size="md"
        values={{ proteins: consumed?.proteins, carbs: consumed?.carbs, fat: consumed?.fat }}
        targets={{ proteins: targets?.proteins, carbs: targets?.carbs, fat: targets?.fat }}
      />
    </Card>
  );
}

export default BudgetCard;
