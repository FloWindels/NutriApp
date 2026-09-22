"use client";

import Link from "next/link";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { DietScoreGauge } from "@/components/diet/diet-score-gauge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Modal } from "@/components/ui/modal";
import { Pill } from "@/components/ui/pill";
import { SectionHeader } from "@/components/ui/section-header";
import { SkeletonCard } from "@/components/ui/skeleton";
import { apiGet, getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { formatRelativeDay } from "@/lib/format";
import { queryKeys } from "@/lib/query-keys";
import { DIET_STATUT_LABELS, MEAL_TYPE_LABELS, REGIME_LABELS } from "@/lib/vocab";
import type {
  DataEnvelope,
  Diet,
  DietEvaluation,
  DietSummary,
  ListEnvelope,
  Profile,
} from "@/lib/types/api";

const RANGES = [7, 14, 30] as const;

/** « Régime reconnu » — principes du régime choisi et conformité des repas enregistrés (§9). */
export default function DietPage() {
  const [days, setDays] = useState<(typeof RANGES)[number]>(7);
  const [detail, setDetail] = useState<DietSummary | null>(null);

  const profileQuery = useQuery({
    queryKey: queryKeys.profile,
    queryFn: () => apiGet<Profile>("/profile"),
  });

  const regime = profileQuery.data?.regime_alimentaire ?? null;

  const dietQuery = useQuery({
    queryKey: queryKeys.diets.detail(regime ?? ""),
    queryFn: () => apiGet<DataEnvelope<Diet>>(`/diets/${regime}`),
    enabled: Boolean(regime),
  });

  const catalogQuery = useQuery({
    queryKey: queryKeys.diets.catalog,
    queryFn: () => apiGet<ListEnvelope<DietSummary>>("/diets"),
  });

  const evaluationQuery = useQuery({
    queryKey: queryKeys.diets.evaluate(days),
    queryFn: () => apiGet<DataEnvelope<DietEvaluation>>("/diets/evaluate", { days }),
    enabled: Boolean(regime),
  });

  const diet = dietQuery.data?.data;
  const evaluation = evaluationQuery.data?.data;

  return (
    <div className="space-y-6">
      <SectionHeader
        eyebrow="Régime reconnu"
        title={diet?.nom ?? (regime ? REGIME_LABELS[regime] : "Aucun régime choisi")}
        subtitle={diet?.description ?? "Choisis un régime dans ton profil pour suivre ta conformité."}
        tone="fuchsia"
        actions={
          <Link href="/dashboard/profil">
            <Button variant="secondary">Changer de régime</Button>
          </Link>
        }
      />

      {profileQuery.isPending ? <SkeletonCard /> : null}

      {profileQuery.isError ? (
        <ErrorState
          message={getErrorMessage(profileQuery.error)}
          onRetry={() => profileQuery.refetch()}
        />
      ) : null}

      {profileQuery.isSuccess && !regime ? (
        <EmptyState
          title="Aucun régime sélectionné"
          message="Indique ton régime alimentaire dans ton profil : Mavi’oh pourra alors vérifier la conformité de tes repas."
          action={
            <Link href="/dashboard/profil">
              <Button>Compléter mon profil</Button>
            </Link>
          }
        />
      ) : null}

      {diet ? (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card padding="md">
            <p className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
              Principes
            </p>
            <ul className="space-y-1.5 text-sm text-slate-700">
              {diet.principes.map((line) => (
                <li key={line} className="flex gap-2">
                  <span aria-hidden className="text-fuchsia-600">
                    •
                  </span>
                  <span>{line}</span>
                </li>
              ))}
            </ul>
            {diet.conseils.length > 0 ? (
              <>
                <p className="mb-2 mt-4 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                  Conseils
                </p>
                <ul className="space-y-1.5 text-sm text-slate-600">
                  {diet.conseils.map((line) => (
                    <li key={line}>{line}</li>
                  ))}
                </ul>
              </>
            ) : null}
          </Card>

          <Card padding="md">
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                  À privilégier
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {diet.aliments_conseilles.map((item) => (
                    <Pill key={item} tone="emerald">
                      {item}
                    </Pill>
                  ))}
                </div>
              </div>
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                  À limiter
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {diet.aliments_a_limiter.map((item) => (
                    <Pill key={item} tone="amber">
                      {item}
                    </Pill>
                  ))}
                </div>
              </div>
            </div>
          </Card>
        </div>
      ) : null}

      {regime ? (
        <Card padding="md">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm font-semibold text-slate-900">Conformité de tes repas</p>
            <div className="flex gap-1.5" role="group" aria-label="Période analysée">
              {RANGES.map((value) => (
                <button
                  key={value}
                  type="button"
                  aria-pressed={value === days}
                  onClick={() => setDays(value)}
                  className={cn(
                    "min-h-10 rounded-full border px-3 py-1.5 text-sm font-medium transition",
                    value === days
                      ? "border-fuchsia-600 bg-fuchsia-600 text-white"
                      : "border-slate-200 bg-white text-slate-600 hover:border-slate-300",
                  )}
                >
                  {value} j
                </button>
              ))}
            </div>
          </div>

          {evaluationQuery.isPending ? <SkeletonCard /> : null}

          {evaluationQuery.isError ? (
            <ErrorState
              message={getErrorMessage(evaluationQuery.error)}
              onRetry={() => evaluationQuery.refetch()}
            />
          ) : null}

          {evaluation && evaluation.statut === "donnees_insuffisantes" ? (
            <EmptyState
              title="Données insuffisantes"
              message={evaluation.message}
              action={
                <Link href="/dashboard/historique-repas-journee">
                  <Button>Enregistrer un repas</Button>
                </Link>
              }
            />
          ) : null}

          {evaluation && evaluation.statut !== "donnees_insuffisantes" ? (
            <div className="space-y-5">
              <div className="flex flex-wrap items-center gap-5">
                <DietScoreGauge
                  scorePct={evaluation.score_pct}
                  statut={evaluation.statut}
                  statutLabel={DIET_STATUT_LABELS[evaluation.statut]}
                />
                <p className="max-w-md text-sm text-slate-600">{evaluation.mention}</p>
              </div>

              {evaluation.conseils.length > 0 ? (
                <ul className="space-y-1.5 text-sm text-slate-700">
                  {evaluation.conseils.map((line) => (
                    <li key={line}>{line}</li>
                  ))}
                </ul>
              ) : null}

              {evaluation.ecarts.length > 0 ? (
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                    Écarts relevés
                  </p>
                  <ul className="divide-y divide-slate-100 rounded-2xl border border-slate-200">
                    {evaluation.ecarts.map((ecart, index) => (
                      <li key={`${ecart.date}-${index}`} className="px-4 py-2.5 text-sm">
                        <span className="font-medium text-slate-900">
                          {formatRelativeDay(ecart.date)}
                          {ecart.meal_type ? ` · ${MEAL_TYPE_LABELS[ecart.meal_type]}` : ""}
                        </span>
                        <span className="text-slate-600">
                          {ecart.item_label ? ` — ${ecart.item_label}` : ""}
                        </span>
                        <p className="text-slate-600">{ecart.explication}</p>
                      </li>
                    ))}
                  </ul>
                </div>
              ) : (
                <p className="text-sm text-emerald-700">
                  Aucun écart relevé sur la période : beau travail.
                </p>
              )}

              {evaluation.regimes_proches.length > 0 ? (
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                    Ton alimentation ressemble aussi à
                  </p>
                  <div className="flex flex-wrap gap-2">
                    {evaluation.regimes_proches.map((proche) => (
                      <ProcheChip key={proche.key} nom={proche.nom} score={proche.score_pct} raisons={proche.raisons} />
                    ))}
                  </div>
                </div>
              ) : null}
            </div>
          ) : null}
        </Card>
      ) : null}

      <Card padding="md">
        <p className="mb-3 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
          Tous les régimes pris en charge
        </p>
        {catalogQuery.isPending ? <SkeletonCard /> : null}
        {catalogQuery.isError ? (
          <ErrorState
            message={getErrorMessage(catalogQuery.error)}
            onRetry={() => catalogQuery.refetch()}
          />
        ) : null}
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {(catalogQuery.data?.data ?? []).map((item) => (
            <button
              key={item.key}
              type="button"
              onClick={() => setDetail(item)}
              className={cn(
                "rounded-2xl border p-4 text-left transition",
                item.key === regime
                  ? "border-fuchsia-300 bg-fuchsia-50"
                  : "border-slate-200 bg-white hover:border-slate-300",
              )}
            >
              <p className="text-sm font-semibold text-slate-900">{item.nom}</p>
              <p className="mt-1 line-clamp-2 text-xs text-slate-600">{item.description}</p>
              {!item.mineurs_autorise ? (
                <Pill tone="amber" className="mt-2">
                  Non proposé aux mineurs
                </Pill>
              ) : null}
            </button>
          ))}
        </div>
      </Card>

      <DietDetailModal diet={detail} onClose={() => setDetail(null)} />
    </div>
  );
}

function ProcheChip({ nom, score, raisons }: { nom: string; score: number; raisons: string[] }) {
  const [open, setOpen] = useState(false);

  return (
    <div>
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        className="min-h-10 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 transition hover:border-slate-300"
      >
        {nom} · {Math.round(score)} %
      </button>
      {open && raisons.length > 0 ? (
        <ul className="mt-1 max-w-xs space-y-0.5 text-xs text-slate-600">
          {raisons.map((raison) => (
            <li key={raison}>— {raison}</li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

function DietDetailModal({ diet, onClose }: { diet: DietSummary | null; onClose: () => void }) {
  const detailQuery = useQuery({
    queryKey: queryKeys.diets.detail(diet?.key ?? ""),
    queryFn: () => apiGet<DataEnvelope<Diet>>(`/diets/${diet?.key}`),
    enabled: Boolean(diet),
  });

  const full = detailQuery.data?.data;

  return (
    <Modal open={diet !== null} onClose={onClose} title={diet?.nom} size="lg">
      {diet ? (
        <div className="space-y-4 text-sm">
          <p className="text-slate-600">{diet.description}</p>

          {!diet.mineurs_autorise ? (
            <Pill tone="amber">Non proposé aux moins de 18 ans</Pill>
          ) : null}

          <div>
            <p className="mb-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
              Principes
            </p>
            <ul className="space-y-1 text-slate-700">
              {diet.principes.map((line) => (
                <li key={line}>— {line}</li>
              ))}
            </ul>
          </div>

          {full ? (
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <p className="mb-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                  À privilégier
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {full.aliments_conseilles.map((item) => (
                    <Pill key={item} tone="emerald">
                      {item}
                    </Pill>
                  ))}
                </div>
              </div>
              <div>
                <p className="mb-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                  À limiter
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {full.aliments_a_limiter.map((item) => (
                    <Pill key={item} tone="amber">
                      {item}
                    </Pill>
                  ))}
                </div>
              </div>
            </div>
          ) : null}

          {full && full.conseils.length > 0 ? (
            <div>
              <p className="mb-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                Conseils
              </p>
              <ul className="space-y-1 text-slate-600">
                {full.conseils.map((line) => (
                  <li key={line}>{line}</li>
                ))}
              </ul>
            </div>
          ) : null}
        </div>
      ) : null}
    </Modal>
  );
}
