import Link from "next/link";
import { Card, CardHeader } from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";
import { Pill } from "@/components/ui/pill";
import { StatCard } from "@/components/ui/stat-card";
import { formatKcal, formatMinutes } from "@/lib/format";
import type { Dashboard, SessionStatus } from "@/lib/types/api";
import { SESSION_STATUS_LABELS } from "@/lib/vocab";

export type SportCardProps = {
  sport: Dashboard["sport"];
};

const toneByStatus: Record<SessionStatus, "emerald" | "amber" | "sky" | "slate"> = {
  terminee: "emerald",
  en_cours: "amber",
  prevue: "sky",
  annulee: "slate",
};

/** Today's sessions, week minutes and streak (brief §16.2 point 6). */
export function SportCard({ sport }: SportCardProps) {
  const today = sport?.sessions_today ?? [];
  const planned = sport?.planned ?? [];
  const sessions = [...today, ...planned.filter((session) => !today.some((item) => item.id === session.id))];

  return (
    <Card>
      <CardHeader
        title="Sport"
        subtitle="Tes séances du jour et ta régularité."
        actions={
          <Link
            href="/dashboard/sport"
            className="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
          >
            Ouvrir Sport
          </Link>
        }
      />

      {sessions.length === 0 ? (
        <EmptyState
          compact
          className="mt-4"
          title="Aucune séance aujourd’hui"
          message="Laisse le coach te proposer une séance adaptée à ton objectif et à ton matériel."
          action={
            <Link
              href="/dashboard/sport?generer=1"
              className="inline-flex h-11 items-center rounded-2xl border border-emerald-700 bg-emerald-700 px-4 text-sm font-medium text-white transition hover:bg-emerald-800"
            >
              Générer
            </Link>
          }
        />
      ) : (
        <ul className="mt-4 space-y-2">
          {sessions.map((session) => (
            <li
              key={session.id}
              className="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2.5"
            >
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-slate-900">{session.title}</p>
                <p className="text-xs text-slate-500">
                  {formatMinutes(session.duration_min)}
                  {session.calories_burned ? ` · ${formatKcal(session.calories_burned)}` : ""}
                </p>
              </div>
              <Pill tone={toneByStatus[session.status] ?? "slate"} dot>
                {SESSION_STATUS_LABELS[session.status] ?? session.status}
              </Pill>
            </li>
          ))}
        </ul>
      )}

      <div className="mt-4 grid gap-3 sm:grid-cols-2">
        <StatCard
          label="Cette semaine"
          value={formatMinutes(sport?.week_minutes ?? 0)}
          caption={`${sport?.week_sessions ?? 0} séance${(sport?.week_sessions ?? 0) > 1 ? "s" : ""}`}
        />
        <StatCard
          label="Série"
          value={`${sport?.streak_days ?? 0} j`}
          caption="jours consécutifs avec une séance"
        />
      </div>
    </Card>
  );
}

export default SportCard;
