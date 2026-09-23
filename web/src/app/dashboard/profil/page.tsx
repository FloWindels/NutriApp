"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiGet, apiPost, apiPut, getErrorMessage } from "@/lib/api-client";
import { queryKeys } from "@/lib/query-keys";
import { formatKcal, formatNumber } from "@/lib/format";
import {
  NIVEAU_ACTIVITE_HELP,
  NIVEAU_ACTIVITE_LABELS,
  OBJECTIF_TYPE_LABELS,
  REGIMES_ADULTES_SEULEMENT,
  REGIME_LABELS,
  SEXE_LABELS,
  SITUATION_LABELS,
} from "@/lib/vocab";
import type {
  Besoins,
  DataEnvelope,
  NiveauActivite,
  ObjectifType,
  Profile,
  ProfileInput,
  ProfilePreview,
  Regime,
  Sexe,
  SituationParticuliere,
} from "@/lib/types/api";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { ErrorState } from "@/components/ui/error-state";
import { Field, SelectField } from "@/components/ui/field";
import { ProgressBar } from "@/components/ui/progress-bar";
import { SectionHeader } from "@/components/ui/section-header";
import { SkeletonCard } from "@/components/ui/skeleton";
import { StatCard } from "@/components/ui/stat-card";
import { useToast } from "@/components/ui/toast";

/**
 * Profil nutritionnel.
 *
 * Aucun calcul n'est fait ici : toutes les valeurs viennent du serveur, seul détenteur des
 * règles (Mifflin-St Jeor ou Schofield selon l'âge, facteurs d'activité, bornes d'ajustement,
 * planchers de sécurité — 1 500 kcal pour un homme, 1 200 pour une femme, et jamais sous le
 * métabolisme de base en perte de poids — macros par régime). GET /profile fournit l'état
 * enregistré, POST /profile/preview simule une modification sans rien écrire.
 */

type FormState = {
  nom: string;
  sexe: "" | Sexe;
  age: string;
  taille: string;
  poids: string;
  poidsSouhaite: string;
  delaiJours: string;
  niveauActivite: "" | NiveauActivite;
  objectifType: "" | ObjectifType;
  regime: "" | Regime;
  situation: SituationParticuliere;
  consentementParental: boolean;
};

const EMPTY_FORM: FormState = {
  nom: "",
  sexe: "",
  age: "",
  taille: "",
  poids: "",
  poidsSouhaite: "",
  delaiJours: "",
  niveauActivite: "",
  objectifType: "",
  regime: "",
  situation: "aucune",
  consentementParental: false,
};

function fromProfile(profile: Profile): FormState {
  return {
    nom: profile.nom ?? "",
    sexe: profile.sexe ?? "",
    age: profile.age != null ? String(profile.age) : "",
    taille: profile.taille != null ? String(profile.taille) : "",
    poids: profile.poids != null ? String(profile.poids) : "",
    poidsSouhaite: profile.poids_souhaite_kg != null ? String(profile.poids_souhaite_kg) : "",
    delaiJours: profile.delai_objectif_jours != null ? String(profile.delai_objectif_jours) : "",
    niveauActivite: profile.niveau_activite ?? "",
    objectifType: profile.objectif_type ?? "",
    regime: profile.regime_alimentaire ?? "",
    situation: profile.situation_particuliere ?? "aucune",
    consentementParental: profile.consentement_parental ?? false,
  };
}

/** Corps envoyé au serveur. `objectif_calcul_auto` rend la main au calcul serveur. */
function toInput(form: FormState): ProfileInput {
  const age = Number(form.age);
  const mineur = Number.isFinite(age) && age < 18;
  const maintien = form.objectifType === "maintenir";

  return {
    nom: form.nom.trim(),
    sexe: form.sexe as Sexe,
    age,
    taille: Number(form.taille),
    poids: Number(form.poids),
    poids_souhaite_kg: maintien || form.poidsSouhaite === "" ? null : Number(form.poidsSouhaite),
    delai_objectif_jours: maintien || form.delaiJours === "" ? null : Number(form.delaiJours),
    niveau_activite: form.niveauActivite as NiveauActivite,
    objectif_type: form.objectifType as ObjectifType,
    regime_alimentaire: form.regime as Regime,
    situation_particuliere: form.situation,
    ...(mineur ? { consentement_parental: form.consentementParental } : {}),
    objectif_calcul_auto: true,
  };
}

const REQUIRED: Array<{ champ: keyof FormState; libelle: string }> = [
  { champ: "nom", libelle: "Renseigne ton nom." },
  { champ: "sexe", libelle: "Choisis ton sexe." },
  { champ: "age", libelle: "Renseigne ton âge." },
  { champ: "taille", libelle: "Renseigne ta taille." },
  { champ: "poids", libelle: "Renseigne ton poids." },
  { champ: "niveauActivite", libelle: "Choisis ton niveau d’activité." },
  { champ: "objectifType", libelle: "Choisis ton objectif." },
  { champ: "regime", libelle: "Choisis ton régime alimentaire." },
];

/** Contrôle minimal, pour éviter un aller-retour inutile. Le serveur reste l'autorité. */
function champsManquants(form: FormState): Partial<Record<keyof FormState, string>> {
  const erreurs: Partial<Record<keyof FormState, string>> = {};
  for (const { champ, libelle } of REQUIRED) {
    if (String(form[champ]).trim() === "") erreurs[champ] = libelle;
  }
  if (form.objectifType !== "" && form.objectifType !== "maintenir") {
    if (form.poidsSouhaite.trim() === "") erreurs.poidsSouhaite = "Renseigne ton poids souhaité.";
    if (form.delaiJours.trim() === "") erreurs.delaiJours = "Renseigne ton délai en jours.";
  }
  return erreurs;
}

export default function ProfilPage() {
  const queryClient = useQueryClient();
  const toast = useToast();

  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [hydrateDepuis, setHydrateDepuis] = useState<string | null>(null);
  const [simulation, setSimulation] = useState<ProfileInput | null>(null);
  const [erreursLocales, setErreursLocales] = useState<Partial<Record<keyof FormState, string>>>({});
  const [consentementSante, setConsentementSante] = useState(false);
  const [erreurGlobale, setErreurGlobale] = useState<string | null>(null);

  const profileQuery = useQuery({
    queryKey: queryKeys.profile,
    queryFn: () => apiGet<Profile>("/profile"),
  });
  const profile = profileQuery.data ?? null;

  // Hydratation du formulaire pendant le rendu : React 19 interdit setState dans un effet.
  const cleProfil = profile ? JSON.stringify(fromProfile(profile)) : null;
  if (cleProfil !== null && hydrateDepuis === null) {
    setHydrateDepuis(cleProfil);
    if (profile?.has_profile) setForm(fromProfile(profile));
  }

  const previewQuery = useQuery({
    queryKey: queryKeys.profilePreview(simulation ?? undefined),
    queryFn: () => apiPost<DataEnvelope<ProfilePreview>>("/profile/preview", simulation),
    enabled: simulation !== null,
  });

  // Le serveur est la seule source des chiffres : la simulation quand elle existe, sinon
  // les besoins déjà calculés et renvoyés par GET /profile.
  const besoins: Besoins | null = previewQuery.data?.data.besoins ?? profile?.besoins ?? null;
  const consentementDonne = profile?.consentement_sante ?? false;
  const age = Number(form.age);
  const mineurJeune = Number.isFinite(age) && age > 0 && age < 15;
  const maintien = form.objectifType === "maintenir";

  const save = useMutation({
    mutationFn: (input: ProfileInput) => apiPut<Profile>("/profile", input),
    onSuccess: (data) => {
      queryClient.setQueryData(queryKeys.profile, data);
      queryClient.invalidateQueries({ queryKey: queryKeys.profile });
      queryClient.invalidateQueries({ queryKey: ["dashboard"] });
      queryClient.invalidateQueries({ queryKey: ["meals"] });
      setErreursLocales({});
      setErreurGlobale(null);
      toast.success("Profil enregistré.", "Tes objectifs ont été recalculés.");
    },
    onError: (error) => {
      if (error instanceof ApiError && Object.keys(error.fieldErrors).length > 0) {
        setErreursLocales(traduireErreursServeur(error));
      }
      setErreurGlobale(getErrorMessage(error, "Impossible d’enregistrer le profil."));
    },
  });

  function setChamp<K extends keyof FormState>(champ: K, valeur: FormState[K]) {
    setForm((precedent) => ({ ...precedent, [champ]: valeur }));
    setErreursLocales((precedent) => ({ ...precedent, [champ]: undefined }));
  }

  function simuler() {
    setErreurGlobale(null);
    const erreurs = champsManquants(form);
    setErreursLocales(erreurs);
    if (Object.keys(erreurs).length > 0) return;
    setSimulation(toInput(form));
  }

  function reinitialiser() {
    setForm(profile?.has_profile ? fromProfile(profile) : EMPTY_FORM);
    setErreursLocales({});
    setSimulation(null);
    setErreurGlobale(null);
  }

  function enregistrer() {
    setErreurGlobale(null);
    const erreurs = champsManquants(form);
    setErreursLocales(erreurs);
    if (Object.keys(erreurs).length > 0) return;
    if (!consentementDonne && !consentementSante) {
      setErreurGlobale(
        "Ton accord est nécessaire pour calculer des objectifs à partir de tes données de santé.",
      );
      return;
    }
    const input = toInput(form);
    setSimulation(input);
    save.mutate(consentementDonne ? input : { ...input, consentement_sante: true });
  }

  if (profileQuery.isPending) {
    return (
      <div className="mx-auto max-w-6xl space-y-4">
        <SkeletonCard lines={6} />
        <SkeletonCard lines={4} />
      </div>
    );
  }

  if (profileQuery.isError) {
    return (
      <ErrorState
        title="Profil indisponible"
        message={getErrorMessage(profileQuery.error, "Impossible de charger ton profil.")}
        onRetry={() => profileQuery.refetch()}
        retrying={profileQuery.isFetching}
      />
    );
  }

  const erreurPreview =
    previewQuery.isError && simulation !== null
      ? getErrorMessage(previewQuery.error, "Le calcul n’a pas abouti.")
      : null;

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <SectionHeader
        eyebrow="Nutrition"
        level="page"
        title="Mon profil"
        subtitle="Tes objectifs sont calculés par Mavi’oh à partir de ces informations, puis vérifiés par des bornes de sécurité."
      />

      <div className="grid gap-5 lg:grid-cols-[1.05fr_0.95fr]">
        <Card tone="emerald" padding="md" className="space-y-4">
          <CardHeader title="Mes informations" subtitle="Modifie, simule, puis enregistre." />

          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              label="Nom"
              required
              value={form.nom}
              onChange={(e) => setChamp("nom", e.target.value)}
              error={erreursLocales.nom}
            />

            <SelectField
              label="Sexe"
              required
              value={form.sexe}
              onChange={(e) => setChamp("sexe", e.target.value as Sexe)}
              error={erreursLocales.sexe}
            >
              <option value="">Sélectionner</option>
              {Object.entries(SEXE_LABELS).map(([valeur, libelle]) => (
                <option key={valeur} value={valeur}>
                  {libelle}
                </option>
              ))}
            </SelectField>

            <Field
              label="Âge"
              type="number"
              inputMode="numeric"
              required
              value={form.age}
              onChange={(e) => setChamp("age", e.target.value)}
              error={erreursLocales.age}
            />

            <Field
              label="Taille (cm)"
              type="number"
              inputMode="numeric"
              required
              value={form.taille}
              onChange={(e) => setChamp("taille", e.target.value)}
              error={erreursLocales.taille}
            />

            <Field
              label="Poids actuel (kg)"
              type="number"
              inputMode="decimal"
              step="0.1"
              required
              value={form.poids}
              onChange={(e) => setChamp("poids", e.target.value)}
              error={erreursLocales.poids}
            />

            <SelectField
              label="Objectif"
              required
              value={form.objectifType}
              onChange={(e) => setChamp("objectifType", e.target.value as ObjectifType)}
              error={erreursLocales.objectifType}
            >
              <option value="">Sélectionner</option>
              {Object.entries(OBJECTIF_TYPE_LABELS).map(([valeur, libelle]) => (
                <option key={valeur} value={valeur}>
                  {libelle}
                </option>
              ))}
            </SelectField>

            {!maintien ? (
              <>
                <Field
                  label="Poids souhaité (kg)"
                  type="number"
                  inputMode="decimal"
                  step="0.1"
                  value={form.poidsSouhaite}
                  onChange={(e) => setChamp("poidsSouhaite", e.target.value)}
                  error={erreursLocales.poidsSouhaite}
                />
                <Field
                  label="Délai (jours)"
                  type="number"
                  inputMode="numeric"
                  value={form.delaiJours}
                  onChange={(e) => setChamp("delaiJours", e.target.value)}
                  error={erreursLocales.delaiJours}
                  hint="Minimum retenu par le calcul : 28 jours."
                />
              </>
            ) : null}

            <SelectField
              label="Niveau d’activité"
              required
              value={form.niveauActivite}
              onChange={(e) => setChamp("niveauActivite", e.target.value as NiveauActivite)}
              error={erreursLocales.niveauActivite}
              hint={
                form.niveauActivite !== "" ? NIVEAU_ACTIVITE_HELP[form.niveauActivite] : undefined
              }
            >
              <option value="">Sélectionner</option>
              {Object.entries(NIVEAU_ACTIVITE_LABELS).map(([valeur, libelle]) => (
                <option key={valeur} value={valeur}>
                  {libelle}
                </option>
              ))}
            </SelectField>

            <SelectField
              label="Régime alimentaire"
              required
              value={form.regime}
              onChange={(e) => setChamp("regime", e.target.value as Regime)}
              error={erreursLocales.regime}
            >
              <option value="">Sélectionner</option>
              {Object.entries(REGIME_LABELS).map(([valeur, libelle]) => (
                <option
                  key={valeur}
                  value={valeur}
                  disabled={
                    Number.isFinite(age) &&
                    age > 0 &&
                    age < 18 &&
                    REGIMES_ADULTES_SEULEMENT.includes(valeur as Regime)
                  }
                >
                  {libelle}
                </option>
              ))}
            </SelectField>

            <SelectField
              label="Situation particulière"
              value={form.situation}
              onChange={(e) => setChamp("situation", e.target.value as SituationParticuliere)}
              error={erreursLocales.situation}
              hint="Grossesse, allaitement ou suivi médical imposent le maintien du poids."
            >
              {Object.entries(SITUATION_LABELS).map(([valeur, libelle]) => (
                <option key={valeur} value={valeur}>
                  {libelle}
                </option>
              ))}
            </SelectField>
          </div>

          {mineurJeune ? (
            <label className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <input
                type="checkbox"
                checked={form.consentementParental}
                onChange={(e) => setChamp("consentementParental", e.target.checked)}
                className="mt-0.5 h-4 w-4 shrink-0 rounded border-amber-300 accent-emerald-700"
              />
              <span>
                Un parent ou tuteur autorise l’usage de ces données. Cet accord est obligatoire
                avant 15 ans.
                {erreursLocales.consentementParental ? (
                  <span className="mt-1 block font-medium text-rose-600">
                    {erreursLocales.consentementParental}
                  </span>
                ) : null}
              </span>
            </label>
          ) : null}

          {!consentementDonne ? (
            <label className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <input
                type="checkbox"
                checked={consentementSante}
                onChange={(e) => {
                  setConsentementSante(e.target.checked);
                  if (e.target.checked) setErreurGlobale(null);
                }}
                className="mt-0.5 h-4 w-4 shrink-0 rounded border-amber-300 accent-emerald-700"
              />
              <span>
                J’autorise Mavi’oh à utiliser mon âge, mon sexe, ma taille et mon poids pour
                calculer mes objectifs nutritionnels. Ces données restent sur ce serveur ; tu peux
                les exporter ou supprimer ton compte depuis les paramètres.
              </span>
            </label>
          ) : null}

          <div className="flex flex-wrap gap-3">
            <Button type="button" onClick={simuler} loading={previewQuery.isFetching}>
              Calculer
            </Button>
            <Button type="button" variant="secondary" onClick={reinitialiser}>
              Réinitialiser
            </Button>
            <Button type="button" variant="secondary" onClick={enregistrer} loading={save.isPending}>
              Enregistrer
            </Button>
          </div>

          {erreurGlobale ? <Banner tone="error">{erreurGlobale}</Banner> : null}
          {erreurPreview ? <Banner tone="warning">{erreurPreview}</Banner> : null}
        </Card>

        <Card padding="md" className="space-y-4">
          <CardHeader
            title="Résultat nutritionnel"
            subtitle={
              simulation !== null ? "Simulation, non enregistrée." : "D’après ton profil enregistré."
            }
          />

          {besoins === null ? (
            <p className="text-sm text-slate-600">
              Complète le formulaire puis clique sur « Calculer » pour obtenir tes objectifs.
            </p>
          ) : (
            <ResultatNutritionnel besoins={besoins} />
          )}
        </Card>
      </div>
    </div>
  );
}

function ResultatNutritionnel({ besoins }: { besoins: Besoins }) {
  const [etapesOuvertes, setEtapesOuvertes] = useState(false);
  // Jauge de sécurité : 1 kg par semaine est la variation maximale raisonnable.
  const jauge = Math.min(Math.round((Math.abs(besoins.variation_hebdo_kg) / 1) * 100), 100);

  return (
    <div className="space-y-4">
      <div className="rounded-2xl bg-emerald-700 px-5 py-4 text-white">
        <p className="text-xs uppercase tracking-[0.12em] text-emerald-100">Calories cibles</p>
        <p className="mt-1 text-3xl font-semibold">{formatKcal(besoins.calories_recommandees)}</p>
        <p className="mt-1 text-xs text-emerald-100">
          Métabolisme de base {formatKcal(besoins.bmr)} · dépense {formatKcal(besoins.tdee)} ·
          plancher de sécurité {formatKcal(besoins.plancher_kcal)}
        </p>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <StatCard label="Protéines" value={`${formatNumber(besoins.proteines_g, 0)} g`} />
        <StatCard label="Lipides" value={`${formatNumber(besoins.lipides_g, 0)} g`} />
        <StatCard label="Glucides" value={`${formatNumber(besoins.glucides_g, 0)} g`} />
      </div>

      <ProgressBar
        value={jauge}
        tone={jauge >= 100 ? "amber" : "emerald"}
        label="Variation hebdomadaire"
        caption={`${formatNumber(besoins.variation_hebdo_kg, 2)} kg / semaine`}
      />

      <div className="grid gap-3 sm:grid-cols-2">
        <StatCard label="IMC" value={formatNumber(besoins.imc, 1)} />
        <StatCard
          label="Poids de référence"
          value={`${formatNumber(besoins.poids_reference, 1)} kg`}
          caption={
            besoins.jours_restants != null ? `${besoins.jours_restants} jours restants` : undefined
          }
        />
      </div>

      {besoins.avertissements.length > 0 ? (
        <div className="space-y-2">
          {besoins.avertissements.map((avertissement) => (
            <Banner key={avertissement} tone="warning">
              {avertissement}
            </Banner>
          ))}
        </div>
      ) : null}

      <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
        <button
          type="button"
          onClick={() => setEtapesOuvertes((ouvert) => !ouvert)}
          className="flex w-full items-center justify-between text-sm font-semibold text-slate-900"
          aria-expanded={etapesOuvertes}
        >
          Comment ce calcul est fait
          <span aria-hidden="true">{etapesOuvertes ? "−" : "+"}</span>
        </button>
        {etapesOuvertes ? (
          <ol className="mt-3 space-y-1.5 text-sm text-slate-600">
            {besoins.etapes.map((etape, index) => (
              <li key={etape} className="flex gap-2">
                <span className="text-slate-400">{index + 1}.</span>
                <span>{etape}</span>
              </li>
            ))}
          </ol>
        ) : null}
      </div>

      <p className="text-xs leading-5 text-slate-500">{besoins.mention}</p>
    </div>
  );
}

/** Rattache les erreurs de validation du serveur aux champs du formulaire. */
function traduireErreursServeur(error: ApiError): Partial<Record<keyof FormState, string>> {
  const correspondances: Record<string, keyof FormState> = {
    nom: "nom",
    sexe: "sexe",
    age: "age",
    taille: "taille",
    poids: "poids",
    poids_souhaite_kg: "poidsSouhaite",
    delai_objectif_jours: "delaiJours",
    niveau_activite: "niveauActivite",
    objectif_type: "objectifType",
    regime_alimentaire: "regime",
    situation_particuliere: "situation",
    consentement_parental: "consentementParental",
  };

  const erreurs: Partial<Record<keyof FormState, string>> = {};
  for (const [champServeur, messages] of Object.entries(error.fieldErrors)) {
    const champ = correspondances[champServeur];
    if (champ && messages.length > 0) erreurs[champ] = messages[0];
  }
  return erreurs;
}
