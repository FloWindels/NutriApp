"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { ActivityModal } from "@/components/sport/activity-modal";
import { GenerateForm } from "@/components/sport/generate-form";
import { LogPlanModal } from "@/components/sport/log-modal";
import { SessionRow } from "@/components/sport/shared";
import { coefficientPct, useSportSummary } from "@/components/sport/sport-api";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Modal } from "@/components/ui/modal";
import { SkeletonCard } from "@/components/ui/skeleton";
import { StatCard } from "@/components/ui/stat-card";
import { getErrorMessage } from "@/lib/api-client";
import { capitalize, formatKcal, formatMinutes, formatRelativeDay, formatTime, todayIso } from "@/lib/format";
import type { SportPlan } from "@/lib/types/api";

/** Onglet « Aujourd’hui » — summary, next plan, quick actions and today's sessions. */

export type TodayTabProps = {
  /** Opens the generation form straight away (`?generer=1`). */
  autoGenerate?: boolean;
};

export function TodayTab({ autoGenerate = false }: TodayTabProps) {
  const date = useMemo(() => todayIso(), []);
  const summaryQuery = useSportSummary();
  const [generating, setGenerating] = useState(autoGenerate);
  const [generatePlanId, setGeneratePlanId] = useState<number | null>(null);
  const [activityOpen, setActivityOpen] = useState(false);
  const [logPlan, setLogPlan] = useState<SportPlan | null>(null);
  const [explicationOpen, setExplicationOpen] = useState(false);

  const summary = summaryQuery.data;

  const nextPlan: SportPlan | null = useMemo(() => {
    const plans = summary?.week_plans ?? [];
    return (
      plans
        .filter((plan) => plan.status === "prevu" && plan.date >= date)
        .sort(
          (a, b) =>
            a.date.localeCompare(b.date) || (a.planned_at ?? "").localeCompare(b.planned_at ?? ""),
        )[0] ?? null
    );
  }, [summary, date]);

  if (summaryQuery.isPending) {
    return (
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-3">
          <SkeletonCard lines={1} />
          <SkeletonCard lines={1} />
          <SkeletonCard lines={1} />
        </div>
        <SkeletonCard lines={3} />
      </div>
    );
  }

  if (summaryQuery.isError || !summary) {
    return (
      <ErrorState
        message={getErrorMessage(summaryQuery.error)}
        onRetry={() => void summaryQuery.refetch()}
        retrying={summaryQuery.isFetching}
      />
    );
  }

  const pct = coefficientPct(summary.nutrition.coefficient ?? summary.coef_calories);
  const bonus = summary.nutrition.calories_bonus ?? 0;
  const displayPlan = summary.next_plan ?? nextPlan;

  return (
    <div className="space-y-4">
      {summary.active_session_id ? (
        <Banner
          tone="warning"
          title="Une séance est en cours."
          action={
            <Link
              href={`/dashboard/sport/${summary.active_session_id}`}
              className="inline-flex h-10 items-center rounded-xl border border-amber-300 bg-white px-3 text-sm font-medium text-amber-900 transition hover:bg-amber-100"
            >
              Reprendre la séance en cours
            </Link>
          }
        >
          Reprends là où tu t’es arrêté.
        </Banner>
      ) : null}

      <div className="grid gap-3 sm:grid-cols-3">
        <StatCard
          tone="amber"
          label="Aujourd’hui"
          value={formatMinutes(summary.today.minutes)}
          caption={`${formatKcal(summary.today.calories_burned)} brûlées`}
          estimate={summary.nutrition.is_estimate}
        />
        <StatCard
          tone="emerald"
          label="Cette semaine"
          value={formatMinutes(summary.week.minutes)}
          caption={`${summary.week.sessions} séance${summary.week.sessions > 1 ? "s" : ""} · ${formatKcal(
            summary.week.calories,
          )}`}
        />
        <StatCard
          tone="lime"
          label="Série"
          value={`${summary.streak_days} j`}
          caption="jours consécutifs avec une séance"
        />
      </div>

      <Card tone="emerald" padding="sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm font-medium text-emerald-950">
            {`+${formatKcal(bonus)} ajoutés à ton budget (${pct} %)`}
          </p>
          <Button variant="secondary" size="sm" onClick={() => setExplicationOpen(true)}>
            Comment ça marche ?
          </Button>
        </div>
      </Card>

      <div className="flex flex-wrap gap-2">
        <Button
          size="lg"
          onClick={() => {
            setGeneratePlanId(null);
            setGenerating(true);
          }}
        >
          Générer une séance
        </Button>
        <Button size="lg" variant="secondary" onClick={() => setActivityOpen(true)}>
          Activité rapide
        </Button>
      </div>

      {generating ? (
        <GenerateForm
          key={generatePlanId ?? "libre"}
          planId={generatePlanId}
          date={generatePlanId ? (nextPlan?.date ?? date) : date}
          defaultTime={generatePlanId ? (nextPlan?.planned_at ?? null) : null}
          defaultDuration={generatePlanId ? nextPlan?.planned_duration_min : undefined}
          onClose={() => {
            setGenerating(false);
            setGeneratePlanId(null);
          }}
        />
      ) : null}

      {displayPlan ? (
        <Card>
          <CardHeader
            title="Prochaine séance"
            subtitle={`${capitalize(formatRelativeDay(displayPlan.date))} · ${displayPlan.sport_name} · ${formatMinutes(
              displayPlan.planned_duration_min,
            )}${displayPlan.planned_at ? ` · ${formatTime(displayPlan.planned_at)}` : ""}`}
          />
          <div className="mt-4 flex flex-wrap gap-2">
            <Button
              variant="secondary"
              disabled={!nextPlan}
              onClick={() => {
                if (!nextPlan) return;
                setGeneratePlanId(nextPlan.id);
                setGenerating(true);
              }}
            >
              Proposer une séance
            </Button>
            <Button disabled={!nextPlan} onClick={() => setLogPlan(nextPlan)}>
              J’ai fait cette séance
            </Button>
          </div>
        </Card>
      ) : null}

      <Card>
        <CardHeader title="Tes séances du jour" subtitle={summary.recos.pre ?? undefined} />
        {summary.today.sessions.length === 0 ? (
          <EmptyState
            compact
            className="mt-4"
            title="Aucune séance aujourd’hui"
            message="Génère une séance adaptée à ton contexte ou enregistre une activité déjà faite."
            action={
              <Button
                onClick={() => {
                  setGeneratePlanId(null);
                  setGenerating(true);
                }}
              >
                Générer une séance
              </Button>
            }
          />
        ) : (
          <ul className="mt-4 space-y-2">
            {summary.today.sessions.map((session) => (
              <SessionRow key={session.id} session={session} href={`/dashboard/sport/${session.id}`} />
            ))}
          </ul>
        )}
        {summary.recos.post ? (
          <Banner tone="info" className="mt-4">
            {summary.recos.post}
          </Banner>
        ) : null}
      </Card>

      <Modal
        open={explicationOpen}
        onClose={() => setExplicationOpen(false)}
        title="Bonus sport"
        size="sm"
      >
        <p className="text-sm leading-6 text-slate-600">
          {summary.nutrition.explication ??
            `${formatKcal(summary.nutrition.calories_burned)} brûlées aujourd’hui (estimation MET, hors métabolisme de base) : ${pct} % sont ajoutées à ton budget, soit +${formatKcal(
              bonus,
            )} à consommer.`}
        </p>
      </Modal>

      <ActivityModal open={activityOpen} onClose={() => setActivityOpen(false)} date={date} />
      <LogPlanModal open={logPlan !== null} onClose={() => setLogPlan(null)} plan={logPlan} />
    </div>
  );
}

export default TodayTab;
