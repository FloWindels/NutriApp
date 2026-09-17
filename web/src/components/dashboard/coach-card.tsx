"use client";

import Link from "next/link";
import { Card, CardHeader } from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";
import { EstimatePill } from "@/components/ui/pill";
import { cn } from "@/lib/cn";
import type { Recommendation, RecommendationAction, RecommendationPriority } from "@/lib/types/api";
import { MEAL_TYPE_IN_SENTENCE } from "@/lib/vocab";

export type CoachCardProps = {
  recommendations: Recommendation[];
  /** `key` identifies the chip so the caller can show a loading state on it. */
  onAction: (action: RecommendationAction, key: string) => void;
  /** `${recommendation.id}-${index}` of the chip currently loading. */
  pendingKey?: string | null;
};

const stripeByPriority: Record<RecommendationPriority, string> = {
  1: "bg-rose-500",
  2: "bg-amber-500",
  3: "bg-slate-300",
};

/** Human label for an action chip — the verb matches what the chip really does. */
export function recommendationActionLabel(action: RecommendationAction): string {
  switch (action.kind) {
    case "ajouter_au_repas":
      return `Ajouter au ${MEAL_TYPE_IN_SENTENCE[action.meal_type]}`;
    case "ouvrir_recette":
      return "Voir la recette";
    case "ouvrir_stock":
      return "Voir le stock";
    case "generer_seance":
      return "Générer une séance";
    case "ajouter_courses":
      return "Ouvrir la liste de courses";
    case "ouvrir_planificateur":
      return "Ouvrir le planificateur";
    case "supprimer_stock":
      return "Gérer le stock";
    default:
      return "Ouvrir";
  }
}

/** Top recommendations of the day with their action chips (brief §7, §8). */
export function CoachCard({ recommendations, onAction, pendingKey = null }: CoachCardProps) {
  return (
    <Card>
      <CardHeader
        title="Coach du jour"
        subtitle="Les conseils calculés à partir de ta journée."
        actions={
          <Link
            href="/dashboard/recommandations-repas-journee"
            className="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
          >
            Voir tout
          </Link>
        }
      />

      {recommendations.length === 0 ? (
        <EmptyState
          compact
          className="mt-4"
          title="Rien à signaler pour l’instant"
          message="Enregistre tes repas de la journée et le coach te proposera des pistes."
          action={
            <Link
              href="/dashboard/historique-repas-journee"
              className="inline-flex h-11 items-center rounded-2xl border border-emerald-700 bg-emerald-700 px-4 text-sm font-medium text-white transition hover:bg-emerald-800"
            >
              Enregistrer un repas
            </Link>
          }
        />
      ) : (
        <ul className="mt-4 space-y-3">
          {recommendations.map((recommendation) => (
            <li
              key={recommendation.id}
              className="flex gap-3 rounded-2xl border border-slate-200 bg-white p-3"
            >
              <span
                aria-hidden="true"
                className={cn("w-1 shrink-0 rounded-full", stripeByPriority[recommendation.priority] ?? "bg-slate-300")}
              />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <p className="text-sm font-semibold text-slate-900">{recommendation.title}</p>
                  {recommendation.is_estimate ? <EstimatePill /> : null}
                </div>
                <p className="mt-1 text-sm leading-6 text-slate-600">{recommendation.message}</p>

                {recommendation.factors?.length ? (
                  <details className="mt-2">
                    <summary className="inline-flex cursor-pointer list-none items-center text-xs font-semibold text-slate-500 hover:text-slate-700">
                      Pourquoi ?
                    </summary>
                    <ul className="mt-1.5 space-y-1 text-xs leading-5 text-slate-500">
                      {recommendation.factors.map((factor, index) => (
                        <li key={index}>• {factor}</li>
                      ))}
                    </ul>
                  </details>
                ) : null}

                {recommendation.actions?.length ? (
                  <div className="mt-3 flex flex-wrap gap-2">
                    {recommendation.actions.map((action, index) => {
                      const key = `${recommendation.id}-${index}`;
                      const busy = pendingKey === key;
                      return (
                        <button
                          key={key}
                          type="button"
                          onClick={() => onAction(action, key)}
                          disabled={busy}
                          aria-busy={busy || undefined}
                          className="inline-flex h-10 items-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 text-sm font-medium text-emerald-800 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:opacity-60"
                        >
                          {busy ? "…" : recommendationActionLabel(action)}
                        </button>
                      );
                    })}
                  </div>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

export default CoachCard;
