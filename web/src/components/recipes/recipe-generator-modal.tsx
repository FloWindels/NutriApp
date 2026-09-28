"use client";

import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { apiPost, getErrorMessage } from "@/lib/api-client";
import { formatKcal, formatNumber } from "@/lib/format";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { Overline } from "@/components/ui/section-header";
import { Pill } from "@/components/ui/pill";

/**
 * Recette proposée par l'IA à partir du stock.
 *
 * Rien n'est enregistré par la génération : la proposition est relue, puis ouverte dans
 * l'éditeur de recette habituel, où elle peut être corrigée avant sauvegarde.
 */

export type Ingredient = {
  name: string;
  ean: string | null;
  amount: number;
  unit: string;
  du_stock: boolean;
  food_id: number | null;
};

export type Proposition = {
  source: "ia" | "indisponible";
  message?: string;
  titre?: string;
  description?: string;
  portions?: number;
  temps_preparation_min?: number;
  ingredients?: Ingredient[];
  etapes?: string[];
  remarque?: string;
  ingredients_retires?: { nom: string; raison: string }[];
  estimation?: { calories: number; proteins: number; carbs: number; fat: number };
  llm_model?: string | null;
};

const EXEMPLES = [
  "J’ai plein de bananes, propose-moi une recette légère",
  "Un plat rapide avec ce qui périme bientôt",
  "Quelque chose de riche en protéines pour ce soir",
];

export function RecipeGeneratorModal({
  open,
  onClose,
  onAccept,
}: {
  open: boolean;
  onClose: () => void;
  onAccept: (proposition: Proposition) => void;
}) {
  const [demande, setDemande] = useState("");
  const [proposition, setProposition] = useState<Proposition | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);

  const generer = useMutation({
    mutationFn: (texte: string) => apiPost<{ data: Proposition }>("/recipes/generate", { demande: texte }),
    onSuccess: (reponse) => setProposition(reponse.data),
    onError: (error) => setErreur(getErrorMessage(error, "La génération n’a pas abouti.")),
  });

  function lancer() {
    setErreur(null);
    setProposition(null);
    const texte = demande.trim();
    if (texte.length < 3) {
      setErreur("Dis en une phrase ce que tu voudrais cuisiner.");
      return;
    }
    generer.mutate(texte);
  }

  function fermer() {
    setProposition(null);
    setErreur(null);
    onClose();
  }

  return (
    <Modal open={open} onClose={fermer} title="Une recette avec ce que j’ai" size="lg">
      <div className="space-y-4">
        <Banner tone="info">
          La proposition part de ton stock et tient compte de ton régime et de tes allergènes.
          Relis-la : c’est une suggestion de cuisine, pas un conseil diététique.
        </Banner>

        <Field
          label="Qu’est-ce que tu voudrais ?"
          value={demande}
          onChange={(event) => setDemande(event.target.value)}
          placeholder="J’ai plein de bananes, propose-moi une recette légère"
          onKeyDown={(event) => {
            if (event.key === "Enter") lancer();
          }}
        />

        <div className="flex flex-wrap gap-1.5">
          {EXEMPLES.map((exemple) => (
            <button
              key={exemple}
              type="button"
              onClick={() => setDemande(exemple)}
              className="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-600 transition hover:bg-slate-50"
            >
              {exemple}
            </button>
          ))}
        </div>

        <Button type="button" onClick={lancer} loading={generer.isPending}>
          {generer.isPending ? "Je cherche…" : "Proposer une recette"}
        </Button>

        {erreur ? <Banner tone="error">{erreur}</Banner> : null}

        {proposition?.source === "indisponible" ? (
          <Banner tone="warning">
            Aucun modèle n’est configuré pour rédiger une recette sur ce serveur. Tu peux en créer
            une à la main.
          </Banner>
        ) : null}

        {proposition?.source === "ia" ? (
          <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4">
            <div>
              <h3 className="text-lg font-semibold text-slate-950">{proposition.titre}</h3>
              <p className="mt-0.5 text-xs text-slate-500">
                {proposition.portions} portions · {proposition.temps_preparation_min} min
                {proposition.estimation
                  ? ` · ${formatKcal(proposition.estimation.calories)} au total`
                  : ""}
              </p>
            </div>

            {proposition.ingredients_retires && proposition.ingredients_retires.length > 0 ? (
              <Banner tone="warning">
                Retiré pour ta sécurité :{" "}
                {proposition.ingredients_retires.map((ligne) => `${ligne.nom} (${ligne.raison})`).join(", ")}.
              </Banner>
            ) : null}

            <div>
              <Overline className="mb-2">Ingrédients</Overline>
              <ul className="space-y-1 text-sm text-slate-700">
                {proposition.ingredients?.map((ingredient) => (
                  <li key={ingredient.name} className="flex items-center gap-2">
                    <span className="flex-1">
                      {formatNumber(ingredient.amount, 0)} {ingredient.unit} — {ingredient.name}
                    </span>
                    {ingredient.du_stock ? (
                      <Pill tone="emerald">dans ton stock</Pill>
                    ) : (
                      <Pill tone="slate">à acheter</Pill>
                    )}
                  </li>
                ))}
              </ul>
            </div>

            {proposition.etapes && proposition.etapes.length > 0 ? (
              <div>
                <Overline className="mb-2">Préparation</Overline>
                <ol className="space-y-1 text-sm text-slate-700">
                  {proposition.etapes.map((etape, index) => (
                    <li key={etape} className="flex gap-2">
                      <span className="text-slate-400">{index + 1}.</span>
                      <span>{etape}</span>
                    </li>
                  ))}
                </ol>
              </div>
            ) : null}

            {proposition.remarque ? (
              <p className="text-sm text-slate-600">{proposition.remarque}</p>
            ) : null}

            <div className="flex flex-wrap gap-2 pt-1">
              <Button type="button" onClick={() => onAccept(proposition)}>
                Ouvrir dans l’éditeur
              </Button>
              <Button type="button" variant="secondary" onClick={lancer} loading={generer.isPending}>
                Autre proposition
              </Button>
            </div>

            <p className="text-xs text-slate-500">
              Proposée par l’IA{proposition.llm_model ? ` (${proposition.llm_model})` : ""}. Les
              valeurs nutritionnelles sont calculées par Mavi’oh à partir de sa base d’aliments,
              pas par le modèle.
            </p>
          </div>
        ) : null}
      </div>
    </Modal>
  );
}
