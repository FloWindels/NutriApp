"use client";

import { useEffect, useMemo, useState } from "react";
import { getApiErrorMessage } from "@/lib/api";

type ProfileForm = {
  nom: string;
  sexe: string;
  age: string;
  tailleCm: string;
  poidsActuelKg: string;
  poidsSouhaiteKg: string;
  delaiJours: string;
  niveauActivite: string;
  objectifType: string;
  regimeAlimentaire: string;
};

type CalculationResult = {
  bmr: number;
  maintenanceCalories: number;
  targetCalories: number;
  proteinsGrams: number;
  fatsGrams: number;
  carbsGrams: number;
  adjustmentPerDay: number;
  weeklyVariationKg: number;
  variationKg: number;
  floorCalories: number;
  warning: string | null;
  summary: string;
  explanation: string;
};

type ValidationError = {
  field: keyof ProfileForm;
  message: string;
};

const initialForm: ProfileForm = {
  nom: "",
  sexe: "",
  age: "",
  tailleCm: "",
  poidsActuelKg: "",
  poidsSouhaiteKg: "",
  delaiJours: "",
  niveauActivite: "",
  objectifType: "",
  regimeAlimentaire: "",
};

const activityFactors: Record<string, number> = {
  sedentaire: 1.2,
  leger: 1.375,
  modere: 1.55,
  eleve: 1.725,
  tres_eleve: 1.9,
};

function toNumber(value: string) {
  const parsed = Number(value);
  return Number.isNaN(parsed) ? null : parsed;
}

function validateForm(form: ProfileForm): ValidationError[] {
  const errors: ValidationError[] = [];

  if (!form.nom.trim()) {
    errors.push({ field: "nom", message: "Le nom est obligatoire." });
  }

  if (!form.sexe) {
    errors.push({ field: "sexe", message: "Le sexe est obligatoire." });
  }

  const age = toNumber(form.age);
  if (!age || age < 12 || age > 120) {
    errors.push({ field: "age", message: "L'age doit etre compris entre 12 et 120 ans." });
  }

  const taille = toNumber(form.tailleCm);
  if (!taille || taille < 100 || taille > 250) {
    errors.push({ field: "tailleCm", message: "La taille doit etre comprise entre 100 et 250 cm." });
  }

  const poidsActuel = toNumber(form.poidsActuelKg);
  if (!poidsActuel || poidsActuel < 20 || poidsActuel > 500) {
    errors.push({ field: "poidsActuelKg", message: "Le poids actuel doit etre compris entre 20 et 500 kg." });
  }

  const poidsSouhaite = toNumber(form.poidsSouhaiteKg);
  if (!poidsSouhaite || poidsSouhaite < 20 || poidsSouhaite > 500) {
    errors.push({ field: "poidsSouhaiteKg", message: "Le poids souhaite doit etre compris entre 20 et 500 kg." });
  }

  const delai = toNumber(form.delaiJours);
  if (!delai || delai < 1 || delai > 2000) {
    errors.push({ field: "delaiJours", message: "Le nombre de jours doit etre compris entre 1 et 2000." });
  }

  if (!form.niveauActivite || !(form.niveauActivite in activityFactors)) {
    errors.push({ field: "niveauActivite", message: "Le niveau d'activite est obligatoire." });
  }

  if (!form.objectifType || !["perdre", "maintenir", "prendre"].includes(form.objectifType)) {
    errors.push({ field: "objectifType", message: "L'objectif est obligatoire." });
  }

  if (!form.regimeAlimentaire) {
    errors.push({ field: "regimeAlimentaire", message: "Le regime alimentaire est obligatoire." });
  }

  return errors;
}

function calculateResult(form: ProfileForm): CalculationResult {
  const age = Number(form.age);
  const taille = Number(form.tailleCm);
  const poidsActuel = Number(form.poidsActuelKg);
  const poidsSouhaite = Number(form.poidsSouhaiteKg);
  const delaiJours = Number(form.delaiJours);

  const bmr =
    form.sexe === "homme"
      ? 10 * poidsActuel + 6.25 * taille - 5 * age + 5
      : 10 * poidsActuel + 6.25 * taille - 5 * age - 161;

  const maintenanceCalories = bmr * activityFactors[form.niveauActivite];
  const variationKg = poidsSouhaite - poidsActuel;
  const adjustmentPerDay = (variationKg * 7700) / delaiJours;

  const floorCalories = form.sexe === "homme" ? 1400 : 1200;
  const maxDailyChange = 1100;

  let warning: string | null = null;

  const adjustmentClamped = Math.max(-maxDailyChange, Math.min(adjustmentPerDay, maxDailyChange));
  if (adjustmentPerDay !== adjustmentClamped) {
    warning = "Objectif tres agressif: l'ajustement calorique a ete limite pour proteger ta sante.";
  }

  const weeklyVariationKg = (variationKg / delaiJours) * 7;
  if (!warning && Math.abs(weeklyVariationKg) > 1) {
    warning = "Objectif potentiellement irrealiste: la variation hebdomadaire depasse 1 kg/semaine.";
  }

  let targetCalories = maintenanceCalories + adjustmentClamped;
  if (targetCalories < floorCalories) {
    targetCalories = floorCalories;
    warning = "Les calories cibles ont ete remontees au seuil de securite minimal.";
  }

  const proteinsGrams = Math.round(poidsActuel * 1.6);
  const fatsGrams = Math.round((targetCalories * 0.28) / 9);
  const carbsGrams = Math.max(Math.round((targetCalories - proteinsGrams * 4 - fatsGrams * 9) / 4), 0);

  const summary =
    form.objectifType === "maintenir"
      ? "Objectif maintien: stabiliser ton poids actuel."
      : form.objectifType === "perdre"
        ? `Objectif perte: passer de ${poidsActuel.toFixed(1)} kg a ${poidsSouhaite.toFixed(1)} kg en ${delaiJours} jours.`
        : `Objectif prise: passer de ${poidsActuel.toFixed(1)} kg a ${poidsSouhaite.toFixed(1)} kg en ${delaiJours} jours.`;

  const explanation =
    "Le calcul combine ton metabolisme de base, ton activite physique et ton delai cible. Les macros sont ensuite reparties automatiquement: proteines fixes, lipides a 28% des calories, glucides sur le reste.";

  return {
    bmr: Math.round(bmr),
    maintenanceCalories: Math.round(maintenanceCalories),
    targetCalories: Math.round(targetCalories),
    proteinsGrams,
    fatsGrams,
    carbsGrams,
    adjustmentPerDay: Math.round(adjustmentClamped),
    weeklyVariationKg: Number(weeklyVariationKg.toFixed(2)),
    variationKg: Number(variationKg.toFixed(2)),
    floorCalories,
    warning,
    summary,
    explanation,
  };
}

export default function ProfilPage() {
  const [form, setForm] = useState<ProfileForm>(initialForm);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(true);
  const [calculation, setCalculation] = useState<CalculationResult | null>(null);
  const [validationErrors, setValidationErrors] = useState<ValidationError[]>([]);
  const [errorMessage, setErrorMessage] = useState("");
  const [successMessage, setSuccessMessage] = useState("");

  const weeklyGaugePercent = useMemo(() => {
    if (!calculation) {
      return 0;
    }

    const value = Math.min(Math.abs(calculation.weeklyVariationKg), 1.2);
    return Math.round((value / 1.2) * 100);
  }, [calculation]);

  useEffect(() => {
    let isMounted = true;

    async function loadProfile() {
      const token = localStorage.getItem("token");
      if (!token) {
        setLoading(false);
        return;
      }

      try {
        const response = await fetch("/api/profile", {
          headers: {
            Accept: "application/json",
            Authorization: `Bearer ${token}`,
          },
        });

        if (!response.ok) {
          throw new Error("Profil non charge");
        }

        const data = await response.json();
        if (!isMounted) {
          return;
        }

        setForm({
          nom: data.nom ?? "",
          sexe: data.sexe ?? "",
          age: data.age != null ? String(data.age) : "",
          tailleCm: data.taille != null ? String(data.taille) : "",
          poidsActuelKg: data.poids != null ? String(data.poids) : "",
          poidsSouhaiteKg: data.poids_souhaite_kg != null ? String(data.poids_souhaite_kg) : "",
          delaiJours: data.delai_objectif_jours != null ? String(data.delai_objectif_jours) : "",
          niveauActivite: data.niveau_activite ?? "",
          objectifType: data.objectif_type ?? "",
          regimeAlimentaire: data.regime_alimentaire ?? "",
        });

        if (
          data.sexe &&
          data.age != null &&
          data.taille != null &&
          data.poids != null &&
          data.poids_souhaite_kg != null &&
          data.delai_objectif_jours != null &&
          data.niveau_activite &&
          data.objectif_type &&
          data.regime_alimentaire
        ) {
          setCalculation(
            calculateResult({
              nom: data.nom ?? "",
              sexe: data.sexe,
              age: String(data.age),
              tailleCm: String(data.taille),
              poidsActuelKg: String(data.poids),
              poidsSouhaiteKg: String(data.poids_souhaite_kg),
              delaiJours: String(data.delai_objectif_jours),
              niveauActivite: data.niveau_activite,
              objectifType: data.objectif_type,
              regimeAlimentaire: data.regime_alimentaire,
            })
          );
        }
      } catch {
        if (isMounted) {
          setErrorMessage("Impossible de charger le profil enregistre.");
        }
      } finally {
        if (isMounted) {
          setLoading(false);
        }
      }
    }

    loadProfile();

    return () => {
      isMounted = false;
    };
  }, []);

  function setField(field: keyof ProfileForm, value: string) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  function getFieldError(field: keyof ProfileForm) {
    return validationErrors.find((error) => error.field === field)?.message ?? "";
  }

  function handleCalculate() {
    setErrorMessage("");
    setSuccessMessage("");

    const errors = validateForm(form);
    setValidationErrors(errors);

    if (errors.length > 0) {
      setCalculation(null);
      return;
    }

    setCalculation(calculateResult(form));
  }

  function handleReset() {
    setForm(initialForm);
    setValidationErrors([]);
    setCalculation(null);
    setErrorMessage("");
    setSuccessMessage("");
  }

  async function handleSave() {
    setErrorMessage("");
    setSuccessMessage("");

    const errors = validateForm(form);
    setValidationErrors(errors);

    if (errors.length > 0) {
      return;
    }

    const computed = calculation ?? calculateResult(form);
    const token = localStorage.getItem("token");

    if (!token) {
      setErrorMessage("Session invalide. Reconnecte-toi.");
      return;
    }

    setSaving(true);

    try {
      const body = {
        nom: form.nom,
        sexe: form.sexe,
        age: Number(form.age),
        taille: Number(form.tailleCm),
        poids: Number(form.poidsActuelKg),
        poids_souhaite_kg: Number(form.poidsSouhaiteKg),
        delai_objectif_jours: Number(form.delaiJours),
        niveau_activite: form.niveauActivite,
        objectif_type: form.objectifType,
        objectif: form.objectifType,
        regime_alimentaire: form.regimeAlimentaire,
        calories_cibles: computed.targetCalories,
        proteines_cibles: computed.proteinsGrams,
        glucides_cibles: computed.carbsGrams,
        lipides_cibles: computed.fatsGrams,
      };

      const response = await fetch("/api/profile", {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify(body),
      });

      const payload = await response.json();
      if (!response.ok) {
        throw { response: { data: payload } };
      }

      setCalculation(computed);
      setSuccessMessage("Profil et calcul nutritionnel enregistres avec succes.");
    } catch (error: unknown) {
      setErrorMessage(getApiErrorMessage(error, "Impossible d'enregistrer le profil."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <section className="mx-auto max-w-6xl rounded-[1.75rem] border border-emerald-100 bg-white p-5 shadow-[0_20px_50px_rgba(15,23,42,0.08)] sm:p-7">
        <div className="mb-6">
          <h1 className="text-2xl font-semibold tracking-tight text-slate-950">Calculateur nutritionnel</h1>
          <p className="mt-2 text-sm text-slate-600">
            Renseigne ton profil, calcule tes cibles, puis sauvegarde les resultats.
          </p>
          {loading ? <p className="mt-2 text-xs text-slate-500">Chargement du profil...</p> : null}
        </div>

        <div className="grid gap-4 lg:grid-cols-[1.1fr_0.9fr]">
          <div className="space-y-4 rounded-[1.5rem] border border-emerald-100 bg-emerald-50/50 p-5">
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Nom</label>
                <input value={form.nom} onChange={(e) => setField("nom", e.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5" />
                {getFieldError("nom") ? <p className="mt-1 text-xs text-rose-600">{getFieldError("nom")}</p> : null}
              </div>

              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Sexe</label>
                <select value={form.sexe} onChange={(e) => setField("sexe", e.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5">
                  <option value="">Selectionner</option>
                  <option value="homme">Homme</option>
                  <option value="femme">Femme</option>
                </select>
                {getFieldError("sexe") ? <p className="mt-1 text-xs text-rose-600">{getFieldError("sexe")}</p> : null}
              </div>

              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Age</label>
                <input type="number" value={form.age} onChange={(e) => setField("age", e.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5" />
                {getFieldError("age") ? <p className="mt-1 text-xs text-rose-600">{getFieldError("age")}</p> : null}
              </div>

              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Taille (cm)</label>
                <input type="number" value={form.tailleCm} onChange={(e) => setField("tailleCm", e.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5" />
                {getFieldError("tailleCm") ? <p className="mt-1 text-xs text-rose-600">{getFieldError("tailleCm")}</p> : null}
              </div>

              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Poids actuel (kg)</label>
                <input type="number" step="0.1" value={form.poidsActuelKg} onChange={(e) => setField("poidsActuelKg", e.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5" />
                {getFieldError("poidsActuelKg") ? <p className="mt-1 text-xs text-rose-600">{getFieldError("poidsActuelKg")}</p> : null}
              </div>

              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Poids souhaite (kg)</label>
                <input type="number" step="0.1" value={form.poidsSouhaiteKg} onChange={(e) => setField("poidsSouhaiteKg", e.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5" />
                {getFieldError("poidsSouhaiteKg") ? <p className="mt-1 text-xs text-rose-600">{getFieldError("poidsSouhaiteKg")}</p> : null}
              </div>

              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Nombre de jours</label>
                <input type="number" value={form.delaiJours} onChange={(e) => setField("delaiJours", e.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5" />
                {getFieldError("delaiJours") ? <p className="mt-1 text-xs text-rose-600">{getFieldError("delaiJours")}</p> : null}
              </div>

              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Niveau d’activité</label>
                <select value={form.niveauActivite} onChange={(e) => setField("niveauActivite", e.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5">
                  <option value="">Selectionner</option>
                  <option value="sedentaire">Sedentaire</option>
                  <option value="leger">Leger</option>
                  <option value="modere">Modere</option>
                  <option value="eleve">Eleve</option>
                  <option value="tres_eleve">Tres eleve</option>
                </select>
                {getFieldError("niveauActivite") ? <p className="mt-1 text-xs text-rose-600">{getFieldError("niveauActivite")}</p> : null}
              </div>

              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Objectif</label>
                <select value={form.objectifType} onChange={(e) => setField("objectifType", e.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5">
                  <option value="">Selectionner</option>
                  <option value="perdre">Perdre du poids</option>
                  <option value="maintenir">Maintenir</option>
                  <option value="prendre">Prendre du poids</option>
                </select>
                {getFieldError("objectifType") ? <p className="mt-1 text-xs text-rose-600">{getFieldError("objectifType")}</p> : null}
              </div>

              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Regime alimentaire</label>
                <select value={form.regimeAlimentaire} onChange={(e) => setField("regimeAlimentaire", e.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5">
                  <option value="">Selectionner</option>
                  <option value="omnivore">Omnivore</option>
                  <option value="vegetarien">Vegetarien</option>
                  <option value="vegan">Vegan</option>
                  <option value="keto">Keto</option>
                  <option value="low_carb">Low carb</option>
                  <option value="mediterraneen">Mediterraneen</option>
                  <option value="halal">Halal</option>
                  <option value="sans_gluten">Sans gluten</option>
                  <option value="autre">Autre</option>
                </select>
                {getFieldError("regimeAlimentaire") ? <p className="mt-1 text-xs text-rose-600">{getFieldError("regimeAlimentaire")}</p> : null}
              </div>
            </div>

            <div className="flex flex-wrap gap-3">
              <button type="button" onClick={handleCalculate} className="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-800">
                Calculer
              </button>
              <button type="button" onClick={handleReset} className="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                Reinitialiser
              </button>
              <button type="button" onClick={handleSave} disabled={saving} className="rounded-xl border border-lime-300 bg-lime-100 px-5 py-2.5 text-sm font-medium text-lime-900 transition hover:bg-lime-200 disabled:opacity-60">
                {saving ? "Enregistrement..." : "Enregistrer"}
              </button>
            </div>

            {errorMessage ? <p className="text-sm text-rose-600">{errorMessage}</p> : null}
            {successMessage ? <p className="text-sm text-emerald-700">{successMessage}</p> : null}
          </div>

          <div className="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-[0_14px_40px_rgba(15,23,42,0.06)]">
            <p className="text-sm font-semibold text-slate-900">Resultat nutritionnel</p>

            {!calculation ? (
              <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                Clique sur « Calculer » pour obtenir ton plan.
              </div>
            ) : (
              <div className="mt-4 space-y-4">
                <div className="grid gap-3 sm:grid-cols-2">
                  <div className="rounded-2xl bg-emerald-50 px-4 py-3">
                    <p className="text-xs uppercase tracking-[0.12em] text-emerald-700">Metabolisme de base</p>
                    <p className="mt-1 text-2xl font-semibold text-emerald-900">{calculation.bmr} kcal</p>
                  </div>
                  <div className="rounded-2xl bg-lime-50 px-4 py-3">
                    <p className="text-xs uppercase tracking-[0.12em] text-lime-700">Calories maintien</p>
                    <p className="mt-1 text-2xl font-semibold text-lime-900">{calculation.maintenanceCalories} kcal</p>
                  </div>
                  <div className="rounded-2xl bg-slate-950 px-4 py-3 text-white sm:col-span-2">
                    <p className="text-xs uppercase tracking-[0.12em] text-slate-300">Calories cibles</p>
                    <p className="mt-1 text-3xl font-semibold">{calculation.targetCalories} kcal / jour</p>
                  </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                  <div className="rounded-2xl border border-slate-200 px-4 py-3">
                    <p className="text-xs text-slate-500">Proteines</p>
                    <p className="mt-1 text-xl font-semibold text-slate-900">{calculation.proteinsGrams} g</p>
                  </div>
                  <div className="rounded-2xl border border-slate-200 px-4 py-3">
                    <p className="text-xs text-slate-500">Lipides</p>
                    <p className="mt-1 text-xl font-semibold text-slate-900">{calculation.fatsGrams} g</p>
                  </div>
                  <div className="rounded-2xl border border-slate-200 px-4 py-3">
                    <p className="text-xs text-slate-500">Glucides</p>
                    <p className="mt-1 text-xl font-semibold text-slate-900">{calculation.carbsGrams} g</p>
                  </div>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                  <p className="text-xs uppercase tracking-[0.12em] text-slate-500">Jauge objectif</p>
                  <div className="mt-2 h-2.5 rounded-full bg-slate-200">
                    <div className="h-2.5 rounded-full bg-emerald-600" style={{ width: `${weeklyGaugePercent}%` }} />
                  </div>
                  <p className="mt-2 text-sm text-slate-700">Variation estimee: {calculation.weeklyVariationKg} kg / semaine</p>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                  <p className="text-sm font-semibold text-slate-900">Resume</p>
                  <p className="mt-1 text-sm text-slate-600">{calculation.summary}</p>
                  <p className="mt-2 text-sm text-slate-600">{calculation.explanation}</p>
                </div>

                {calculation.warning ? (
                  <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    {calculation.warning}
                  </div>
                ) : null}
              </div>
            )}
          </div>
        </div>

        <section className="mt-5 rounded-[1.5rem] border border-emerald-100 bg-emerald-50/50 px-5 py-4">
          <h2 className="text-sm font-semibold text-emerald-900">Section explicative</h2>
          <p className="mt-2 text-sm leading-6 text-emerald-900/90">
            Le calculateur utilise Mifflin-St Jeor pour estimer le metabolisme de base, applique ton niveau
            d’activité pour les calories de maintien, puis ajuste selon la variation de poids et le délai.
            Des limites de securite evitent les objectifs trop agressifs ou dangereux.
          </p>
        </section>
      </section>
    </>
  );
}
