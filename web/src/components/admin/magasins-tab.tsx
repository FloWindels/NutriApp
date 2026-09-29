"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { ErrorState } from "@/components/ui/error-state";
import { Field, SelectField } from "@/components/ui/field";
import { Pill } from "@/components/ui/pill";
import { SkeletonList } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { useMagasins } from "@/hooks/use-magasins";
import { apiDelete, apiGet, apiPost, apiPut, getErrorMessage, isApiError } from "@/lib/api-client";
import { addDays, formatDate, formatPrix, parseDecimal, todayIso } from "@/lib/format";
import { queryKeys } from "@/lib/query-keys";
import type {
  MagasinProduit,
  MagasinProduitsResponse,
  Promotion,
  PromotionsResponse,
  RayonCle,
} from "@/lib/types/api";

const PAR_PAGE = 25;

/** Le formulaire de promotion, en création comme en correction. */
type Formulaire = {
  /** Non nul quand on corrige une promotion existante plutôt que d'en créer une. */
  id: number | null;
  libelle: string;
  magasinProduitId: number | null;
  produitLibelle: string;
  prixPromotionnel: string;
  prixAvant: string;
  debut: string;
  fin: string;
};

function formulaireVide(): Formulaire {
  return {
    id: null,
    libelle: "",
    magasinProduitId: null,
    produitLibelle: "",
    prixPromotionnel: "",
    prixAvant: "",
    debut: todayIso(),
    fin: addDays(todayIso(), 7),
  };
}

/**
 * Enseignes, assortiments et promotions, côté administration.
 *
 * Ce que cet écran peut et ne peut pas faire mérite d'être dit franchement, parce que
 * l'information manquante se paierait en prix faux :
 *
 *  - les prix du catalogue viennent d'un relevé importé en ligne de commande. On les CONSULTE
 *    ici, avec leur date ; on ne les réécrit pas à la main dans un formulaire, sinon plus
 *    personne ne saurait de quand date quoi ;
 *  - le prix qui se corrige ici, c'est celui d'une promotion. C'est aussi lui que la liste de
 *    courses retient quand il existe, donc c'est bien lui qui change ce que les gens voient ;
 *  - une promotion relevée par l'IA arrive NON VÉRIFIÉE. La marquer vérifiée est un acte
 *    humain : ça veut dire que quelqu'un est allé regarder.
 */
export function MagasinsTab() {
  const { magasins, rayons, chargement } = useMagasins();
  const [enseigneId, setEnseigneId] = useState<number | null>(null);
  const [recherche, setRecherche] = useState("");
  const [terme, setTerme] = useState("");
  const [rayon, setRayon] = useState<RayonCle | "">("");
  const [page, setPage] = useState(1);
  const [formulaire, setFormulaire] = useState<Formulaire>(formulaireVide);

  const magasinId = enseigneId ?? magasins[0]?.id ?? null;

  function changerEnseigne(id: number) {
    setEnseigneId(id);
    setPage(1);
    setFormulaire(formulaireVide());
  }

  if (chargement) return <SkeletonList rows={4} />;

  if (magasinId === null) {
    return (
      <Banner tone="warning">
        Aucune enseigne n’est enregistrée. Lance les données de départ (`MagasinSeeder`) avant
        d’espérer voir des prix ici.
      </Banner>
    );
  }

  return (
    <div className="space-y-4">
      <Banner tone="info">
        Les prix du catalogue sont des relevés importés par la commande{" "}
        <code className="font-mono text-xs">mavioh:magasin-importer</code> : ils se consultent ici
        avec leur date, ils ne se réécrivent pas à la main. Le prix qui se corrige depuis cet
        écran est celui d’une promotion — c’est lui que la liste de courses retient quand il
        existe.
      </Banner>

      <Card padding="md">
        <CardHeader title="Enseigne" subtitle="Quatre enseignes, écrites en dur côté serveur." />
        <div className="mt-3 flex flex-wrap gap-2">
          {magasins.map((magasin) => (
            <button
              key={magasin.id}
              type="button"
              onClick={() => changerEnseigne(magasin.id)}
              className={`min-h-10 rounded-xl border px-4 py-2 text-sm font-medium transition ${
                magasin.id === magasinId
                  ? "border-emerald-700 bg-emerald-700 text-white"
                  : "border-slate-200 bg-white text-slate-600 hover:bg-slate-50"
              }`}
            >
              {magasin.enseigne_libelle}
              {magasin.produits_count !== undefined ? (
                <span className="ml-2 opacity-70">{magasin.produits_count}</span>
              ) : null}
              {magasin.actif ? "" : " · inactive"}
            </button>
          ))}
        </div>
      </Card>

      <Assortiment
        magasinId={magasinId}
        terme={terme}
        rayon={rayon}
        page={page}
        recherche={recherche}
        rayons={rayons}
        onRecherche={setRecherche}
        onChercher={() => {
          setTerme(recherche.trim());
          setPage(1);
        }}
        onRayon={(valeur) => {
          setRayon(valeur);
          setPage(1);
        }}
        onPage={setPage}
        onCorriger={(produit) =>
          setFormulaire({
            ...formulaireVide(),
            libelle: produit.libelle,
            magasinProduitId: produit.id,
            produitLibelle: produit.libelle,
            prixAvant: produit.prix_indicatif === null ? "" : String(produit.prix_indicatif),
          })
        }
      />

      <Promotions
        magasinId={magasinId}
        formulaire={formulaire}
        onFormulaire={setFormulaire}
      />
    </div>
  );
}

/* ------------------------------------------------------------------ */

function Assortiment({
  magasinId,
  terme,
  rayon,
  page,
  recherche,
  rayons,
  onRecherche,
  onChercher,
  onRayon,
  onPage,
  onCorriger,
}: {
  magasinId: number;
  terme: string;
  rayon: RayonCle | "";
  page: number;
  recherche: string;
  rayons: { cle: RayonCle; libelle: string }[];
  onRecherche: (valeur: string) => void;
  onChercher: () => void;
  onRayon: (valeur: RayonCle | "") => void;
  onPage: (page: number) => void;
  onCorriger: (produit: MagasinProduit) => void;
}) {
  const params = { q: terme || undefined, rayon: rayon || undefined, page, per_page: PAR_PAGE };

  const produits = useQuery({
    queryKey: queryKeys.magasins.produits(magasinId, params),
    queryFn: () =>
      apiGet<MagasinProduitsResponse>(`/magasins/${magasinId}/produits`, {
        q: terme || null,
        rayon: rayon || null,
        page,
        per_page: PAR_PAGE,
      }),
  });

  const meta = produits.data?.meta;

  return (
    <Card padding="md" className="space-y-3">
      <CardHeader
        title="Assortiment"
        subtitle={meta ? `${meta.total} produits · page ${meta.current_page} sur ${meta.last_page}` : undefined}
      />

      <form
        className="flex flex-wrap items-end gap-2"
        onSubmit={(event) => {
          event.preventDefault();
          onChercher();
        }}
      >
        <div className="min-w-[14rem] flex-1">
          <Field
            label="Rechercher"
            value={recherche}
            onChange={(event) => onRecherche(event.target.value)}
            placeholder="Libellé ou marque"
          />
        </div>
        <SelectField
          label="Rayon"
          className="sm:w-56"
          value={rayon}
          onChange={(event) => onRayon(event.target.value as RayonCle | "")}
        >
          <option value="">Tous les rayons</option>
          {rayons.map((item) => (
            <option key={item.cle} value={item.cle}>
              {item.libelle}
            </option>
          ))}
        </SelectField>
        <Button type="submit" variant="secondary">
          Chercher
        </Button>
      </form>

      {produits.isPending ? <SkeletonList rows={4} /> : null}
      {produits.isError ? (
        <ErrorState error={produits.error} compact onRetry={() => produits.refetch()} />
      ) : null}

      <ul className="space-y-2">
        {produits.isSuccess && produits.data.data.length === 0 ? (
          <li className="text-sm text-slate-500">Aucun produit ne correspond.</li>
        ) : null}
        {produits.data?.data.map((produit) => (
          <li
            key={produit.id}
            className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 px-3 py-2.5"
          >
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-slate-900">{produit.libelle}</p>
              <p className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <Pill tone="slate">{produit.rayon_libelle}</Pill>
                {produit.marque ? <span>{produit.marque}</span> : null}
                {produit.code_barres ? (
                  <span className="font-mono">{produit.code_barres}</span>
                ) : null}
                {produit.prix_par_unite_base !== null ? (
                  <span>
                    {formatPrix(produit.prix_par_unite_base)} / {produit.unite}
                  </span>
                ) : null}
              </p>
            </div>

            <div className="flex shrink-0 items-center gap-3">
              <p className="text-right">
                <span className="block text-sm font-semibold tabular-nums text-slate-900">
                  {formatPrix(produit.prix_indicatif)}
                </span>
                <span className="block text-[11px] text-slate-400">
                  {produit.prix_maj_le ? `relevé le ${formatDate(produit.prix_maj_le)}` : "sans date"}
                </span>
              </p>
              <Button size="sm" variant="secondary" onClick={() => onCorriger(produit)}>
                Corriger le prix
              </Button>
            </div>
          </li>
        ))}
      </ul>

      {meta && meta.last_page > 1 ? (
        <div className="flex items-center justify-between gap-2 border-t border-slate-100 pt-3">
          <Button
            size="sm"
            variant="secondary"
            disabled={meta.current_page <= 1}
            onClick={() => onPage(meta.current_page - 1)}
          >
            Précédent
          </Button>
          <span className="text-xs text-slate-500">
            Page {meta.current_page} sur {meta.last_page}
          </span>
          <Button
            size="sm"
            variant="secondary"
            disabled={meta.current_page >= meta.last_page}
            onClick={() => onPage(meta.current_page + 1)}
          >
            Suivant
          </Button>
        </div>
      ) : null}
    </Card>
  );
}

/* ------------------------------------------------------------------ */

function Promotions({
  magasinId,
  formulaire,
  onFormulaire,
}: {
  magasinId: number;
  formulaire: Formulaire;
  onFormulaire: (formulaire: Formulaire) => void;
}) {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [erreur, setErreur] = useState<string | null>(null);

  const promotions = useQuery({
    queryKey: queryKeys.magasins.promotions(magasinId),
    queryFn: () => apiGet<PromotionsResponse>(`/magasins/${magasinId}/promotions`),
  });

  async function rafraichir() {
    await queryClient.invalidateQueries({ queryKey: queryKeys.magasins.all });
    await queryClient.invalidateQueries({ queryKey: queryKeys.shopping.all });
  }

  function echouer(error: unknown) {
    const details = isApiError(error) ? error.allFieldMessages : [];
    setErreur(details.length > 0 ? details.join(" ") : getErrorMessage(error));
  }

  const enregistrer = useMutation({
    mutationFn: () => {
      const corps = {
        libelle: formulaire.libelle.trim(),
        magasin_produit_id: formulaire.magasinProduitId,
        prix_promotionnel: parseDecimal(formulaire.prixPromotionnel),
        prix_avant: parseDecimal(formulaire.prixAvant),
        debut: formulaire.debut,
        fin: formulaire.fin,
      };

      return formulaire.id === null
        ? apiPost(`/magasins/${magasinId}/promotions`, corps)
        : apiPut(`/magasins/promotions/${formulaire.id}`, corps);
    },
    onSuccess: async () => {
      setErreur(null);
      onFormulaire(formulaireVide());
      await rafraichir();
      toast.success("Promotion enregistrée.");
    },
    onError: echouer,
  });

  const verifier = useMutation({
    mutationFn: (promotion: Promotion) =>
      apiPut(`/magasins/promotions/${promotion.id}`, { verifiee: true }),
    onSuccess: async () => {
      await rafraichir();
      toast.success("Promotion marquée vérifiée.");
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const supprimer = useMutation({
    mutationFn: (promotion: Promotion) => apiDelete(`/magasins/promotions/${promotion.id}`),
    onSuccess: async () => {
      await rafraichir();
      toast.success("Promotion supprimée.");
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const lignes = promotions.data?.data ?? [];

  return (
    <>
      <Card padding="md" className="space-y-3">
        <CardHeader
          title={formulaire.id === null ? "Ajouter une promotion" : "Corriger une promotion"}
          subtitle={
            formulaire.produitLibelle
              ? `Rattachée à « ${formulaire.produitLibelle} »`
              : "Sans produit rattaché, elle s’affiche mais ne change aucun prix de liste."
          }
          actions={
            formulaire.id !== null || formulaire.magasinProduitId !== null ? (
              <Button size="sm" variant="ghost" onClick={() => onFormulaire(formulaireVide())}>
                Repartir de zéro
              </Button>
            ) : null
          }
        />

        <div className="grid gap-3 sm:grid-cols-3">
          <Field
            label="Libellé"
            value={formulaire.libelle}
            onChange={(event) => onFormulaire({ ...formulaire, libelle: event.target.value })}
            wrapperClassName="sm:col-span-3"
          />
          <Field
            label="Prix promotionnel"
            inputMode="decimal"
            value={formulaire.prixPromotionnel}
            onChange={(event) =>
              onFormulaire({ ...formulaire, prixPromotionnel: event.target.value })
            }
            hint="En euros. Vide = promotion annoncée sans prix."
          />
          <Field
            label="Prix avant"
            inputMode="decimal"
            value={formulaire.prixAvant}
            onChange={(event) => onFormulaire({ ...formulaire, prixAvant: event.target.value })}
          />
          <div className="grid grid-cols-2 gap-2">
            <Field
              label="Début"
              type="date"
              value={formulaire.debut}
              onChange={(event) => onFormulaire({ ...formulaire, debut: event.target.value })}
            />
            <Field
              label="Fin"
              type="date"
              value={formulaire.fin}
              onChange={(event) => onFormulaire({ ...formulaire, fin: event.target.value })}
            />
          </div>
        </div>

        <Button
          type="button"
          onClick={() => {
            if (formulaire.libelle.trim() === "") {
              setErreur("Écris au moins ce sur quoi porte la promotion.");
              return;
            }
            enregistrer.mutate();
          }}
          loading={enregistrer.isPending}
        >
          {formulaire.id === null ? "Enregistrer la promotion" : "Enregistrer la correction"}
        </Button>

        {erreur ? (
          <Banner tone="error" onClose={() => setErreur(null)}>
            {erreur}
          </Banner>
        ) : null}
      </Card>

      <Card padding="md" className="space-y-3">
        <CardHeader
          title="Promotions en cours"
          subtitle="Celles qui couvrent aujourd’hui. Une promotion à venir ou terminée n’apparaît pas ici."
        />

        {promotions.isPending ? <SkeletonList rows={3} /> : null}
        {promotions.isError ? (
          <ErrorState error={promotions.error} compact onRetry={() => promotions.refetch()} />
        ) : null}

        {/* L'avertissement parle des relevés automatiques : le laisser quand tout a été
            vérifié à la main le viderait de son sens, et on cesserait de le lire. */}
        {lignes.some((promotion) => !promotion.verifiee) ? (
          <Banner tone="warning">{promotions.data?.avertissement}</Banner>
        ) : null}

        <ul className="space-y-2">
          {promotions.isSuccess && lignes.length === 0 ? (
            <li className="text-sm text-slate-500">Aucune promotion en cours.</li>
          ) : null}
          {lignes.map((promotion) => (
            <li
              key={promotion.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 px-3 py-2.5"
            >
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-slate-900">{promotion.libelle}</p>
                <p className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                  <Pill tone={promotion.verifiee ? "emerald" : "amber"} dot>
                    {promotion.verifiee ? "Vérifiée" : "Non vérifiée"}
                  </Pill>
                  <span>
                    {formatPrix(promotion.prix_promotionnel)}
                    {promotion.prix_avant !== null ? ` au lieu de ${formatPrix(promotion.prix_avant)}` : ""}
                  </span>
                  <span>
                    du {formatDate(promotion.debut)} au {formatDate(promotion.fin)}
                  </span>
                  <span className="truncate">
                    {promotion.produit ? promotion.produit.libelle : "sans produit rattaché"}
                  </span>
                  {promotion.source ? <span className="truncate">{promotion.source}</span> : null}
                </p>
              </div>

              <div className="flex shrink-0 flex-wrap items-center gap-2">
                {promotion.verifiee ? null : (
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => verifier.mutate(promotion)}
                    loading={verifier.isPending}
                  >
                    J’ai vérifié
                  </Button>
                )}
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() =>
                    onFormulaire({
                      id: promotion.id,
                      libelle: promotion.libelle,
                      magasinProduitId: promotion.magasin_produit_id,
                      produitLibelle: promotion.produit?.libelle ?? "",
                      prixPromotionnel:
                        promotion.prix_promotionnel === null ? "" : String(promotion.prix_promotionnel),
                      prixAvant: promotion.prix_avant === null ? "" : String(promotion.prix_avant),
                      debut: promotion.debut ?? todayIso(),
                      fin: promotion.fin ?? todayIso(),
                    })
                  }
                >
                  Corriger
                </Button>
                <Button
                  size="sm"
                  variant="danger"
                  onClick={() => supprimer.mutate(promotion)}
                  loading={supprimer.isPending}
                >
                  Retirer
                </Button>
              </div>
            </li>
          ))}
        </ul>
      </Card>
    </>
  );
}

export default MagasinsTab;
