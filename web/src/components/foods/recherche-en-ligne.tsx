"use client";

import { useEffect, useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useOffre } from "@/hooks/use-offre";
import { apiPost, getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { formatPercent } from "@/lib/format";
import { capaciteRequise, estOffreRequise } from "@/lib/offres-capacites";
import { queryKeys } from "@/lib/query-keys";
import type {
  DataEnvelope,
  Food,
  PropositionEnLigne,
  RechercheEnLigneInput,
  RechercheEnLigneResultat,
  SourceEnLigne,
} from "@/lib/types/api";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { OffreRequisePourCapacite } from "@/components/ui/offre-requise";
import { useToast } from "@/components/ui/toast";

/**
 * « Chercher les valeurs sur Internet » : quand un produit manque à la base, le serveur
 * regarde d'abord le catalogue et Open Food Facts, puis lance un agent qui lit des pages
 * (fiche produit, recette type) pour en tirer l'énergie et les macros pour 100 g.
 *
 * - Fiche trouvée (catalogue, Open Food Facts) → elle est déjà enregistrée : on la choisit.
 * - Valeurs lues sur Internet → rien n'est enregistré : elles préremplissent le formulaire
 *   de création, que l'utilisateur relit avant d'enregistrer.
 * - Rien de fiable → le message du serveur, et la saisie à la main reste là.
 */

export const LIBELLE_RECHERCHE_EN_LIGNE = "Chercher les valeurs sur Internet";

const NOM_MIN = 2;
const NOM_MAX = 120;

const ETAPES = [
  "Recherche dans le catalogue et sur Open Food Facts…",
  "Recherche sur Internet…",
  "Recherche sur Internet… lecture des valeurs nutritionnelles",
  "Toujours en cours : certaines pages sont lentes à lire…",
];
/** Moment (en secondes) où chaque étape remplace la précédente. */
const ETAPES_A = [0, 4, 12, 40];

const ORIGINE: Record<"catalogue" | "open_food_facts", string> = {
  catalogue: "le catalogue Mavi’oh",
  open_food_facts: "Open Food Facts",
};

export type RechercheEnLigneProps = {
  /** Nom tapé par l'utilisateur. */
  nom: string;
  marque?: string | null;
  /** Une fiche existante a été trouvée (déjà enregistrée côté serveur). */
  onFood: (food: Food) => void;
  /** Des valeurs ont été lues sur Internet : à relire dans le formulaire de création. */
  onProposition: (resultat: RechercheEnLigneResultat) => void;
  /** Bouton discret (lien sous une liste de résultats) plutôt qu'un bouton secondaire. */
  variante?: "bouton" | "lien";
  size?: "sm" | "md";
  disabled?: boolean;
  className?: string;
};

export function RechercheEnLigne({
  nom,
  marque = null,
  onFood,
  onProposition,
  variante = "bouton",
  size = "sm",
  disabled = false,
  className,
}: RechercheEnLigneProps) {
  const { peut } = useOffre();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [verrouVisible, setVerrouVisible] = useState(false);
  const [erreur, setErreur] = useState<unknown>(null);
  const [indisponible, setIndisponible] = useState<string | null>(null);
  const [etape, setEtape] = useState(0);

  const recherche = useMutation({
    mutationFn: (input: RechercheEnLigneInput) =>
      apiPost<DataEnvelope<RechercheEnLigneResultat>>("/foods/recherche-en-ligne", input),
    onSuccess: (reponse) => {
      const resultat = reponse.data;
      if ((resultat.source === "catalogue" || resultat.source === "open_food_facts") && resultat.food) {
        void queryClient.invalidateQueries({ queryKey: queryKeys.foods.all });
        toast({
          title: `Trouvé dans ${ORIGINE[resultat.source]}`,
          description: resultat.food.name,
          tone: "success",
        });
        onFood(resultat.food);
        return;
      }
      if (resultat.source === "internet" && resultat.proposition) {
        onProposition(resultat);
        return;
      }
      setIndisponible(
        resultat.message ||
          "Aucune valeur fiable trouvée pour ce produit. Tu peux le créer en saisissant ses valeurs toi-même.",
      );
    },
    onError: (error) => setErreur(error),
  });

  // Une minute d'attente se supporte mieux quand on voit où en est la recherche.
  useEffect(() => {
    if (!recherche.isPending) return;
    const minuteurs = ETAPES_A.slice(1).map((secondes, index) =>
      setTimeout(() => setEtape(index + 1), secondes * 1000),
    );
    return () => minuteurs.forEach(clearTimeout);
  }, [recherche.isPending]);

  const nomPropre = nom.trim().slice(0, NOM_MAX);

  function lancer() {
    setErreur(null);
    setIndisponible(null);
    if (!peut("ia")) {
      setVerrouVisible((visible) => !visible);
      return;
    }
    if (nomPropre.length < NOM_MIN) {
      setIndisponible("Indique d’abord le nom du produit (2 caractères minimum).");
      return;
    }
    setEtape(0);
    recherche.mutate({ nom: nomPropre, marque: marque?.trim().slice(0, NOM_MAX) || null });
  }

  const verrouille = !peut("ia");
  const enCours = recherche.isPending;

  return (
    <div className={cn("space-y-2 text-left", className)}>
      {variante === "lien" ? (
        <button
          type="button"
          onClick={lancer}
          disabled={disabled || enCours}
          className="w-full rounded-2xl border border-dashed border-sky-200 px-4 py-2 text-sm text-sky-800 transition hover:bg-sky-50 disabled:opacity-60"
        >
          {enCours ? "Recherche en cours…" : `Pas trouvé ? ${LIBELLE_RECHERCHE_EN_LIGNE}`}
          {verrouille ? <span className="block text-xs text-slate-500">Inclus dans les offres payantes</span> : null}
        </button>
      ) : (
        <div className="flex flex-col items-center gap-1">
          <Button type="button" variant="secondary" size={size} onClick={lancer} loading={enCours} disabled={disabled}>
            {LIBELLE_RECHERCHE_EN_LIGNE}
          </Button>
          {verrouille ? <span className="text-xs text-slate-500">Inclus dans les offres payantes</span> : null}
        </div>
      )}

      {enCours ? (
        <p role="status" aria-live="polite" className="text-center text-xs leading-5 text-slate-600">
          {ETAPES[etape]}
          <span className="block text-slate-400">Cela peut prendre jusqu’à une minute.</span>
        </p>
      ) : null}

      {verrouille && verrouVisible ? <OffreRequisePourCapacite capacite="ia" compact /> : null}

      {erreur && estOffreRequise(erreur) ? (
        <OffreRequisePourCapacite capacite={capaciteRequise(erreur) ?? "ia"} compact />
      ) : erreur ? (
        <Banner tone="error" onClose={() => setErreur(null)}>
          {getErrorMessage(erreur, "La recherche sur Internet n’a pas abouti.")}
        </Banner>
      ) : null}

      {indisponible ? (
        <Banner tone="warning" onClose={() => setIndisponible(null)}>
          {indisponible}
        </Banner>
      ) : null}
    </div>
  );
}

/** Seuls les liens web s'ouvrent : un `javascript:` venu d'une page lue n'a rien à faire ici. */
export function lienWebSur(url: string): string | null {
  try {
    const parsed = new URL(url);
    return parsed.protocol === "http:" || parsed.protocol === "https:" ? parsed.href : null;
  } catch {
    return null;
  }
}

function domaineDe(url: string): string {
  try {
    return new URL(url).hostname.replace(/^www\./, "");
  } catch {
    return url;
  }
}

/** Un plat (ou une valeur déduite d'une recette type) n'est qu'une estimation. */
export function estEstimation(proposition: PropositionEnLigne): boolean {
  return proposition.estimation || proposition.nature === "plat";
}

/**
 * Avertissement affiché au-dessus du formulaire prérempli : d'où viennent les chiffres, à
 * quel point s'y fier, et ce qu'il faut vérifier.
 */
export function AvisValeursEnLigne({ resultat, className }: { resultat: RechercheEnLigneResultat; className?: string }) {
  const proposition = resultat.proposition;
  if (!proposition) return null;

  const confiance = Math.round(Math.min(1, Math.max(0, proposition.confiance)) * 100);
  const sources = resultat.sources
    .map((source): (SourceEnLigne & { href: string }) | null => {
      const href = lienWebSur(source.url);
      return href ? { ...source, href } : null;
    })
    .filter((source): source is SourceEnLigne & { href: string } => source !== null);

  return (
    <Banner tone="warning" title="Valeurs trouvées sur Internet — vérifie-les avant d’enregistrer" className={className}>
      <div className="space-y-2">
        <p>
          Confiance : <strong>{formatPercent(confiance)}</strong>
          {confiance < 50 ? " · à prendre avec prudence" : null}
        </p>
        {estEstimation(proposition) ? (
          <p>
            C’est une <strong>estimation</strong> fondée sur une recette type : les valeurs réelles dépendent des
            ingrédients et de la préparation.
          </p>
        ) : null}
        {resultat.message ? <p>{resultat.message}</p> : null}
        {resultat.avertissements.length > 0 ? (
          <ul className="list-disc space-y-0.5 pl-5">
            {resultat.avertissements.map((avertissement, index) => (
              <li key={index}>{avertissement}</li>
            ))}
          </ul>
        ) : null}
        {sources.length > 0 ? (
          <div>
            <p className="font-medium">Sources</p>
            <ul className="mt-0.5 space-y-0.5">
              {sources.map((source, index) => (
                <li key={`${source.href}-${index}`} className="min-w-0">
                  <a
                    href={source.href}
                    target="_blank"
                    rel="noreferrer noopener"
                    className="break-words underline decoration-amber-400 underline-offset-2 hover:decoration-amber-700"
                  >
                    {source.titre?.trim() || domaineDe(source.href)}
                  </a>
                  <span className="text-amber-800/70"> · {domaineDe(source.href)}</span>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
        <p className="text-xs text-amber-900/80">
          Valeurs pour 100 g. Ce n’est pas une mesure de laboratoire ni un avis médical.
        </p>
      </div>
    </Banner>
  );
}
