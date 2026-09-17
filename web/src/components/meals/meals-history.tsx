"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Sparkline } from "@/components/dashboard/sparkline";
import { Card, CardHeader } from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { StatCard } from "@/components/ui/stat-card";
import { Skeleton, SkeletonCard } from "@/components/ui/skeleton";
import { apiGet, getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { addDays, capitalize, formatDay, formatKcal, formatMinutes, formatPercent } from "@/lib/format";
import { queryKeys } from "@/lib/query-keys";
import type { DataEnvelope, History } from "@/lib/types/api";
import { CaloriesBarChart } from "./calories-bar-chart";

export type MealsHistoryProps = {
  /** Reference date (usually the journal date). */
  date: string;
  /** Clicking a day goes back to the journal on that date. */
  onSelectDay: (date: string) => void;
};

const RANGES = [7, 30, 90] as const;
type Range = (typeof RANGES)[number];

/** History view: ranges, calories chart, summary, weight line and the day table (brief §16.3). */
export function MealsHistory({ date, onSelectDay }: MealsHistoryProps) {
  const [range, setRange] = useState<Range>(7);
  const from = addDays(date, -(range - 1));

  const query = useQuery({
    queryKey: queryKeys.history({ from, to: date }),
    queryFn: () => apiGet<DataEnvelope<History>>("/history", { from, to: date }),
  });

  const rangeChips = (
    <div className="flex flex-wrap gap-2" role="group" aria-label="Période de l’historique">
      {RANGES.map((value) => (
        <button
          key={value}
          type="button"
          onClick={() => setRange(value)}
          aria-pressed={range === value}
          className={cn(
            "inline-flex h-10 items-center rounded-xl border px-3 text-sm font-medium transition",
            range === value
              ? "border-emerald-700 bg-emerald-700 text-white"
              : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50",
          )}
        >
          {value} jours
        </button>
      ))}
    </div>
  );

  if (query.isPending) {
    return (
      <div className="space-y-4">
        {rangeChips}
        <SkeletonCard lines={5} />
        <div className="grid gap-3 sm:grid-cols-3">
          <Skeleton className="h-24 w-full rounded-2xl" />
          <Skeleton className="h-24 w-full rounded-2xl" />
          <Skeleton className="h-24 w-full rounded-2xl" />
        </div>
        <SkeletonCard lines={4} />
      </div>
    );
  }

  const history = query.data?.data;

  if (query.isError || !history) {
    return (
      <div className="space-y-4">
        {rangeChips}
        <ErrorState
          message={getErrorMessage(query.error)}
          onRetry={() => void query.refetch()}
          retrying={query.isFetching}
        />
      </div>
    );
  }

  const days = [...(history.days ?? [])].sort((a, b) => a.date.localeCompare(b.date));
  const weights = [...(history.weights ?? [])].sort((a, b) => a.date.localeCompare(b.date));
  const loggedDays = days.filter((day) => (day.meals_count ?? 0) > 0);

  if (loggedDays.length === 0) {
    return (
      <div className="space-y-4">
        {rangeChips}
        <EmptyState
          title="Aucune journée enregistrée sur cette période"
          message="Enregistre tes repas pour voir ta courbe de calories, ton adhérence et ton poids."
          action={
            <button
              type="button"
              onClick={() => onSelectDay(date)}
              className="inline-flex h-11 items-center rounded-2xl border border-emerald-700 bg-emerald-700 px-4 text-sm font-medium text-white transition hover:bg-emerald-800"
            >
              Enregistrer un repas
            </button>
          }
        />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {rangeChips}

      <Card>
        <CardHeader
          title="Calories par jour"
          subtitle={`Comparées à ton objectif sur ${range} jours.`}
        />
        <CaloriesBarChart days={days} className="mt-4" />
      </Card>

      <div className="grid gap-3 sm:grid-cols-3">
        <StatCard
          label="Moyenne"
          value={history.summary?.avg_calories !== null && history.summary?.avg_calories !== undefined ? formatKcal(history.summary.avg_calories) : "—"}
          caption="par jour enregistré"
        />
        <StatCard
          label="Jours enregistrés"
          value={`${history.summary?.days_logged ?? loggedDays.length}`}
          caption={`sur ${range} jours`}
        />
        <StatCard
          label="Adhérence"
          value={history.summary?.adherence_pct !== null && history.summary?.adherence_pct !== undefined ? formatPercent(history.summary.adherence_pct) : "—"}
          caption="journées à ±10 % de l’objectif"
        />
      </div>

      {weights.length > 1 ? (
        <Card>
          <CardHeader title="Poids" subtitle="Évolution sur la période." />
          <Sparkline
            className="mt-3"
            points={weights.map((entry) => ({ label: entry.date, value: entry.weight_kg }))}
            ariaLabel={`Évolution du poids sur ${range} jours`}
            tone="sky"
          />
        </Card>
      ) : null}

      <Card padding="none">
        <div className="px-5 pt-5 sm:px-6">
          <CardHeader title="Détail des journées" subtitle="Clique sur une journée pour revenir au journal." />
        </div>
        <div className="mt-3 overflow-x-auto">
          <table className="w-full min-w-[34rem] border-collapse text-sm">
            <caption className="sr-only">Calories, objectif, repas et sport par journée</caption>
            <thead>
              <tr className="border-y border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <th scope="col" className="px-5 py-2.5 font-semibold sm:px-6">
                  Jour
                </th>
                <th scope="col" className="px-3 py-2.5 text-right font-semibold">
                  Calories
                </th>
                <th scope="col" className="px-3 py-2.5 text-right font-semibold">
                  Objectif
                </th>
                <th scope="col" className="px-3 py-2.5 text-right font-semibold">
                  Repas
                </th>
                <th scope="col" className="px-5 py-2.5 text-right font-semibold sm:px-6">
                  Sport
                </th>
              </tr>
            </thead>
            <tbody>
              {[...days].reverse().map((day) => (
                <tr key={day.date} className="border-b border-slate-100 last:border-b-0 hover:bg-slate-50">
                  <th scope="row" className="px-5 py-1.5 text-left font-normal sm:px-6">
                    <button
                      type="button"
                      onClick={() => onSelectDay(day.date)}
                      className="inline-flex h-10 items-center rounded-xl px-2 text-sm font-medium text-slate-900 underline-offset-4 transition hover:underline"
                    >
                      {capitalize(formatDay(day.date))}
                    </button>
                  </th>
                  <td className="px-3 py-1.5 text-right tabular-nums text-slate-900">
                    {formatKcal(day.calories)}
                  </td>
                  <td className="px-3 py-1.5 text-right tabular-nums text-slate-500">
                    {formatKcal(day.target_calories)}
                  </td>
                  <td className="px-3 py-1.5 text-right tabular-nums text-slate-500">{day.meals_count}</td>
                  <td className="px-5 py-1.5 text-right tabular-nums text-slate-500 sm:px-6">
                    {day.sport_minutes ? formatMinutes(day.sport_minutes) : "—"}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  );
}

export default MealsHistory;
