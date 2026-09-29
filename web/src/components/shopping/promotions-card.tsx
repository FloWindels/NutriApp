"use client";

import Link from "next/link";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { ErrorState } from "@/components/ui/error-state";
import { Pill } from "@/components/ui/pill";
import { Overline } from "@/components/ui/section-header";
import { SkeletonList } from "@/components/ui/skeleton";
import { useOffre } from "@/hooks/use-offre";
import { apiGet, apiPost } from "@/lib/api-client";
import { formatDate, formatPercent, formatPrix } from "@/lib/format";
import { queryKeys } from "@/lib/query-keys";
import type {
  MagasinBref,
  Promotion,
  PromotionsResponse,
  RecherchePromotionsResponse,
} from "@/lib/types/api";

/**
 * Les promotions de la semaine du magasin choisi.
 *
 * Deux origines cohabitent et ne valent pas la même chose : ce qu'un humain a saisi, et ce qu'un
 * modèle a relevé sur une page web. La seconde est une piste, pas un prix. L'encart le dit avant
 * la liste, le répète sur chaque ligne non vérifiée, et cite la page d'où elle vient — pour qu'on
 * puisse aller voir soi-même plutôt que de nous croire sur parole.
 */
export function PromotionsCard({ magasin }: { magasin: MagasinBref }) {
  const queryClient = useQueryClient();
  const { peut } = useOffre();
  const [releve, setReleve] = useState<RecherchePromotionsResponse | null>(null);

  const promotions = useQuery({
    queryKey: queryKeys.magasins.promotions(magasin.id),
    queryFn: () => apiGet<PromotionsResponse>(`/magasins/${magasin.id}/promotions`),
  });

  const rechercher = useMutation({
    mutationFn: () =>
      apiPost<RecherchePromotionsResponse>(`/magasins/${magasin.id}/promotions/recherche`),
    onSuccess: async (reponse) => {
      setReleve(reponse);
      await queryClient.invalidateQueries({ queryKey: queryKeys.magasins.promotions(magasin.id) });
      await queryClient.invalidateQueries({ queryKey: queryKeys.shopping.all });
    },
  });

  const lignes = promotions.data?.data ?? [];
  const aVerifier = lignes.filter((promotion) => !promotion.verifiee).length;

  return (
    <Card padding="md" className="space-y-4">
      <CardHeader
        title={`Promotions de la semaine · ${magasin.enseigne_libelle}`}
        subtitle={
          lignes.length === 0
            ? "Rien de relevé pour aujourd’hui."
            : `${lignes.length} promotion${lignes.length > 1 ? "s" : ""} en cours${
                aVerifier > 0 ? ` · ${aVerifier} à vérifier` : ""
              }`
        }
        actions={
          peut("ia") ? (
            <Button
              size="sm"
              variant="secondary"
              onClick={() => rechercher.mutate()}
              loading={rechercher.isPending}
            >
              {rechercher.isPending ? "Je cherche…" : "Chercher les promos"}
            </Button>
          ) : null
        }
      />

      {promotions.isPending ? <SkeletonList rows={3} /> : null}

      {promotions.isError ? (
        <ErrorState error={promotions.error} compact onRetry={() => promotions.refetch()} />
      ) : null}

      {lignes.length > 0 ? (
        <>
          {/* L'avertissement parle des relevés automatiques. Quand un humain a tout vérifié,
              le laisser le viderait de son sens, et plus personne ne le lirait le jour où il
              compte vraiment. */}
          {aVerifier > 0 ? <Banner tone="warning">{promotions.data?.avertissement}</Banner> : null}

          <ul className="space-y-2">
            {lignes.map((promotion) => (
              <LignePromotion key={promotion.id} promotion={promotion} />
            ))}
          </ul>

          <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
            {demandeRepas(lignes, magasin) !== null ? (
              <Link
                href={`/dashboard/recettes?demande=${encodeURIComponent(demandeRepas(lignes, magasin) ?? "")}`}
                className="inline-flex h-9 items-center justify-center rounded-xl border border-emerald-700 bg-emerald-700 px-3 text-sm font-medium text-white transition hover:bg-emerald-800"
              >
                Composer un repas avec ces promos
              </Link>
            ) : null}
            <p className="text-xs text-slate-500">
              Mavi’oh part de ces produits pour écrire une recette. Vérifie les prix en magasin
              avant de compter dessus.
            </p>
          </div>
        </>
      ) : null}

      {promotions.isSuccess && lignes.length === 0 && releve === null ? (
        <p className="text-sm text-slate-500">
          Aucune promotion enregistrée pour aujourd’hui.{" "}
          {peut("ia")
            ? "Tu peux demander à Mavi’oh d’aller en chercher sur le web."
            : "Un administrateur peut en saisir depuis l’espace d’administration."}
        </p>
      ) : null}

      {rechercher.isError ? (
        <ErrorState error={rechercher.error} compact />
      ) : null}

      {releve ? <ResultatReleve releve={releve} /> : null}
    </Card>
  );
}

function LignePromotion({ promotion }: { promotion: Promotion }) {
  return (
    <li className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 rounded-2xl border border-slate-200 px-3 py-2.5">
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-slate-900">{promotion.libelle}</p>
        <p className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
          <Pill tone={promotion.verifiee ? "emerald" : "amber"} dot>
            {promotion.verifiee ? "Vérifiée" : "Non vérifiée"}
          </Pill>
          {promotion.produit?.rayon_libelle ? (
            <Pill tone="slate">{promotion.produit.rayon_libelle}</Pill>
          ) : null}
          {promotion.fin ? <span>Jusqu’au {formatDate(promotion.fin)}</span> : null}
          <SourcePromotion source={promotion.source} />
        </p>
      </div>

      <p className="shrink-0 text-right">
        <span className="block text-sm font-semibold tabular-nums text-slate-900">
          {formatPrix(promotion.prix_promotionnel)}
        </span>
        {promotion.prix_avant !== null ? (
          <span className="block text-[11px] tabular-nums text-slate-400 line-through">
            {formatPrix(promotion.prix_avant)}
          </span>
        ) : null}
        {promotion.remise_pourcent !== null ? (
          <span className="block text-[11px] font-medium text-rose-600">
            −{formatPercent(promotion.remise_pourcent)}
          </span>
        ) : null}
      </p>
    </li>
  );
}

/**
 * D'où vient la ligne. Une URL relevée sur le web s'ouvre dans un onglet neuf et sans
 * `referrer` : la page d'une enseigne n'a pas à savoir d'où on vient.
 */
function SourcePromotion({ source }: { source: string | null }) {
  if (!source) return null;

  const url = urlSure(source);
  if (url === null) return <span>Source : {source}</span>;

  return (
    <a
      href={url.href}
      target="_blank"
      rel="noreferrer noopener"
      className="text-emerald-800 underline underline-offset-2 hover:text-emerald-900"
    >
      Source : {url.hostname}
    </a>
  );
}

function ResultatReleve({ releve }: { releve: RecherchePromotionsResponse }) {
  return (
    <div className="space-y-2 rounded-2xl border border-slate-200 bg-slate-50 p-3">
      <Overline>Dernier relevé automatique</Overline>
      <p className="text-sm text-slate-700">{releve.message}</p>

      {releve.ia ? <p className="text-xs text-amber-900">{releve.avertissement}</p> : null}

      {releve.sources.length > 0 ? (
        <ul className="space-y-1 text-xs text-slate-600">
          {releve.sources.map((source) => {
            const url = urlSure(source.url);
            return (
              <li key={source.url} className="truncate">
                {url ? (
                  <a
                    href={url.href}
                    target="_blank"
                    rel="noreferrer noopener"
                    className="text-emerald-800 underline underline-offset-2"
                  >
                    {source.titre || url.hostname}
                  </a>
                ) : (
                  <span>{source.titre}</span>
                )}
                <span className="text-slate-400"> · {source.domaine}</span>
              </li>
            );
          })}
        </ul>
      ) : null}
    </div>
  );
}

/** Une adresse venue du web n'est un lien que si c'est du http(s) : `javascript:` n'en est pas un. */
function urlSure(valeur: string): URL | null {
  try {
    const url = new URL(valeur);
    return url.protocol === "http:" || url.protocol === "https:" ? url : null;
  } catch {
    return null;
  }
}

/**
 * La phrase envoyée au générateur de recettes : uniquement des libellés du CATALOGUE.
 *
 * Le libellé brut d'une promotion relevée sur le web n'a été confronté à rien. Le recopier ici le
 * ferait entrer dans la consigne donnée au modèle, c'est-à-dire à l'endroit exact où le dépôt
 * refuse de laisser passer du texte d'origine web — celui-ci n'est admis que comme donnée inerte,
 * dans un champ séparé et annoncé comme tel. On n'envoie donc que les promotions rattachées à un
 * produit connu, dont le nom vient de nous.
 */
function demandeRepas(promotions: Promotion[], magasin: MagasinBref): string | null {
  const produits = promotions
    .map((promotion) => promotion.produit?.libelle)
    .filter((libelle): libelle is string => typeof libelle === "string" && libelle.trim() !== "")
    .slice(0, 6);

  if (produits.length === 0) return null;

  return `Propose-moi un repas avec ce qui est en promotion chez ${magasin.enseigne_libelle} : ${produits.join(", ")}.`;
}

export default PromotionsCard;
