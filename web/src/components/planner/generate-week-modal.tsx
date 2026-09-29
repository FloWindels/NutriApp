"use client";

import { useEffect, useRef, useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { SwitchRow } from "@/components/settings/switch";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { TextareaField } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { useOffre } from "@/hooks/use-offre";
import { apiPost, getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { formatDay } from "@/lib/format";
import { messages } from "@/lib/messages";
import { MEAL_TYPES, type MealType, type PlannerGenerateResponse } from "@/lib/types/api";
import { MEAL_TYPE_LABELS, labelFor } from "@/lib/vocab";
import { useResetOnChange } from "@/lib/use-reset-on-change";

const DEFAULT_TYPES: MealType[] = ["dejeuner", "diner"];

const EXEMPLES = [
  "Le matin je n’ai pas le temps de cuisiner",
  "Des plats à préparer d’avance le dimanche",
  "Quelque chose de léger le soir",
];

export type GenerateWeekModalProps = {
  open: boolean;
  onClose: () => void;
  weekStart: string;
  onGenerated: (result: PlannerGenerateResponse) => void;
  /** L'attente a été abandonnée : le serveur, lui, finit. Le parent doit recharger la semaine. */
  onAbandon: () => void;
};

/**
 * « Générer la semaine » — `POST /planner/generate` with the meal types to fill,
 * a « Remplacer l’existant » switch and an optional French request the model reads
 * (« le matin je n’ai pas le temps de cuisiner »).
 *
 * La demande n’est proposée qu’avec l’offre qui donne droit à l’IA, et la modale reste
 * ouverte après coup pour dire par quel moteur la semaine a été composée : sans cela, rien
 * ne distinguerait une semaine écoutée d’un repli silencieux sur les règles.
 */
export function GenerateWeekModal({ open, onClose, weekStart, onGenerated, onAbandon }: GenerateWeekModalProps) {
  const { peut } = useOffre();
  const avecIa = peut("ia");

  const [mealTypes, setMealTypes] = useState<MealType[]>(DEFAULT_TYPES);
  const [replace, setReplace] = useState(false);
  const [demande, setDemande] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [resultat, setResultat] = useState<PlannerGenerateResponse | null>(null);
  const controllerRef = useRef<AbortController | null>(null);

  useResetOnChange(String(open), () => {
    if (!open) return;
    setMealTypes(DEFAULT_TYPES);
    setReplace(false);
    setDemande("");
    setError(null);
    setResultat(null);
  });

  useEffect(() => () => controllerRef.current?.abort(), []);

  const generate = useMutation({
    mutationFn: () => {
      const controller = new AbortController();
      controllerRef.current = controller;
      const texte = demande.trim();

      return apiPost<PlannerGenerateResponse>(
        "/planner/generate",
        {
          week_start: weekStart,
          meal_types: MEAL_TYPES.filter((type) => mealTypes.includes(type)),
          replace,
          ...(avecIa && texte ? { demande: texte } : {}),
        },
        { signal: controller.signal },
      );
    },
    onSuccess: (response) => {
      controllerRef.current = null;
      onGenerated(response);
      if (avecIa && demande.trim()) setResultat(response);
      else onClose();
    },
    onError: (err) => {
      // Génération annulée : le contrôleur a déjà été oublié, il n’y a rien à signaler.
      if (controllerRef.current === null) return;
      controllerRef.current = null;
      setError(getErrorMessage(err));
    },
  });

  /**
   * Abandonner l'attente, pas la génération.
   *
   * Couper la requête ne dit rien au serveur, qui va au bout et écrit la semaine. Appeler cela
   * « Annuler » laissait croire l'inverse : on ferme donc la boîte en prévenant, et le parent
   * recharge la semaine pour montrer ce qui a réellement été écrit.
   */
  function abandonner() {
    controllerRef.current?.abort();
    controllerRef.current = null;
    onAbandon();
    onClose();
  }

  function toggle(type: MealType) {
    setMealTypes((current) =>
      current.includes(type) ? current.filter((item) => item !== type) : [...current, type],
    );
  }

  const pending = generate.isPending;
  const parIa = resultat?.generated_by === "ia";

  return (
    <Modal
      open={open}
      onClose={pending ? () => undefined : onClose}
      locked={pending}
      title="Générer la semaine"
      description={`Semaine du ${formatDay(weekStart)} · recettes compatibles avec ton régime et tes cibles (estimation).`}
      size="md"
      footer={
        resultat ? (
          <Button onClick={onClose}>Fermer</Button>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose} disabled={pending}>
              {messages.cancel}
            </Button>
            <Button
              onClick={() => generate.mutate()}
              loading={pending}
              disabled={mealTypes.length === 0}
            >
              Générer la semaine
            </Button>
          </>
        )
      }
    >
      {resultat ? (
        <div className="space-y-3">
          <Banner
            tone={parIa ? "success" : "warning"}
            title={parIa ? "Semaine composée par l’IA" : "Semaine composée par les règles Mavi’oh"}
          >
            {resultat.warnings[0] ??
              (parIa
                ? "Le modèle a choisi parmi tes recettes compatibles ; Mavi’oh a vérifié chaque choix avant de planifier."
                : "Ta demande n’a pas changé la semaine : les règles Mavi’oh l’ont composée.")}
          </Banner>

          {resultat.explication.length > 0 ? (
            <ul className="space-y-1 text-sm text-slate-700">
              {resultat.explication.map((phrase) => (
                <li key={phrase} className="flex gap-2">
                  <span aria-hidden="true" className="text-slate-400">
                    —
                  </span>
                  <span>{phrase}</span>
                </li>
              ))}
            </ul>
          ) : null}

          <p className="text-xs text-slate-500">
            {resultat.generated_count > 1
              ? `${resultat.generated_count} repas planifiés.`
              : resultat.message}{" "}
            Relis ta semaine : tu peux remplacer n’importe quel repas.
          </p>
        </div>
      ) : (
        <div className="space-y-4">
          {error ? <Banner tone="error">{error}</Banner> : null}

          {pending ? (
            <Banner tone="info" title="Mavi’oh compose ta semaine…">
              <p>
                Cela peut prendre 20 à 60 secondes. Tu peux fermer : la semaine sera composée
                quand même, et tu la retrouveras sur la grille.
              </p>
              <Button variant="secondary" size="sm" className="mt-2" onClick={abandonner}>
                Continuer sans attendre
              </Button>
            </Banner>
          ) : null}

          <fieldset className="min-w-0">
            <legend className="mb-2 block text-sm font-medium text-slate-700">Repas à remplir</legend>
            <div className="flex flex-wrap gap-2">
              {MEAL_TYPES.map((type) => {
                const selected = mealTypes.includes(type);
                return (
                  <button
                    key={type}
                    type="button"
                    role="checkbox"
                    aria-checked={selected}
                    onClick={() => toggle(type)}
                    disabled={pending}
                    className={cn(
                      "inline-flex h-10 items-center rounded-2xl border px-4 text-sm font-medium transition disabled:opacity-60",
                      selected
                        ? "border-emerald-700 bg-emerald-700 text-white"
                        : "border-slate-200 bg-white text-slate-700 hover:bg-slate-50",
                    )}
                  >
                    {labelFor(MEAL_TYPE_LABELS, type)}
                  </button>
                );
              })}
            </div>
            {mealTypes.length === 0 ? (
              <p className="mt-2 text-xs font-medium text-rose-600" role="alert">
                Choisis au moins un type de repas.
              </p>
            ) : null}
          </fieldset>

          {avecIa ? (
            <div className="space-y-2">
              <TextareaField
                label="Une contrainte à respecter ? (facultatif)"
                hint="Dis-le en français. Mavi’oh choisit alors parmi tes recettes compatibles, jamais en dehors."
                value={demande}
                maxLength={500}
                rows={2}
                disabled={pending}
                placeholder="Le matin je n’ai pas le temps de cuisiner"
                onChange={(event) => setDemande(event.target.value)}
              />
              <div className="flex flex-wrap gap-1.5">
                {EXEMPLES.map((exemple) => (
                  <button
                    key={exemple}
                    type="button"
                    disabled={pending}
                    onClick={() => setDemande(exemple)}
                    className="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-600 transition hover:bg-slate-50 disabled:opacity-60"
                  >
                    {exemple}
                  </button>
                ))}
              </div>
            </div>
          ) : null}

          <SwitchRow
            title="Remplacer l’existant"
            description="Les repas déjà prévus sur ces créneaux seront remplacés."
            checked={replace}
            onChange={setReplace}
            disabled={pending}
            className="rounded-2xl border border-slate-200 bg-slate-50 px-4"
          />
        </div>
      )}
    </Modal>
  );
}

export default GenerateWeekModal;
