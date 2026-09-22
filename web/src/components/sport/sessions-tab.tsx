"use client";

import { useMemo, useState } from "react";
import { SessionRow } from "@/components/sport/shared";
import { useSportSessions } from "@/components/sport/sport-api";
import { Card } from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { SkeletonList } from "@/components/ui/skeleton";
import { cn } from "@/lib/cn";
import { addDays, formatRelativeDay, todayIso } from "@/lib/format";
import { getErrorMessage } from "@/lib/api-client";
import type { SessionStatus, WorkoutSession } from "@/lib/types/api";

const FILTERS = [
  { key: "toutes", label: "Toutes" },
  { key: "terminee", label: "Terminées" },
  { key: "prevue", label: "Prévues" },
] as const;

type FilterKey = (typeof FILTERS)[number]["key"];

/** Onglet « Séances » — historique et séances à venir sur 60 jours. */
export function SessionsTab() {
  const [filter, setFilter] = useState<FilterKey>("toutes");

  const range = useMemo(() => {
    const today = todayIso();
    return { from: addDays(today, -30), to: addDays(today, 30) };
  }, []);

  const status: SessionStatus | undefined = filter === "toutes" ? undefined : filter;
  const query = useSportSessions({ ...range, ...(status ? { status } : {}) });

  const groups = useMemo(() => groupByDate(query.data ?? []), [query.data]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2" role="group" aria-label="Filtrer les séances">
        {FILTERS.map((item) => {
          const active = item.key === filter;
          return (
            <button
              key={item.key}
              type="button"
              aria-pressed={active}
              onClick={() => setFilter(item.key)}
              className={cn(
                "min-h-10 rounded-full border px-4 py-1.5 text-sm font-medium transition",
                active
                  ? "border-emerald-700 bg-emerald-700 text-white"
                  : "border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-900",
              )}
            >
              {item.label}
            </button>
          );
        })}
      </div>

      {query.isPending ? <SkeletonList /> : null}

      {query.isError ? (
        <ErrorState message={getErrorMessage(query.error)} onRetry={() => query.refetch()} />
      ) : null}

      {query.isSuccess && groups.length === 0 ? (
        <EmptyState
          title="Aucune séance sur cette période"
          message={
            filter === "toutes"
              ? "Génère une séance ou planifie un sport depuis le calendrier pour commencer."
              : "Change de filtre pour voir les autres séances."
          }
        />
      ) : null}

      {groups.map(([date, sessions]) => (
        <Card key={date} padding="md">
          <p className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
            {formatRelativeDay(date)}
          </p>
          <ul className="space-y-2">
            {sessions.map((session) => (
              <li key={session.id}>
                <SessionRow session={session} href={`/dashboard/sport/${session.id}`} />
              </li>
            ))}
          </ul>
        </Card>
      ))}
    </div>
  );
}

/** Regroupe les séances par date, de la plus récente à la plus ancienne. */
function groupByDate(sessions: WorkoutSession[]): Array<[string, WorkoutSession[]]> {
  const map = new Map<string, WorkoutSession[]>();

  for (const session of sessions) {
    const list = map.get(session.date) ?? [];
    list.push(session);
    map.set(session.date, list);
  }

  return [...map.entries()].sort((a, b) => b[0].localeCompare(a[0]));
}

export default SessionsTab;
