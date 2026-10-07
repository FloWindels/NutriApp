import type { PillTone } from "@/components/ui/pill";
import type {
  ExercisePrescriptionFields,
  PrescriptionCible,
  PrescriptionRange,
  SeanceEtape,
} from "@/lib/types/api";

/**
 * Lecture de la prescription structurée (docs/SPORT_V2.md §3) : pastilles de cibles, phrase
 * lisible et étapes minutées. Le serveur calcule `resume` et `etapes` ; les replis ci-dessous ne
 * servent qu’aux séances enregistrées avant la refonte (jamais « 10x30 1min52 »).
 */

type Seriable = ExercisePrescriptionFields & {
  sets?: number | null;
  reps?: number | null;
  duration_sec?: number | null;
  rest_sec?: number | null;
  distance_km?: number | null;
};

const TIRET = "–";

function bornes(range: PrescriptionRange | null | undefined): [number, number] | null {
  if (range === null || range === undefined) return null;
  if (typeof range === "number") return Number.isFinite(range) ? [range, range] : null;
  // Le serveur peut envoyer [min, max] ou une seule valeur dans un tableau.
  const valeurs: unknown[] = Array.isArray(range) ? range : [];
  if (valeurs.length === 0) return null;
  const a = valeurs[0];
  const b = valeurs.length > 1 ? valeurs[1] : a;
  if (typeof a !== "number" || !Number.isFinite(a)) return null;
  return [a, typeof b === "number" && Number.isFinite(b) ? b : a];
}

function nombre(value: number): string {
  return String(Math.round(value * 10) / 10).replace(".", ",");
}

/** « 100–110 » ou « 7 » quand les deux bornes sont égales. */
export function formatRange(
  range: PrescriptionRange | null | undefined,
  format: (value: number) => string = nombre,
): string | null {
  const b = bornes(range);
  if (!b) return null;
  return b[0] === b[1] ? format(b[0]) : `${format(b[0])}${TIRET}${format(b[1])}`;
}

/** 5.5 min/km → « 5:30 ». */
export function formatAllure(minutes: number): string {
  const total = Math.round(minutes * 60);
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, "0")}`;
}

/** 112 s → « 1:52 ». */
export function formatMinSec(seconds: number): string {
  const total = Math.max(0, Math.round(seconds));
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, "0")}`;
}

/** 30 → « 30 s », 90 → « 1 min 30 », 480 → « 8 min », 3900 → « 1 h 05 ». */
export function formatDuree(seconds: number | null | undefined): string {
  if (!seconds || seconds <= 0) return "";
  const s = Math.round(seconds);
  if (s < 60) return `${s} s`;
  if (s >= 3600) {
    const h = Math.floor(s / 3600);
    const m = Math.round((s % 3600) / 60);
    return m ? `${h} h ${String(m).padStart(2, "0")}` : `${h} h`;
  }
  const m = Math.floor(s / 60);
  const r = s % 60;
  return r ? `${m} min ${String(r).padStart(2, "0")}` : `${m} min`;
}

export type CibleChip = { key: string; label: string; tone: PillTone; title?: string };

const ZONE_TONES: Record<number, PillTone> = { 1: "sky", 2: "emerald", 3: "amber", 4: "orange", 5: "rose" };

/** Les cibles d’une prescription en pastilles, dans un ordre stable et lisible. */
export function cibleChips(cible: PrescriptionCible | null | undefined): CibleChip[] {
  if (!cible) return [];
  const chips: CibleChip[] = [];
  const push = (key: string, label: string | null, tone: PillTone, title?: string) => {
    if (label) chips.push({ key, label, tone, title });
  };

  const rpm = formatRange(cible.cadence_rpm);
  push("rpm", rpm && `${rpm} tr/min`, "sky", "Cadence de pédalage");
  const resistance = formatRange(cible.resistance);
  push("resistance", resistance && `Résistance ${resistance}/10`, "violet");
  const watts = formatRange(cible.puissance_w);
  push("watts", watts && `${watts} W`, "violet", "Puissance");
  const vitesse = formatRange(cible.vitesse_kmh);
  push("vitesse", vitesse && `${vitesse} km/h`, "teal", "Vitesse");
  const allure = formatRange(cible.allure_min_km, formatAllure);
  push("allure", allure && `${allure} /km`, "teal", "Allure");
  const pente = formatRange(cible.inclinaison_pct);
  push("pente", pente && `Pente ${pente} %`, "teal", "Inclinaison");
  const coups = formatRange(cible.cadence_coups_min);
  push("coups", coups && `${coups} coups/min`, "sky", "Cadence de rame");
  const cinqCents = formatRange(cible.allure_500m_sec, formatMinSec);
  push("500m", cinqCents && `${cinqCents} /500 m`, "teal", "Temps au 500 m");

  const zone = typeof cible.zone_fc === "number" ? cible.zone_fc : null;
  const bpm = formatRange(cible.fc_bpm);
  if (zone) {
    push("zone", `Zone ${zone}${bpm ? ` · ${bpm} bpm` : ""}`, ZONE_TONES[zone] ?? "slate", "Zone de fréquence cardiaque");
  } else if (bpm) {
    push("bpm", `${bpm} bpm`, "rose", "Fréquence cardiaque");
  }
  const rpe = formatRange(cible.rpe);
  push("rpe", rpe && `Ressenti ${rpe}/10`, "amber", "RPE : effort ressenti de 1 à 10");
  return chips;
}

/** Phrase lisible construite côté client pour une séance sans `resume`. */
export function resumeSeries(row: Seriable): string {
  const parts: string[] = [];
  const sets = row.sets ?? null;
  const reps = row.reps ?? null;
  const duree = row.duration_sec ?? null;
  const pluriel = (n: number, mot: string) => `${n} ${mot}${n > 1 ? "s" : ""}`;

  if (sets && sets > 1) {
    if (reps) parts.push(`${pluriel(sets, "série")} de ${pluriel(reps, "répétition")}`);
    else if (duree) parts.push(`${pluriel(sets, "série")} de ${formatDuree(duree)}`);
    else parts.push(pluriel(sets, "série"));
  } else if (reps) {
    parts.push(pluriel(reps, "répétition"));
  } else if (duree) {
    parts.push(formatDuree(duree));
  }
  if (row.distance_km) parts.push(`${String(row.distance_km).replace(".", ",")} km`);
  if (row.rest_sec && (sets ?? 1) > 1) parts.push(`repos ${formatDuree(row.rest_sec)}`);
  return parts.join(" · ");
}

/** `resume` du serveur, sinon la phrase reconstruite. */
export function exerciseResume(row: Seriable): string {
  const fromServer = row.resume?.trim();
  return fromServer || resumeSeries(row);
}

/** Étapes d’un exercice : celles du serveur, sinon déduites des séries / durée / repos. */
export function exerciseEtapes(row: Seriable): SeanceEtape[] {
  if (row.etapes && row.etapes.length > 0) return row.etapes;

  const sets = Math.max(1, Math.min(row.sets ?? 1, 30));
  const duree = row.duration_sec && row.duration_sec > 0 ? row.duration_sec : null;
  const cible = row.reps ? `${row.reps} répétitions` : null;
  const etapes: SeanceEtape[] = [];
  for (let i = 1; i <= sets; i += 1) {
    etapes.push({
      label: sets > 1 ? `Série ${i}/${sets}` : duree ? "Effort" : "À toi de jouer",
      type: duree ? "effort" : "travail",
      duree_sec: duree,
      cible,
    });
    if (i < sets && row.rest_sec && row.rest_sec > 0) {
      etapes.push({ label: "Repos", type: "repos", duree_sec: row.rest_sec, cible: null });
    }
  }
  return etapes;
}
