"use client";

import Link from "next/link";
import type { ReactNode } from "react";
import { Pill, type PillTone } from "@/components/ui/pill";
import { cn } from "@/lib/cn";
import { formatKcal, formatMinutes, formatTime } from "@/lib/format";
import type {
  CaloriesSource,
  PlanStatus,
  SessionLite,
  SessionStatus,
  WorkoutSession,
} from "@/lib/types/api";
import { PLAN_STATUS_LABELS, SESSION_STATUS_LABELS } from "@/lib/vocab";

/** Shared tones, badges and rows for every sport view. */

export const SESSION_TONES: Record<SessionStatus, PillTone> = {
  prevue: "sky",
  en_cours: "amber",
  terminee: "emerald",
  annulee: "slate",
};

export const PLAN_TONES: Record<PlanStatus, PillTone> = {
  prevu: "lime",
  realise: "emerald",
  annule: "slate",
};

export function SessionStatusPill({ status }: { status: SessionStatus }) {
  return (
    <Pill tone={SESSION_TONES[status] ?? "slate"} dot>
      {SESSION_STATUS_LABELS[status] ?? status}
    </Pill>
  );
}

export function PlanStatusPill({ status }: { status: PlanStatus }) {
  return (
    <Pill tone={PLAN_TONES[status] ?? "slate"} dot>
      {PLAN_STATUS_LABELS[status] ?? status}
    </Pill>
  );
}

/** « auto » / « manuel » marker next to a calories value (addendum §B). */
export function CaloriesSourcePill({ source }: { source: CaloriesSource }) {
  return source === "manuel" ? (
    <Pill tone="slate" title="Valeur saisie à la main">
      manuel
    </Pill>
  ) : (
    <Pill tone="amber" title="Estimation à partir de ton poids, du sport et de la durée">
      auto
    </Pill>
  );
}

export function GeneratedByBadge({
  generatedBy,
  model,
}: {
  generatedBy: "ia" | "regles" | null;
  model?: string | null;
}) {
  if (generatedBy === "ia") {
    return <Pill tone="violet" dot>{`Proposée par l’IA${model ? ` (${model})` : ""}`}</Pill>;
  }
  return (
    <Pill tone="emerald" dot>
      Règles Mavi’oh
    </Pill>
  );
}

export type SessionRowProps = {
  session: WorkoutSession | SessionLite;
  /** Whole row links to the session page when true. */
  href?: string;
  subtitle?: ReactNode;
  actions?: ReactNode;
  className?: string;
};

/** One session in a list — title, duration, kcal and status. */
export function SessionRow({ session, href, subtitle, actions, className }: SessionRowProps) {
  const full = session as Partial<WorkoutSession>;
  const details = [
    formatMinutes(session.duration_min),
    session.calories_burned ? formatKcal(session.calories_burned) : null,
    full.planned_at ? formatTime(full.planned_at) : null,
    session.sport_name && session.sport_name !== session.title ? session.sport_name : null,
  ].filter(Boolean) as string[];

  const body = (
    <>
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-slate-900">{session.title}</p>
        <p className="mt-0.5 truncate text-xs text-slate-500">{subtitle ?? details.join(" · ")}</p>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        {full.calories_source ? <CaloriesSourcePill source={full.calories_source} /> : null}
        <SessionStatusPill status={session.status} />
      </div>
    </>
  );

  const shell = cn(
    "flex min-h-[3.5rem] items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 transition",
    className,
  );

  return (
    <li>
      {href ? (
        <Link href={href} className={cn(shell, "hover:border-slate-300 hover:bg-slate-50")}>
          {body}
        </Link>
      ) : (
        <div className={shell}>
          {body}
          {actions ? <div className="flex shrink-0 items-center gap-2">{actions}</div> : null}
        </div>
      )}
    </li>
  );
}

/** Small circular icon placeholder used on plan rows. */
export function SportGlyph({ className }: { className?: string }) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        "grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-amber-50 text-amber-700",
        className,
      )}
    >
      <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none">
        <path
          d="M6.5 9.5v5M17.5 9.5v5M4 11v2M20 11v2M8.5 12h7"
          stroke="currentColor"
          strokeWidth="1.8"
          strokeLinecap="round"
        />
        <rect x="6.5" y="8" width="3" height="8" rx="1.2" stroke="currentColor" strokeWidth="1.8" />
        <rect x="14.5" y="8" width="3" height="8" rx="1.2" stroke="currentColor" strokeWidth="1.8" />
      </svg>
    </span>
  );
}
