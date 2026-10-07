"use client";

import { useRef, useState, type ReactNode } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { useOffre } from "@/hooks/use-offre";
import { usePortions } from "@/hooks/use-portions";
import { apiGet, apiPost, getErrorMessage } from "@/lib/api-client";
import { dataUriBytes, resizeImage, MAX_IMAGE_BYTES } from "@/lib/image-resize";
import { formatKcal, parseDecimal } from "@/lib/format";
import { messages } from "@/lib/messages";
import { previewNutrition, unitLabel } from "@/lib/units";
import type {
  DataEnvelope,
  Food,
  MealCreateInput,
  MealCreateResponse,
  MealItemInput,
  MealType,
  PlateAnalysis,
  PlateLine,
  Portion,
  Recipe,
} from "@/lib/types/api";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { OffreRequisePourCapacite } from "@/components/ui/offre-requise";
import { EstimatePill } from "@/components/ui/pill";
import { Field } from "@/components/ui/field";

/**
 * Photo d'assiette : capture, analyse, puis vérification OBLIGATOIRE.
 *
 * Aucune écriture n'a lieu tant que l'utilisateur n'a pas confirmé : l'analyse ne fait que
 * proposer des lignes, qu'il corrige, complète ou supprime. Ce que le modèle n'a pas vu
 * s'ajoute à la main depuis le catalogue, dans la même liste. La confirmation envoie tout le
 * repas en un seul appel à POST /meals.
 */

/** Aliment ou recette choisi à la main pour compléter ce que la photo a manqué. */
export type AjoutPhoto =
  | { kind: "food"; food: Food; quantity: number; unit: string }
  | { kind: "recipe"; recipe: Recipe; quantity: number };

/** Ce que reçoit le sélecteur d'ajout : il rend la main par l'un ou l'autre. */
export type AjoutPhotoPickerProps = {
  onAjouter: (ajout: AjoutPhoto) => void;
  onAnnuler: () => void;
};

// `quantite` devient une chaîne : c'est la valeur d'un champ texte, corrigée par l'utilisateur.
// `unite` s'élargit : une ligne ajoutée à la main garde l'unité choisie dans le sélecteur.
type Ligne = Omit<PlateLine, "quantite" | "unite"> & {
  uid: string;
  unite: string;
  quantiteInitiale: number;
  quantite: string;
  retenue: boolean;
  /** Présent seulement pour une ligne ajoutée à la main, absente de la photo. */
  ajout: AjoutPhoto | null;
};

let compteur = 0;

function toLignes(analyse: PlateAnalysis): Ligne[] {
  return analyse.aliments.map((ligne) => ({
    ...ligne,
    uid: `plate-${++compteur}`,
    quantiteInitiale: ligne.quantite,
    quantite: String(ligne.quantite),
    retenue: true,
    ajout: null,
  }));
}

function ligneAjoutee(ajout: AjoutPhoto): Ligne {
  const unite = ajout.kind === "food" ? ajout.unit : "portion";

  return {
    uid: `plate-${++compteur}`,
    nom: ajout.kind === "food" ? ajout.food.name : ajout.recipe.title,
    marque: ajout.kind === "food" ? ajout.food.brand : null,
    unite,
    confiance: 1,
    food: null,
    valeurs_proposees: null,
    quantiteInitiale: ajout.quantity,
    quantite: String(ajout.quantity),
    retenue: true,
    ajout,
  };
}

/** Quantité saisie, virgule comprise ; null si elle n'est pas exploitable. */
function quantiteSaisie(ligne: Ligne): number | null {
  const quantite = parseDecimal(ligne.quantite);
  return quantite !== null && Number.isFinite(quantite) && quantite > 0 ? quantite : null;
}

/** Calories de la ligne pour la quantité saisie, à titre indicatif. */
function caloriesLigne(ligne: Ligne, portions: Portion[]): number | null {
  const quantite = quantiteSaisie(ligne);
  if (quantite === null) return null;

  if (ligne.ajout?.kind === "food") {
    const food = ligne.ajout.food;
    return previewNutrition(quantite, ligne.unite, food, food, portions).calories;
  }

  if (ligne.ajout?.kind === "recipe") {
    const recette = ligne.ajout.recipe;
    const parPortion = recette.per_serving?.calories ?? recette.calories;
    return parPortion == null ? null : parPortion * quantite;
  }

  if (ligne.food) {
    const pour100 = ligne.food.calories;
    if (pour100 == null) return null;
    return ligne.unite === "g" || ligne.unite === "ml" ? (pour100 * quantite) / 100 : pour100 * quantite;
  }

  const proposees = ligne.valeurs_proposees?.calories;
  if (proposees == null) return null;
  // Les valeurs proposées portent sur la portion vue : on les met à l'échelle.
  return (proposees * quantite) / Math.max(ligne.quantiteInitiale, 1);
}

function toItem(ligne: Ligne): MealItemInput | null {
  const quantite = quantiteSaisie(ligne);
  if (quantite === null) return null;

  if (ligne.ajout?.kind === "food") {
    return { food_id: ligne.ajout.food.id, quantity: quantite, unit: ligne.unite };
  }

  if (ligne.ajout?.kind === "recipe") {
    return { recipe_id: ligne.ajout.recipe.id, quantity: quantite, unit: "portion" };
  }

  if (ligne.food) {
    return { food_id: ligne.food.id, quantity: quantite, unit: ligne.unite };
  }

  const valeurs = ligne.valeurs_proposees;

  return {
    custom: {
      label: ligne.nom,
      calories: valeurs?.calories ?? 0,
      proteins: valeurs?.proteines ?? 0,
      carbs: valeurs?.glucides ?? 0,
      fat: valeurs?.lipides ?? 0,
    },
    quantity: quantite,
    unit: ligne.unite,
  };
}

export type PlatePhotoPanelProps = {
  date: string;
  type: MealType;
  onAdded: (response: MealCreateResponse) => void;
  /**
   * Recherche dans le catalogue pour ajouter un aliment oublié par le modèle. Fournie par le
   * dialogue d'ajout, qui possède déjà la recherche, les fréquents et le choix d'unité.
   */
  renderAjout?: (props: AjoutPhotoPickerProps) => ReactNode;
};

export function PlatePhotoPanel({ date, type, onAdded, renderAjout }: PlatePhotoPanelProps) {
  const { peut } = useOffre();
  const { portions } = usePortions();
  const [ajoutOuvert, setAjoutOuvert] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const [apercu, setApercu] = useState<string | null>(null);
  const [lignes, setLignes] = useState<Ligne[] | null>(null);
  const [analyse, setAnalyse] = useState<PlateAnalysis | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);

  const capacite = useQuery({
    queryKey: ["meals", "photo-capability"],
    queryFn: () => apiGet<DataEnvelope<{ disponible: boolean; llm_model: string | null }>>("/meals/photo-capability"),
    staleTime: 5 * 60 * 1000,
  });

  const analyseMutation = useMutation({
    mutationFn: (image: string) => apiPost<DataEnvelope<PlateAnalysis>>("/meals/analyze-photo", { image }),
    onSuccess: (reponse) => {
      // Les lignes sont posées ici, pas dans un effet : React 19 l'interdit.
      setAnalyse(reponse.data);
      setLignes(toLignes(reponse.data));
    },
    onError: (error) => setErreur(getErrorMessage(error, "L’analyse n’a pas abouti.")),
  });

  const enregistrer = useMutation({
    mutationFn: (items: MealItemInput[]) => {
      const body: MealCreateInput = { date, type, items };
      return apiPost<MealCreateResponse>("/meals", body);
    },
    onSuccess: onAdded,
    onError: (error) => setErreur(getErrorMessage(error, "Impossible d’enregistrer le repas.")),
  });

  async function choisir(file: File | undefined) {
    if (!file) return;
    setErreur(null);

    try {
      const dataUri = await resizeImage(file, { maxSide: 1024 });

      if (dataUriBytes(dataUri) > MAX_IMAGE_BYTES) {
        setErreur("Cette photo reste trop lourde. Prends-la d’un peu plus loin ou réduis-la.");
        return;
      }

      setApercu(dataUri);
      setLignes(null);
      setAnalyse(null);
      setAjoutOuvert(false);
      analyseMutation.mutate(dataUri);
    } catch {
      setErreur("Impossible de lire cette image.");
    }
  }

  function modifier(uid: string, patch: Partial<Ligne>) {
    setLignes((precedent) =>
      precedent === null ? precedent : precedent.map((l) => (l.uid === uid ? { ...l, ...patch } : l)),
    );
  }

  function retirer(uid: string) {
    setLignes((precedent) => (precedent === null ? precedent : precedent.filter((l) => l.uid !== uid)));
  }

  function ajouter(ajout: AjoutPhoto) {
    setErreur(null);
    setLignes((precedent) => [...(precedent ?? []), ligneAjoutee(ajout)]);
    setAjoutOuvert(false);
  }

  function confirmer() {
    setErreur(null);
    const items = (lignes ?? []).filter((l) => l.retenue).map(toItem).filter((i): i is MealItemInput => i !== null);

    if (items.length === 0) {
      setErreur("Garde au moins une ligne avec une quantité valide.");
      return;
    }

    enregistrer.mutate(items);
  }

  // Le serveur répond « indisponible » aussi bien quand aucun modèle n'est branché que quand
  // l'offre ne couvre pas l'IA. Les deux ne se disent pas pareil : l'un est une panne, l'autre
  // une porte à ouvrir.
  if (!peut("ia")) {
    return <OffreRequisePourCapacite capacite="ia" compact />;
  }

  const indisponible = capacite.data?.data.disponible === false;
  const retenues = (lignes ?? []).filter((l) => l.retenue);
  const totalKcal = retenues.reduce((somme, ligne) => somme + (caloriesLigne(ligne, portions) ?? 0), 0);

  return (
    <div className="space-y-3">
      <Banner tone="info">{messages.plateDisclaimer}</Banner>

      {indisponible ? (
        <Banner tone="warning">
          Aucun modèle capable de lire une photo n’est configuré sur ce serveur. Tu peux saisir ton
          repas à la main depuis les autres onglets.
        </Banner>
      ) : null}

      <div className="flex flex-wrap items-center gap-3">
        <input
          ref={fileRef}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          capture="environment"
          className="hidden"
          onChange={(event) => void choisir(event.target.files?.[0])}
        />
        <Button
          type="button"
          variant="secondary"
          onClick={() => fileRef.current?.click()}
          disabled={indisponible || analyseMutation.isPending}
        >
          {apercu ? "Reprendre une photo" : "Photographier mon assiette"}
        </Button>
        {analyseMutation.isPending ? (
          <span className="text-sm text-slate-600">Analyse en cours…</span>
        ) : null}
      </div>

      {apercu ? (
        // eslint-disable-next-line @next/next/no-img-element -- data URI local, jamais servi par le CDN
        <img
          src={apercu}
          alt="Photo de l’assiette"
          className="max-h-48 w-full rounded-2xl object-cover"
        />
      ) : null}

      {erreur ? <Banner tone="error">{erreur}</Banner> : null}

      {analyse?.source === "indisponible" ? (
        <Banner tone="warning">
          {analyse.avertissements[0] ?? "Aucun aliment n’a pu être identifié sur cette photo."}
        </Banner>
      ) : null}

      {/* La revue s'ouvre dès que l'analyse a répondu, même sans rien reconnaître : il reste
          possible de tout saisir à la main. */}
      {lignes !== null ? (
        <div className="space-y-2">
          {analyse?.avertissements.map((avertissement) => (
            <Banner key={avertissement} tone="warning">
              {avertissement}
            </Banner>
          ))}

          <ul className="space-y-2">
            {lignes.map((ligne) => {
              const kcal = caloriesLigne(ligne, portions);

              if (ligne.ajout) {
                return (
                  <li key={ligne.uid} className="rounded-2xl border border-slate-200 bg-white px-3 py-2.5">
                    <div className="flex flex-wrap items-center gap-3">
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-slate-900">{ligne.nom}</p>
                        <p className="text-xs text-slate-500">
                          {["Ajouté par toi", ligne.marque, kcal != null ? formatKcal(kcal) : null]
                            .filter(Boolean)
                            .join(" · ")}
                        </p>
                      </div>
                      <div className="w-28">
                        <Field
                          type="text"
                          inputMode="decimal"
                          value={ligne.quantite}
                          onChange={(event) => modifier(ligne.uid, { quantite: event.target.value })}
                          aria-label={`Quantité pour ${ligne.nom}`}
                        />
                      </div>
                      <span className="text-sm text-slate-500">{unitLabel(ligne.unite, portions)}</span>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => retirer(ligne.uid)}
                        aria-label={`Retirer ${ligne.nom}`}
                      >
                        Retirer
                      </Button>
                    </div>
                  </li>
                );
              }

              return (
                <li
                  key={ligne.uid}
                  className={`rounded-2xl border px-3 py-2.5 ${ligne.retenue ? "border-slate-200 bg-white" : "border-slate-200 bg-slate-50 opacity-60"}`}
                >
                  <div className="flex flex-wrap items-center gap-3">
                    <input
                      type="checkbox"
                      checked={ligne.retenue}
                      onChange={(event) => modifier(ligne.uid, { retenue: event.target.checked })}
                      className="size-5 shrink-0 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"
                      aria-label={`Garder ${ligne.nom}`}
                    />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium text-slate-900">
                        {ligne.food?.name ?? ligne.nom}
                      </p>
                      <p className="text-xs text-slate-500">
                        {ligne.food
                          ? `Fiche : ${ligne.food.name}${ligne.food.brand ? ` · ${ligne.food.brand}` : ""}`
                          : "Valeurs estimées par l’IA, à vérifier"}
                        {kcal != null ? ` · ${formatKcal(kcal)}` : ""}
                      </p>
                    </div>
                    {!ligne.food ? <EstimatePill /> : null}
                    <div className="w-28">
                      <Field
                        type="number"
                        inputMode="decimal"
                        step="1"
                        value={ligne.quantite}
                        onChange={(event) => modifier(ligne.uid, { quantite: event.target.value })}
                        aria-label={`Quantité pour ${ligne.nom}`}
                      />
                    </div>
                    <span className="text-sm text-slate-500">{ligne.unite}</span>
                  </div>
                </li>
              );
            })}
          </ul>

          {lignes.length === 0 && !ajoutOuvert ? (
            <p className="rounded-2xl border border-dashed border-slate-200 px-4 py-3 text-sm text-slate-500">
              Rien n’a été reconnu sur la photo. Ajoute toi-même ce que contient l’assiette.
            </p>
          ) : null}

          {renderAjout ? (
            ajoutOuvert ? (
              <div className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50/60 p-3">
                <p className="text-sm font-semibold text-slate-900">Ajouter un aliment oublié</p>
                {renderAjout({ onAjouter: ajouter, onAnnuler: () => setAjoutOuvert(false) })}
              </div>
            ) : (
              <Button type="button" variant="secondary" size="sm" onClick={() => setAjoutOuvert(true)}>
                + Ajouter un aliment oublié
              </Button>
            )
          ) : null}

          <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-slate-50 px-3 py-2.5">
            <span className="text-sm text-slate-700">
              {retenues.length} ligne{retenues.length > 1 ? "s" : ""} · {formatKcal(totalKcal)}
            </span>
            <Button
              type="button"
              onClick={confirmer}
              loading={enregistrer.isPending}
              disabled={ajoutOuvert || retenues.length === 0}
            >
              Vérifié, ajouter au repas
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
