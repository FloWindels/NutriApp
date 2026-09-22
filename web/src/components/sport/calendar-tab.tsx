"use client";

import { useMemo, useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { GenerateForm } from "@/components/sport/generate-form";
import { LogPlanModal } from "@/components/sport/log-modal";
import { PlanModal } from "@/components/sport/plan-modal";
import { PlanStatusPill, SessionRow, SportGlyph } from "@/components/sport/shared";
import { monthBounds, sportApi, useInvalidateSport, useSportCalendar } from "@/components/sport/sport-api";
import { WeekPlanModal } from "@/components/sport/week-plan-modal";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Modal } from "@/components/ui/modal";
import { MonthCalendar, type CalendarMarkerTone } from "@/components/ui/month-calendar";
import { Skeleton, SkeletonCard } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { getErrorMessage } from "@/lib/api-client";
import { capitalize, formatKcal, formatLongDate, formatMinutes, formatTime, todayIso } from "@/lib/format";
import type { SportPlan } from "@/lib/types/api";
import { SPORT_LIEU_LABELS, labelFor } from "@/lib/vocab";

/** Onglet « Calendrier » — month grid, day panel and planning modals. */

export function CalendarTab() {
  const today = useMemo(() => todayIso(), []);
  const [month, setMonth] = useState(() => `${today.slice(0, 7)}-01`);
  const [selected, setSelected] = useState(today);
  const [planModal, setPlanModal] = useState<{ open: boolean; plan: SportPlan | null }>({
    open: false,
    plan: null,
  });
  const [weekOpen, setWeekOpen] = useState(false);
  const [logPlan, setLogPlan] = useState<SportPlan | null>(null);
  const [proposePlan, setProposePlan] = useState<SportPlan | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<SportPlan | null>(null);

  const invalidate = useInvalidateSport();
  const { success, error: toastError } = useToast();

  const bounds = useMemo(() => monthBounds(month), [month]);
  const calendarQuery = useSportCalendar(bounds.from, bounds.to);
  const calendar = calendarQuery.data;

  const markers = useMemo(() => {
    const map: Record<string, CalendarMarkerTone[]> = {};
    for (const day of calendar?.days ?? []) {
      const tones: CalendarMarkerTone[] = [];
      if (day.plans.some((plan) => plan.status === "prevu")) tones.push("planned");
      if (day.sessions.some((session) => session.status === "terminee") ||
        day.plans.some((plan) => plan.status === "realise")) {
        tones.push("done");
      }
      if (day.plans.some((plan) => plan.status === "annule")) tones.push("cancelled");
      if (tones.length > 0) map[day.date] = tones;
    }
    return map;
  }, [calendar]);

  const selectedDay = calendar?.days.find((day) => day.date === selected) ?? null;

  const deleteMutation = useMutation({
    mutationFn: ({ plan, serie }: { plan: SportPlan; serie: boolean }) =>
      sportApi.deletePlan(plan.id, serie),
    onSuccess: async (_response, variables) => {
      await invalidate();
      setDeleteTarget(null);
      success(variables.serie ? "Série supprimée." : "Séance prévue supprimée.");
    },
    onError: (error) => toastError("Suppression impossible", getErrorMessage(error)),
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-slate-500">
          {calendar
            ? `${calendar.summary.planned_count} prévue${
                calendar.summary.planned_count > 1 ? "s" : ""
              } · ${calendar.summary.done_count} réalisée${
                calendar.summary.done_count > 1 ? "s" : ""
              } · ${formatMinutes(calendar.summary.minutes_done)} · ${formatKcal(
                calendar.summary.calories_done,
              )}`
            : " "}
        </p>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => setWeekOpen(true)}>
            Planifier ma semaine
          </Button>
          <Button onClick={() => setPlanModal({ open: true, plan: null })}>Planifier</Button>
        </div>
      </div>

      {calendarQuery.isError ? (
        <ErrorState
          message={getErrorMessage(calendarQuery.error)}
          onRetry={() => void calendarQuery.refetch()}
          retrying={calendarQuery.isFetching}
        />
      ) : (
        <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,22rem)_1fr]">
          {calendarQuery.isPending ? (
            <Skeleton className="h-[22rem] w-full rounded-2xl" />
          ) : (
            <MonthCalendar
              month={month}
              onMonthChange={setMonth}
              selected={selected}
              onSelect={setSelected}
              markers={markers}
            />
          )}

          <div className="space-y-4">
            {calendarQuery.isPending ? (
              <SkeletonCard lines={3} />
            ) : (
              <Card>
                <CardHeader
                  title={capitalize(formatLongDate(selected))}
                  actions={
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() => setPlanModal({ open: true, plan: null })}
                    >
                      Planifier
                    </Button>
                  }
                />

                {(selectedDay?.plans.length ?? 0) === 0 && (selectedDay?.sessions.length ?? 0) === 0 ? (
                  <EmptyState
                    compact
                    className="mt-4"
                    title="Rien de prévu ce jour-là"
                    message="Planifie une séance et Mavi’oh te proposera les exercices adaptés."
                    action={
                      <Button onClick={() => setPlanModal({ open: true, plan: null })}>
                        Planifier une séance
                      </Button>
                    }
                  />
                ) : null}

                {(selectedDay?.plans.length ?? 0) > 0 ? (
                  <section className="mt-4">
                    <h3 className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                      Tes séances prévues
                    </h3>
                    <ul className="space-y-2">
                      {selectedDay?.plans.map((plan) => (
                        <li
                          key={plan.id}
                          className="rounded-2xl border border-slate-200 bg-white px-4 py-3"
                        >
                          <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="flex min-w-0 items-start gap-3">
                              <SportGlyph />
                              <div className="min-w-0">
                                <p className="truncate text-sm font-medium text-slate-900">
                                  {plan.sport_name}
                                </p>
                                <p className="mt-0.5 truncate text-xs text-slate-500">
                                  {[
                                    formatMinutes(plan.planned_duration_min),
                                    plan.planned_at ? formatTime(plan.planned_at) : null,
                                    plan.lieu ? labelFor(SPORT_LIEU_LABELS, plan.lieu) : null,
                                    plan.recurrence_id ? "série hebdomadaire" : null,
                                  ]
                                    .filter(Boolean)
                                    .join(" · ")}
                                </p>
                              </div>
                            </div>
                            <PlanStatusPill status={plan.status} />
                          </div>

                          {plan.notes ? (
                            <p className="mt-2 text-xs leading-5 text-slate-500">{plan.notes}</p>
                          ) : null}

                          <div className="mt-3 flex flex-wrap gap-2">
                            <Button
                              size="sm"
                              disabled={plan.status === "realise"}
                              onClick={() => setLogPlan(plan)}
                            >
                              Réaliser
                            </Button>
                            <Button size="sm" variant="secondary" onClick={() => setProposePlan(plan)}>
                              Proposer une séance
                            </Button>
                            <Button
                              size="sm"
                              variant="secondary"
                              onClick={() => setPlanModal({ open: true, plan })}
                            >
                              Modifier
                            </Button>
                            <Button size="sm" variant="danger" onClick={() => setDeleteTarget(plan)}>
                              Supprimer
                            </Button>
                          </div>
                        </li>
                      ))}
                    </ul>
                  </section>
                ) : null}

                {(selectedDay?.sessions.length ?? 0) > 0 ? (
                  <section className="mt-5">
                    <h3 className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                      Séances du jour
                    </h3>
                    <ul className="space-y-2">
                      {selectedDay?.sessions.map((session) => (
                        <SessionRow
                          key={session.id}
                          session={session}
                          href={`/dashboard/sport/${session.id}`}
                        />
                      ))}
                    </ul>
                  </section>
                ) : null}
              </Card>
            )}

            {proposePlan ? (
              <GenerateForm
                key={proposePlan.id}
                planId={proposePlan.id}
                date={proposePlan.date}
                defaultTime={proposePlan.planned_at}
                defaultDuration={proposePlan.planned_duration_min}
                onClose={() => setProposePlan(null)}
              />
            ) : null}
          </div>
        </div>
      )}

      <PlanModal
        open={planModal.open}
        onClose={() => setPlanModal({ open: false, plan: null })}
        date={selected}
        plan={planModal.plan}
      />
      <WeekPlanModal open={weekOpen} onClose={() => setWeekOpen(false)} date={selected} />
      <LogPlanModal open={logPlan !== null} onClose={() => setLogPlan(null)} plan={logPlan} />

      {deleteTarget?.recurrence_id ? (
        <Modal
          open
          onClose={() => setDeleteTarget(null)}
          locked={deleteMutation.isPending}
          title="Supprimer aussi les répétitions ?"
          size="sm"
          footer={
            <>
              <Button
                variant="secondary"
                onClick={() => setDeleteTarget(null)}
                disabled={deleteMutation.isPending}
              >
                Annuler
              </Button>
              <Button
                variant="danger"
                loading={deleteMutation.isPending}
                onClick={() => deleteMutation.mutate({ plan: deleteTarget, serie: false })}
              >
                Celle-ci seulement
              </Button>
              <Button
                variant="danger"
                loading={deleteMutation.isPending}
                onClick={() => deleteMutation.mutate({ plan: deleteTarget, serie: true })}
              >
                Toute la série
              </Button>
            </>
          }
        >
          <p className="text-sm leading-6 text-slate-600">
            Cette séance fait partie d’une série hebdomadaire. Tu peux ne supprimer que celle-ci ou toute
            la série à partir de cette date.
          </p>
        </Modal>
      ) : (
        <ConfirmDialog
          open={deleteTarget !== null}
          onClose={() => setDeleteTarget(null)}
          title="Supprimer cette séance prévue ?"
          message="Elle disparaîtra de ton calendrier."
          confirmLabel="Supprimer"
          danger
          onConfirm={async () => {
            if (!deleteTarget) return;
            await deleteMutation.mutateAsync({ plan: deleteTarget, serie: false });
          }}
        />
      )}

      {calendarQuery.isError ? null : calendarQuery.isFetching && !calendarQuery.isPending ? (
        <Banner tone="info">Mise à jour du calendrier…</Banner>
      ) : null}
    </div>
  );
}

export default CalendarTab;
