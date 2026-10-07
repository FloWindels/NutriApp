"use client";

import { useRouter } from "next/navigation";
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { ProgressBar } from "@/components/ui/progress-bar";
import { SkeletonCard } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { ApiError, apiGet, apiPost, apiPut, getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { formatKcal, formatNumber } from "@/lib/format";
import { queryKeys } from "@/lib/query-keys";
import { useSession } from "@/lib/session";
import type {
  DataEnvelope,
  Me,
  NiveauActivite,
  ObjectifType,
  Profile,
  ProfileInput,
  ProfilePreview,
  Regime,
  Sexe,
  SituationParticuliere,
} from "@/lib/types/api";
import {
  NIVEAU_ACTIVITE_HELP,
  NIVEAU_ACTIVITE_LABELS,
  OBJECTIF_TYPE_LABELS,
  REGIMES_ADULTES_SEULEMENT,
  REGIME_LABELS,
  SEXE_LABELS,
  SITUATION_LABELS,
} from "@/lib/vocab";
import { demanderVisite, reporterAccueil, useParametresAccueil } from "./use-accueil";

/**
 * Questionnaire de bienvenue : les informations du profil nutritionnel, une question à la fois.
 *
 * Il écrit exactement ce qu'écrit la page Profil (PUT /profile, `objectif_calcul_auto` à vrai)
 * et ne calcule rien lui-même : le récapitulatif vient de POST /profile/preview, les chiffres
 * enregistrés de la réponse du PUT. Le rythme de perte intense n'est pas proposé ici : il
 * demande de lire un avertissement détaillé, que la page Profil présente.
 */

type Form = {
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

type Erreurs = Partial<Record<keyof Form | "consentementSante", string>>;

type EtapeId = "identite" | "mesures" | "activite" | "objectif" | "alimentation" | "accord" | "recap";

const ETAPES: { id: EtapeId; titre: string; sousTitre: string }[] = [
  {
    id: "identite",
    titre: "Faisons connaissance",
    sousTitre: "Ton âge et ton sexe servent à estimer ce que ton corps dépense au repos.",
  },
  {
    id: "mesures",
    titre: "Ta taille et ton poids",
    sousTitre: "Ton point de départ. Tu pourras suivre ton poids au fil des semaines.",
  },
  {
    id: "activite",
    titre: "Ton activité au quotidien",
    sousTitre: "Hors séances de sport : celles-ci s’ajoutent quand tu les enregistres.",
  },
  {
    id: "objectif",
    titre: "Ton objectif",
    sousTitre: "Mavi’oh ajuste tes calories pour t’y emmener à un rythme raisonnable.",
  },
  {
    id: "alimentation",
    titre: "Ta façon de manger",
    sousTitre: "Ton régime oriente tes macros, tes recettes et les conseils du coach.",
  },
  {
    id: "accord",
    titre: "Ton accord",
    sousTitre: "Ces informations sont des données de santé : on te demande la permission.",
  },
  {
    id: "recap",
    titre: "Tes objectifs",
    sousTitre: "Calculés à partir de tes réponses. Tu pourras les modifier depuis ton profil.",
  },
];

/** Quels champs chaque étape porte, pour y revenir quand le serveur en refuse un. */
const CHAMPS_PAR_ETAPE: Record<EtapeId, (keyof Erreurs)[]> = {
  identite: ["nom", "sexe", "age", "consentementParental"],
  mesures: ["taille", "poids"],
  activite: ["niveauActivite"],
  objectif: ["objectifType", "poidsSouhaite", "delaiJours"],
  alimentation: ["regime", "situation"],
  accord: ["consentementSante"],
  recap: [],
};

const CHAMP_SERVEUR: Record<string, keyof Erreurs> = {
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
  consentement_sante: "consentementSante",
};

const DELAIS: { jours: number; libelle: string }[] = [
  { jours: 30, libelle: "1 mois" },
  { jours: 90, libelle: "3 mois" },
  { jours: 180, libelle: "6 mois" },
  { jours: 365, libelle: "1 an" },
];

function formDepuis(profile: Profile | null, nomParDefaut: string): Form {
  return {
    nom: profile?.nom ?? nomParDefaut,
    sexe: profile?.sexe ?? "",
    age: profile?.age != null ? String(profile.age) : "",
    taille: profile?.taille != null ? String(profile.taille) : "",
    poids: profile?.poids != null ? String(profile.poids) : "",
    poidsSouhaite: profile?.poids_souhaite_kg != null ? String(profile.poids_souhaite_kg) : "",
    delaiJours: profile?.delai_objectif_jours != null ? String(profile.delai_objectif_jours) : "",
    niveauActivite: profile?.niveau_activite ?? "",
    objectifType: profile?.objectif_type ?? "",
    regime: profile?.regime_alimentaire ?? "",
    situation: profile?.situation_particuliere ?? "aucune",
    consentementParental: profile?.consentement_parental ?? false,
  };
}

/** Même corps que la page Profil, sans le rythme intense (voir l'en-tête). */
function versInput(form: Form): ProfileInput {
  const age = Number(form.age);
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
    ...(Number.isFinite(age) && age < 18 ? { consentement_parental: form.consentementParental } : {}),
    objectif_calcul_auto: true,
  };
}

function nombre(valeur: string): number | null {
  if (valeur.trim() === "") return null;
  const n = Number(valeur.replace(",", "."));
  return Number.isFinite(n) ? n : null;
}

/** Contrôle de l'étape avant d'avancer. Le serveur reste l'autorité, ceci évite l'aller-retour. */
function verifier(etape: EtapeId, form: Form, accordDonne: boolean, accord: boolean): Erreurs {
  const e: Erreurs = {};
  const age = nombre(form.age);
  switch (etape) {
    case "identite":
      if (form.nom.trim() === "") e.nom = "Dis-nous comment t’appeler.";
      if (form.sexe === "") e.sexe = "Choisis une réponse.";
      if (age === null) e.age = "Renseigne ton âge.";
      else if (!Number.isInteger(age) || age < 12 || age > 120)
        e.age = "Mavi’oh s’adresse aux personnes de 12 à 120 ans.";
      else if (age < 15 && !form.consentementParental)
        e.consentementParental = "Avant 15 ans, l’accord d’un parent ou tuteur est obligatoire.";
      break;
    case "mesures": {
      const taille = nombre(form.taille);
      const poids = nombre(form.poids);
      if (taille === null || taille < 100 || taille > 300) e.taille = "Entre 100 et 300 cm.";
      if (poids === null || poids < 20 || poids > 500) e.poids = "Entre 20 et 500 kg.";
      break;
    }
    case "activite":
      if (form.niveauActivite === "") e.niveauActivite = "Choisis le niveau qui te ressemble le plus.";
      break;
    case "objectif": {
      if (form.objectifType === "") {
        e.objectifType = "Choisis un objectif.";
        break;
      }
      if (form.objectifType === "maintenir") break;
      const vise = nombre(form.poidsSouhaite);
      const poids = nombre(form.poids);
      const delai = nombre(form.delaiJours);
      if (vise === null || vise < 20 || vise > 500) e.poidsSouhaite = "Renseigne le poids que tu vises.";
      else if (poids !== null && form.objectifType === "perdre" && vise >= poids)
        e.poidsSouhaite = "Pour perdre du poids, vise un poids inférieur à ton poids actuel.";
      else if (poids !== null && form.objectifType === "prendre" && vise <= poids)
        e.poidsSouhaite = "Pour prendre du poids, vise un poids supérieur à ton poids actuel.";
      if (delai === null || !Number.isInteger(delai) || delai < 1 || delai > 2000)
        e.delaiJours = "Choisis un délai, ou indique-le en jours.";
      break;
    }
    case "alimentation":
      if (form.regime === "") e.regime = "Choisis ton régime, « Omnivore » si tu manges de tout.";
      else if (age !== null && age < 18 && REGIMES_ADULTES_SEULEMENT.includes(form.regime))
        e.regime = "Ce régime n’est pas proposé avant 18 ans.";
      break;
    case "accord":
      if (!accordDonne && !accord)
        e.consentementSante = "Sans ton accord, Mavi’oh ne peut pas calculer tes objectifs.";
      break;
    case "recap":
      break;
  }
  return e;
}

export function Questionnaire() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const toast = useToast();
  const { user } = useSession();
  const { settings, marquer } = useParametresAccueil();

  const profileQuery = useQuery({
    queryKey: queryKeys.profile,
    queryFn: () => apiGet<Profile>("/profile"),
  });
  const profile = profileQuery.data ?? null;

  const [form, setForm] = useState<Form | null>(null);
  const [index, setIndex] = useState(0);
  const [erreurs, setErreurs] = useState<Erreurs>({});
  const [accord, setAccord] = useState(false);
  const [erreurGlobale, setErreurGlobale] = useState<string | null>(null);
  const titreRef = useRef<HTMLHeadingElement>(null);
  const premierRendu = useRef(true);

  // Pré-remplissage pendant le rendu, une seule fois : depuis le profil s'il existe (on peut
  // revenir au questionnaire depuis la page Profil), sinon avec le nom du compte.
  if (form === null && !profileQuery.isPending) {
    setForm(formDepuis(profile, user?.name ?? ""));
  }

  const etape = ETAPES[index];
  const accordDonne = profile?.consentement_sante ?? false;

  // Le titre de chaque étape prend le focus : un lecteur d'écran annonce la nouvelle question.
  useEffect(() => {
    if (premierRendu.current) {
      premierRendu.current = false;
      return;
    }
    titreRef.current?.focus();
  }, [index]);

  const input = form && etape.id === "recap" ? versInput(form) : null;
  const apercu = useQuery({
    queryKey: queryKeys.profilePreview(input ?? undefined),
    queryFn: () => apiPost<DataEnvelope<ProfilePreview>>("/profile/preview", input),
    enabled: input !== null,
    retry: false,
  });

  const enregistrer = useMutation({
    mutationFn: async (corps: ProfileInput) => {
      const profil = await apiPut<Profile>("/profile", corps);
      // Le profil est enregistré : l'échec de cette seconde écriture ne doit pas l'annuler.
      await marquer.mutateAsync({ onboarding_termine: true }).catch(() => undefined);
      return profil;
    },
    onSuccess: (profil) => {
      queryClient.setQueryData(queryKeys.profile, profil);
      // `/me` dit encore « pas de profil » : sans cette mise à jour, l'accueil renverrait ici.
      queryClient.setQueryData<Me>(queryKeys.me, (me) =>
        me ? { ...me, has_profile: true, consentement_sante: true } : me,
      );
      queryClient.invalidateQueries({ queryKey: queryKeys.me });
      queryClient.invalidateQueries({ queryKey: ["dashboard"] });
      queryClient.invalidateQueries({ queryKey: ["meals"] });
      // Première fois : la visite guidée enchaîne. Questionnaire refait plus tard : on ne la
      // réimpose pas, elle reste accessible depuis les Paramètres.
      const premiereVisite = settings?.visite_vue_le == null;
      toast.success(
        "Profil enregistré.",
        premiereVisite ? "Place à la visite guidée de Mavi’oh." : "Tes objectifs ont été recalculés.",
      );
      if (premiereVisite) demanderVisite();
      router.replace("/dashboard");
    },
    onError: (error) => {
      if (error instanceof ApiError && Object.keys(error.fieldErrors).length > 0) {
        const traduites: Erreurs = {};
        for (const [champ, messages] of Object.entries(error.fieldErrors)) {
          const local = CHAMP_SERVEUR[champ];
          if (local && messages.length > 0) traduites[local] = messages[0];
        }
        setErreurs(traduites);
        // On retourne à la première étape concernée.
        const cible = ETAPES.findIndex((e) => CHAMPS_PAR_ETAPE[e.id].some((c) => traduites[c]));
        if (cible >= 0) setIndex(cible);
      }
      setErreurGlobale(getErrorMessage(error, "Impossible d’enregistrer ton profil."));
    },
  });

  if (profileQuery.isPending || form === null) {
    return <SkeletonCard lines={6} />;
  }

  function changer<K extends keyof Form>(champ: K, valeur: Form[K]) {
    setForm((precedent) => (precedent ? { ...precedent, [champ]: valeur } : precedent));
    setErreurs((precedent) => ({ ...precedent, [champ]: undefined }));
  }

  function suivant(event?: FormEvent) {
    event?.preventDefault();
    if (!form) return;
    setErreurGlobale(null);
    if (etape.id === "recap") {
      const corps = versInput(form);
      enregistrer.mutate(accordDonne ? corps : { ...corps, consentement_sante: true });
      return;
    }
    const trouvees = verifier(etape.id, form, accordDonne, accord);
    setErreurs(trouvees);
    if (Object.keys(trouvees).length > 0) return;
    setIndex((i) => Math.min(i + 1, ETAPES.length - 1));
  }

  function precedent() {
    setErreurGlobale(null);
    setErreurs({});
    setIndex((i) => Math.max(i - 1, 0));
  }

  function plusTard() {
    reporterAccueil();
    router.replace("/dashboard");
  }

  const age = nombre(form.age);
  const mineur = age !== null && age < 18;
  const derniere = etape.id === "recap";

  return (
    <div className="mx-auto w-full max-w-2xl">
      <div className="mb-4 flex items-center justify-between gap-3">
        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-emerald-700">
          Bienvenue sur Mavi’oh
        </p>
        <Button variant="ghost" size="sm" onClick={plusTard}>
          Plus tard
        </Button>
      </div>

      <ProgressBar
        value={((index + 1) / ETAPES.length) * 100}
        size="sm"
        label={`Étape ${index + 1} sur ${ETAPES.length}`}
        className="mb-5"
      />

      <form
        onSubmit={suivant}
        noValidate
        className="rounded-2xl border border-emerald-100 bg-white p-4 shadow-[0_14px_40px_rgba(15,23,42,0.06)] sm:rounded-[1.75rem] sm:p-6"
      >
        <h1
          ref={titreRef}
          tabIndex={-1}
          className="text-xl font-semibold tracking-tight text-slate-900 outline-none sm:text-2xl"
        >
          {etape.titre}
        </h1>
        <p className="mt-1 text-sm leading-6 text-slate-600">{etape.sousTitre}</p>

        <div className="mt-5 space-y-5">
          {etape.id === "identite" ? (
            <>
              <Field
                label="Comment veux-tu qu’on t’appelle ?"
                autoComplete="given-name"
                value={form.nom}
                onChange={(e) => changer("nom", e.target.value)}
                error={erreurs.nom}
              />
              <Choix
                legende="Sexe"
                options={Object.entries(SEXE_LABELS).map(([valeur, libelle]) => ({ valeur, libelle }))}
                valeur={form.sexe}
                onChange={(v) => changer("sexe", v as Sexe)}
                erreur={erreurs.sexe}
                colonnes={2}
              />
              <Field
                label="Âge"
                type="number"
                inputMode="numeric"
                min={12}
                max={120}
                value={form.age}
                onChange={(e) => changer("age", e.target.value)}
                error={erreurs.age}
                wrapperClassName="max-w-[12rem]"
              />
              {age !== null && age >= 12 && age < 15 ? (
                <CaseAccord
                  coche={form.consentementParental}
                  onChange={(v) => changer("consentementParental", v)}
                  erreur={erreurs.consentementParental}
                >
                  Un parent ou tuteur autorise l’usage de ces données. Cet accord est obligatoire
                  avant 15 ans.
                </CaseAccord>
              ) : null}
            </>
          ) : null}

          {etape.id === "mesures" ? (
            <div className="grid gap-4 sm:grid-cols-2">
              <Field
                label="Taille (cm)"
                type="number"
                inputMode="numeric"
                value={form.taille}
                onChange={(e) => changer("taille", e.target.value)}
                error={erreurs.taille}
                placeholder="170"
              />
              <Field
                label="Poids actuel (kg)"
                type="number"
                inputMode="decimal"
                step="0.1"
                value={form.poids}
                onChange={(e) => changer("poids", e.target.value)}
                error={erreurs.poids}
                placeholder="70"
              />
            </div>
          ) : null}

          {etape.id === "activite" ? (
            <Choix
              legende="Niveau d’activité"
              cachee
              options={Object.entries(NIVEAU_ACTIVITE_LABELS).map(([valeur, libelle]) => ({
                valeur,
                libelle,
                aide: NIVEAU_ACTIVITE_HELP[valeur as NiveauActivite],
              }))}
              valeur={form.niveauActivite}
              onChange={(v) => changer("niveauActivite", v as NiveauActivite)}
              erreur={erreurs.niveauActivite}
            />
          ) : null}

          {etape.id === "objectif" ? (
            <>
              <Choix
                legende="Objectif"
                cachee
                options={Object.entries(OBJECTIF_TYPE_LABELS).map(([valeur, libelle]) => ({ valeur, libelle }))}
                valeur={form.objectifType}
                onChange={(v) => changer("objectifType", v as ObjectifType)}
                erreur={erreurs.objectifType}
              />
              {form.objectifType !== "" && form.objectifType !== "maintenir" ? (
                <div className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                  <Field
                    label="Poids visé (kg)"
                    type="number"
                    inputMode="decimal"
                    step="0.1"
                    value={form.poidsSouhaite}
                    onChange={(e) => changer("poidsSouhaite", e.target.value)}
                    error={erreurs.poidsSouhaite}
                    wrapperClassName="max-w-[14rem]"
                  />
                  <fieldset>
                    <legend className="mb-2 text-sm font-medium text-slate-700">En combien de temps ?</legend>
                    <div className="flex flex-wrap gap-2">
                      {DELAIS.map((d) => (
                        <button
                          key={d.jours}
                          type="button"
                          aria-pressed={form.delaiJours === String(d.jours)}
                          onClick={() => changer("delaiJours", String(d.jours))}
                          className={cn(
                            "h-11 rounded-2xl border px-4 text-sm font-medium transition",
                            form.delaiJours === String(d.jours)
                              ? "border-emerald-700 bg-emerald-700 text-white"
                              : "border-slate-200 bg-white text-slate-700 hover:border-slate-300",
                          )}
                        >
                          {d.libelle}
                        </button>
                      ))}
                    </div>
                    <Field
                      label="Ou un délai précis, en jours"
                      type="number"
                      inputMode="numeric"
                      value={form.delaiJours}
                      onChange={(e) => changer("delaiJours", e.target.value)}
                      error={erreurs.delaiJours}
                      hint="Si ce délai est trop court pour ta santé, Mavi’oh te proposera un rythme plus sûr."
                      wrapperClassName="mt-3 max-w-[16rem]"
                    />
                  </fieldset>
                </div>
              ) : null}
            </>
          ) : null}

          {etape.id === "alimentation" ? (
            <>
              <fieldset>
                <legend className="mb-2 text-sm font-medium text-slate-700">Régime alimentaire</legend>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                  {Object.entries(REGIME_LABELS).map(([valeur, libelle]) => {
                    const interdit = mineur && REGIMES_ADULTES_SEULEMENT.includes(valeur as Regime);
                    const actif = form.regime === valeur;
                    return (
                      <button
                        key={valeur}
                        type="button"
                        disabled={interdit}
                        aria-pressed={actif}
                        onClick={() => changer("regime", valeur as Regime)}
                        className={cn(
                          "min-h-11 rounded-2xl border px-3 py-2 text-left text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-40",
                          actif
                            ? "border-emerald-700 bg-emerald-700 text-white"
                            : "border-slate-200 bg-white text-slate-700 hover:border-slate-300",
                        )}
                      >
                        {libelle}
                      </button>
                    );
                  })}
                </div>
                {erreurs.regime ? (
                  <p role="alert" className="mt-1.5 text-xs font-medium text-rose-600">
                    {erreurs.regime}
                  </p>
                ) : mineur ? (
                  <p className="mt-1.5 text-xs text-slate-500">
                    Certains régimes restrictifs ne sont pas proposés avant 18 ans.
                  </p>
                ) : null}
              </fieldset>
              <Choix
                legende="Une situation particulière ?"
                options={Object.entries(SITUATION_LABELS).map(([valeur, libelle]) => ({ valeur, libelle }))}
                valeur={form.situation}
                onChange={(v) => changer("situation", v as SituationParticuliere)}
                erreur={erreurs.situation}
                colonnes={2}
                aide="Grossesse, allaitement ou suivi médical : Mavi’oh garde alors ton poids stable."
              />
            </>
          ) : null}

          {etape.id === "accord" ? (
            accordDonne ? (
              <Banner tone="success">
                Tu as déjà donné ton accord. Tu peux le retirer à tout moment en supprimant ton compte
                depuis les paramètres.
              </Banner>
            ) : (
              <>
                <ul className="space-y-2 text-sm leading-6 text-slate-600">
                  <li>Ton âge, ton sexe, ta taille et ton poids servent uniquement à calculer tes objectifs.</li>
                  <li>Ils restent sur le serveur de Mavi’oh et ne sont ni vendus ni partagés.</li>
                  <li>Tu peux les exporter ou supprimer ton compte depuis les paramètres, à tout moment.</li>
                </ul>
                <CaseAccord
                  coche={accord}
                  onChange={(v) => {
                    setAccord(v);
                    setErreurs((p) => ({ ...p, consentementSante: undefined }));
                  }}
                  erreur={erreurs.consentementSante}
                >
                  J’autorise Mavi’oh à utiliser mon âge, mon sexe, ma taille et mon poids pour calculer
                  mes objectifs nutritionnels.
                </CaseAccord>
              </>
            )
          ) : null}

          {etape.id === "recap" ? (
            <Recapitulatif
              chargement={apercu.isFetching && !apercu.data}
              apercu={apercu.data?.data ?? null}
              erreur={apercu.isError ? getErrorMessage(apercu.error, "Le calcul n’a pas abouti.") : null}
              onReessayer={() => apercu.refetch()}
              onUtiliserDelai={(jours) => changer("delaiJours", String(jours))}
            />
          ) : null}

          {erreurGlobale ? <Banner tone="error">{erreurGlobale}</Banner> : null}
        </div>

        <div className="mt-6 flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
          {index > 0 ? (
            <Button variant="secondary" onClick={precedent} disabled={enregistrer.isPending}>
              Précédent
            </Button>
          ) : (
            <span className="hidden sm:block" />
          )}
          <Button
            type="submit"
            size="lg"
            loading={enregistrer.isPending}
            disabled={derniere && (apercu.isFetching || apercu.isError)}
          >
            {derniere ? "Enregistrer et découvrir Mavi’oh" : "Suivant"}
          </Button>
        </div>
      </form>

      <p className="mt-4 text-center text-xs leading-5 text-slate-500">
        Tout se modifie plus tard depuis ton profil. Les objectifs sont des estimations, pas un avis
        médical.
      </p>
    </div>
  );
}

/** Choix exclusif en grosses cases, plus confortable au pouce qu'une liste déroulante. */
function Choix({
  legende,
  cachee = false,
  options,
  valeur,
  onChange,
  erreur,
  aide,
  colonnes = 1,
}: {
  legende: string;
  /** Le titre de l'étape dit déjà la question : la légende reste pour les lecteurs d'écran. */
  cachee?: boolean;
  options: { valeur: string; libelle: string; aide?: string }[];
  valeur: string;
  onChange: (valeur: string) => void;
  erreur?: string;
  aide?: string;
  colonnes?: 1 | 2;
}) {
  return (
    <fieldset>
      <legend className={cn("mb-2 text-sm font-medium text-slate-700", cachee && "sr-only")}>{legende}</legend>
      <div className={cn("grid gap-2", colonnes === 2 && "grid-cols-2")}>
        {options.map((option) => {
          const actif = option.valeur === valeur;
          return (
            <button
              key={option.valeur}
              type="button"
              aria-pressed={actif}
              onClick={() => onChange(option.valeur)}
              className={cn(
                "flex min-h-12 w-full items-center gap-3 rounded-2xl border px-4 py-2.5 text-left transition",
                actif
                  ? "border-emerald-700 bg-emerald-50 ring-2 ring-emerald-700/20"
                  : "border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50",
              )}
            >
              <span
                aria-hidden="true"
                className={cn(
                  "grid h-5 w-5 shrink-0 place-items-center rounded-full border-2",
                  actif ? "border-emerald-700" : "border-slate-300",
                )}
              >
                {actif ? <span className="h-2.5 w-2.5 rounded-full bg-emerald-700" /> : null}
              </span>
              <span className="min-w-0">
                <span className="block text-sm font-semibold text-slate-900">{option.libelle}</span>
                {option.aide ? <span className="block text-xs text-slate-500">{option.aide}</span> : null}
              </span>
            </button>
          );
        })}
      </div>
      {erreur ? (
        <p role="alert" className="mt-1.5 text-xs font-medium text-rose-600">
          {erreur}
        </p>
      ) : aide ? (
        <p className="mt-1.5 text-xs text-slate-500">{aide}</p>
      ) : null}
    </fieldset>
  );
}

function CaseAccord({
  coche,
  onChange,
  erreur,
  children,
}: {
  coche: boolean;
  onChange: (coche: boolean) => void;
  erreur?: string;
  children: ReactNode;
}) {
  return (
    <div>
      <label className="flex cursor-pointer items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <input
          type="checkbox"
          checked={coche}
          onChange={(e) => onChange(e.target.checked)}
          aria-invalid={erreur ? true : undefined}
          className="mt-0.5 h-5 w-5 shrink-0 rounded border-amber-300 accent-emerald-700"
        />
        <span>{children}</span>
      </label>
      {erreur ? (
        <p role="alert" className="mt-1.5 text-xs font-medium text-rose-600">
          {erreur}
        </p>
      ) : null}
    </div>
  );
}

function Recapitulatif({
  chargement,
  apercu,
  erreur,
  onReessayer,
  onUtiliserDelai,
}: {
  chargement: boolean;
  apercu: ProfilePreview | null;
  erreur: string | null;
  onReessayer: () => void;
  onUtiliserDelai: (jours: number) => void;
}) {
  if (erreur) {
    return (
      <Banner
        tone="error"
        action={
          <Button variant="secondary" size="sm" onClick={onReessayer}>
            Réessayer
          </Button>
        }
      >
        {erreur}
      </Banner>
    );
  }
  if (chargement || !apercu) return <SkeletonCard lines={4} />;

  const { besoins } = apercu;
  const delaiConseille = besoins.delai_conseille_jours ?? null;

  return (
    <div className="space-y-4" aria-live="polite">
      <div className="rounded-2xl bg-emerald-700 px-5 py-4 text-white">
        <p className="text-xs uppercase tracking-[0.12em] text-emerald-100">Calories par jour</p>
        <p className="mt-1 text-3xl font-semibold">{formatKcal(besoins.calories_recommandees)}</p>
        <p className="mt-1 text-xs text-emerald-100">
          Dépense estimée {formatKcal(besoins.tdee)} · plancher de sécurité {formatKcal(besoins.plancher_kcal)}
        </p>
      </div>

      <div className="grid grid-cols-3 gap-2 sm:gap-3">
        <Macro libelle="Protéines" grammes={besoins.proteines_g} />
        <Macro libelle="Glucides" grammes={besoins.glucides_g} />
        <Macro libelle="Lipides" grammes={besoins.lipides_g} />
      </div>

      {besoins.variation_hebdo_kg !== 0 ? (
        <p className="text-sm text-slate-600">
          Rythme prévu : {besoins.variation_hebdo_kg > 0 ? "+" : ""}
          {formatNumber(besoins.variation_hebdo_kg, 2)} kg par semaine
          {besoins.jours_restants != null ? `, sur ${besoins.jours_restants} jours` : ""}.
        </p>
      ) : null}

      {besoins.avertissements.length > 0 ? (
        <div className="space-y-2">
          {besoins.avertissements.map((avertissement) => (
            <Banner key={avertissement} tone="warning">
              {avertissement}
            </Banner>
          ))}
          {delaiConseille !== null ? (
            <Button variant="secondary" onClick={() => onUtiliserDelai(delaiConseille)}>
              Utiliser le délai conseillé ({delaiConseille} jours)
            </Button>
          ) : null}
        </div>
      ) : null}

      {besoins.rythme_intense_possible ? (
        <p className="text-xs leading-5 text-slate-500">
          Un rythme de perte plus rapide est possible avec ton accord explicite : il se choisit depuis
          ton profil, après lecture de ses risques.
        </p>
      ) : null}

      <p className="text-xs leading-5 text-slate-500">{besoins.mention}</p>
    </div>
  );
}

/** Tuile de macro compacte : trois tiennent côte à côte sur un écran de 375 px. */
function Macro({ libelle, grammes }: { libelle: string; grammes: number }) {
  return (
    <div className="min-w-0 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2.5">
      <p className="truncate text-[11px] font-medium uppercase tracking-[0.08em] text-slate-500">{libelle}</p>
      <p className="mt-0.5 text-lg font-semibold text-slate-900">{formatNumber(grammes, 0)} g</p>
    </div>
  );
}
