"use client";

import { useId, useState, type ReactNode } from "react";
import { EstimatePill, Pill } from "@/components/ui/pill";
import { cn } from "@/lib/cn";
import type {
  Recommendation,
  RecommendationAction,
  RecommendationActionKind,
  RecommendationPriority,
} from "@/lib/types/api";
import { MEAL_TYPE_IN_SENTENCE } from "@/lib/vocab";
import { RecommendationBadge } from "./recommendation-icon";

/* ------------------------------------------------------------------ */
/* Priority vocabulary (brief §8 : 1 sécurité, 2 objectif, 3 confort)  */
/* ------------------------------------------------------------------ */

const stripeByPriority: Record<RecommendationPriority, string> = {
  1: "bg-rose-500",
  2: "bg-amber-500",
  3: "bg-slate-300",
};

const borderByPriority: Record<RecommendationPriority, string> = {
  1: "border-rose-200",
  2: "border-amber-200",
  3: "border-slate-200",
};

const eyebrowByPriority: Record<RecommendationPriority, string> = {
  1: "text-rose-700",
  2: "text-amber-800",
  3: "text-slate-500",
};

export const PRIORITY_LABELS: Record<RecommendationPriority, string> = {
  1: "Sécurité",
  2: "Objectif du jour",
  3: "Confort",
};

/** Verb matching what the button really does on the web client. */
export function coachActionLabel(action: RecommendationAction): string {
  switch (action.kind) {
    case "ajouter_au_repas":
      return `Ajouter au ${MEAL_TYPE_IN_SENTENCE[action.meal_type] ?? "repas"}`;
    case "ouvrir_recette":
      return "Voir la recette";
    case "ouvrir_stock":
      return "Voir le stock";
    case "generer_seance":
      return "Générer une séance";
    case "ajouter_courses":
      return "Ajouter aux courses";
    case "ouvrir_planificateur":
      return "Ouvrir le planificateur";
    case "supprimer_stock":
      return "Retirer du stock";
    default:
      return "Ouvrir";
  }
}

/* ------------------------------------------------------------------ */
/* Icons                                                               */
/* ------------------------------------------------------------------ */

const stroke = {
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 1.8,
  strokeLinecap: "round" as const,
  strokeLinejoin: "round" as const,
};

const actionGlyphs: Record<RecommendationActionKind, ReactNode> = {
  ajouter_au_repas: (
    <>
      <path {...stroke} d="M12 5V19" />
      <path {...stroke} d="M5 12H19" />
    </>
  ),
  ouvrir_recette: (
    <>
      <path {...stroke} d="M4 5.5C6.5 4.5 9.5 4.5 12 6V19C9.5 17.5 6.5 17.5 4 18.5V5.5Z" />
      <path {...stroke} d="M20 5.5C17.5 4.5 14.5 4.5 12 6V19C14.5 17.5 17.5 17.5 20 18.5V5.5Z" />
    </>
  ),
  ouvrir_stock: (
    <>
      <path {...stroke} d="M5 4H19V20H5V4Z" />
      <path {...stroke} d="M5 11H19" />
      <path {...stroke} d="M8.5 7.2V8.4M8.5 14.2V15.6" />
    </>
  ),
  generer_seance: (
    <>
      <path {...stroke} d="M7 9V15M17 9V15" />
      <path {...stroke} d="M4.5 10.5V13.5M19.5 10.5V13.5" />
      <path {...stroke} d="M7 12H17" />
    </>
  ),
  ajouter_courses: (
    <>
      <path {...stroke} d="M4 8H20L18.5 18H5.5L4 8Z" />
      <path {...stroke} d="M9 8L10.5 4H13.5L15 8" />
      <path {...stroke} d="M10 12V15M14 12V15" />
    </>
  ),
  ouvrir_planificateur: (
    <>
      <rect {...stroke} x="4" y="5.5" width="16" height="14" rx="2.5" />
      <path {...stroke} d="M4 10H20" />
      <path {...stroke} d="M8.5 3.5V6.5M15.5 3.5V6.5" />
    </>
  ),
  supprimer_stock: (
    <>
      <path {...stroke} d="M5 7H19" />
      <path {...stroke} d="M9.5 7V5H14.5V7" />
      <path {...stroke} d="M6.5 7L7.5 19H16.5L17.5 7" />
    </>
  ),
};

function ActionIcon({ kind }: { kind: RecommendationActionKind }) {
  return (
    <svg viewBox="0 0 24 24" className="h-4 w-4" aria-hidden="true">
      {actionGlyphs[kind] ?? (
        <path {...stroke} d="M5 12H19M14 7L19 12L14 17" />
      )}
    </svg>
  );
}

function ChevronIcon({ open }: { open: boolean }) {
  return (
    <svg
      viewBox="0 0 20 20"
      className={cn("h-4 w-4 transition-transform", open && "rotate-180")}
      aria-hidden="true"
    >
      <path {...stroke} d="M5 8L10 13L15 8" />
    </svg>
  );
}

/* ------------------------------------------------------------------ */
/* Factors                                                             */
/* ------------------------------------------------------------------ */

/** `factors[]` is a list of strings, but the API may also send `{label, value}`. */
function factorText(factor: unknown): string {
  if (typeof factor === "string") return factor;
  if (typeof factor === "number") return String(factor);
  if (factor && typeof factor === "object") {
    const record = factor as Record<string, unknown>;
    const label = record.label ?? record.name;
    if (typeof label === "string" && record.value !== undefined) {
      return `${label} : ${String(record.value)}`;
    }
    return Object.values(record)
      .map((value) => String(value))
      .join(" · ");
  }
  return "";
}

/* ------------------------------------------------------------------ */
/* Card                                                                */
/* ------------------------------------------------------------------ */

export type RecommendationCardProps = {
  recommendation: Recommendation;
  /** `key` is `${id}-${index}` so the page can show a spinner on one button. */
  onAction: (action: RecommendationAction, key: string) => void;
  onIgnore: (recommendation: Recommendation) => void;
  onRestore: (recommendation: Recommendation) => void;
  pendingKey?: string | null;
  /** True while the status mutation for this card is in flight. */
  statusPending?: boolean;
};

/**
 * One coach recommendation: priority stripe, type icon, title, message,
 * « Pourquoi ? » disclosure with the factors and the mapped action buttons.
 */
export function RecommendationCard({
  recommendation,
  onAction,
  onIgnore,
  onRestore,
  pendingKey = null,
  statusPending = false,
}: RecommendationCardProps) {
  const [open, setOpen] = useState(false);
  const panelId = useId();
  const ignored = recommendation.status === "ignoree";
  const priority = recommendation.priority ?? 3;
  const factors = (recommendation.factors ?? []).map(factorText).filter(Boolean);

  return (
    <article
      className={cn(
        "flex overflow-hidden rounded-[1.5rem] border bg-white shadow-[0_14px_40px_rgba(15,23,42,0.05)]",
        ignored ? "border-slate-200 opacity-75" : borderByPriority[priority] ?? "border-slate-200",
      )}
    >
      <span
        aria-hidden="true"
        className={cn("w-1.5 shrink-0", ignored ? "bg-slate-200" : stripeByPriority[priority] ?? "bg-slate-300")}
      />

      <div className="min-w-0 flex-1 p-4 sm:p-5">
        <div className="flex items-start gap-3">
          <RecommendationBadge type={recommendation.type} priority={priority} />

          <div className="min-w-0 flex-1">
            <p
              className={cn(
                "text-[11px] font-bold uppercase tracking-[0.08em]",
                ignored ? "text-slate-400" : eyebrowByPriority[priority] ?? "text-slate-500",
              )}
            >
              {PRIORITY_LABELS[priority] ?? PRIORITY_LABELS[3]}
            </p>
            <h3 className="mt-0.5 text-base font-semibold leading-snug text-slate-900">
              {recommendation.title}
            </h3>
          </div>

          {recommendation.is_estimate ? <EstimatePill className="mt-1 shrink-0" /> : null}
        </div>

        <p className="mt-3 text-sm leading-6 text-slate-600">{recommendation.message}</p>

        {factors.length ? (
          <div className="mt-2">
            <button
              type="button"
              onClick={() => setOpen((value) => !value)}
              aria-expanded={open}
              aria-controls={panelId}
              className="inline-flex h-10 items-center gap-1.5 rounded-xl px-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400/40"
            >
              <ChevronIcon open={open} />
              Pourquoi ?
            </button>

            <div id={panelId} hidden={!open} className="mt-1 rounded-2xl border border-slate-200 bg-slate-50 p-3">
              <p className="text-[11px] font-bold uppercase tracking-[0.06em] text-slate-500">
                Ce que le coach a regardé
              </p>
              <ul className="mt-2 space-y-1.5">
                {factors.map((factor, index) => (
                  <li key={index} className="flex gap-2 text-xs leading-5 text-slate-600">
                    <span aria-hidden="true" className="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-slate-400" />
                    <span>{factor}</span>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        ) : null}

        <div className="mt-4 flex flex-wrap items-center gap-2">
          {(recommendation.actions ?? []).map((action, index) => {
            const key = `${recommendation.id}-${index}`;
            const busy = pendingKey === key;
            return (
              <button
                key={key}
                type="button"
                onClick={() => onAction(action, key)}
                disabled={busy}
                aria-busy={busy || undefined}
                className="inline-flex h-10 items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 text-sm font-medium text-emerald-800 transition hover:border-emerald-300 hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600/30 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {busy ? (
                  <span aria-hidden="true" className="h-4 w-4 animate-spin rounded-full border-2 border-emerald-300 border-t-transparent" />
                ) : (
                  <ActionIcon kind={action.kind} />
                )}
                {coachActionLabel(action)}
              </button>
            );
          })}

          {ignored ? (
            <div className="ml-auto flex items-center gap-2">
              <Pill tone="slate">Ignorée</Pill>
              <button
                type="button"
                onClick={() => onRestore(recommendation)}
                disabled={statusPending}
                aria-busy={statusPending || undefined}
                className="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400/40 disabled:opacity-60"
              >
                Rétablir
              </button>
            </div>
          ) : (
            <button
              type="button"
              onClick={() => onIgnore(recommendation)}
              disabled={statusPending}
              aria-busy={statusPending || undefined}
              aria-label={`Ignorer le conseil : ${recommendation.title}`}
              className="ml-auto inline-flex h-10 items-center rounded-xl px-3 text-sm font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400/40 disabled:opacity-60"
            >
              Ignorer
            </button>
          )}
        </div>
      </div>
    </article>
  );
}

export default RecommendationCard;
