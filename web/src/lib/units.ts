import type { EstimateConfidence, Portion, Unit } from "@/lib/types/api";

/**
 * Client-side mirror of `App\Support\Portions` (brief §3.3): used for the live
 * preview in the quantity picker before the server computes the real snapshot.
 * The server stays the source of truth — `GET /portions` overrides this table.
 */

export const UNITS: Unit[] = [
  "g",
  "ml",
  "piece",
  "portion",
  "cas",
  "cac",
  "verre",
  "bol",
  "assiette",
  "poignee",
  "tranche",
];

export const PORTIONS_FALLBACK: Portion[] = [
  { unit: "g", label: "gramme", label_short: "g", grams: 1, step: 10, is_estimate: false },
  { unit: "ml", label: "millilitre", label_short: "ml", grams: 1, step: 10, is_estimate: true },
  { unit: "piece", label: "pièce", label_short: "pièce", grams: null, step: 0.5, is_estimate: true },
  { unit: "portion", label: "portion", label_short: "portion", grams: null, step: 0.5, is_estimate: true },
  { unit: "cas", label: "cuillère à soupe", label_short: "c. à s.", grams: 15, step: 1, is_estimate: true },
  { unit: "cac", label: "cuillère à café", label_short: "c. à c.", grams: 5, step: 1, is_estimate: true },
  { unit: "verre", label: "verre", label_short: "verre", grams: 200, step: 1, is_estimate: true },
  { unit: "bol", label: "bol", label_short: "bol", grams: 300, step: 1, is_estimate: true },
  { unit: "assiette", label: "assiette", label_short: "assiette", grams: 350, step: 1, is_estimate: true },
  { unit: "poignee", label: "poignée", label_short: "poignée", grams: 30, step: 1, is_estimate: true },
  { unit: "tranche", label: "tranche", label_short: "tranche", grams: 30, step: 0.5, is_estimate: true },
];

/** Aliases normalised before lookup (mirror of the server table). */
export const UNIT_ALIASES: Record<string, { unit: Unit; factor: number }> = {
  g: { unit: "g", factor: 1 },
  gr: { unit: "g", factor: 1 },
  gramme: { unit: "g", factor: 1 },
  grammes: { unit: "g", factor: 1 },
  kg: { unit: "g", factor: 1000 },
  ml: { unit: "ml", factor: 1 },
  cl: { unit: "ml", factor: 10 },
  l: { unit: "ml", factor: 1000 },
  litre: { unit: "ml", factor: 1000 },
  unite: { unit: "piece", factor: 1 },
  "unité": { unit: "piece", factor: 1 },
  "pièce": { unit: "piece", factor: 1 },
  piece: { unit: "piece", factor: 1 },
  pc: { unit: "piece", factor: 1 },
  pcs: { unit: "piece", factor: 1 },
  portion: { unit: "portion", factor: 1 },
  portions: { unit: "portion", factor: 1 },
  cas: { unit: "cas", factor: 1 },
  cs: { unit: "cas", factor: 1 },
  "c. à s.": { unit: "cas", factor: 1 },
  cuillere_soupe: { unit: "cas", factor: 1 },
  "cuillère à soupe": { unit: "cas", factor: 1 },
  cac: { unit: "cac", factor: 1 },
  cc: { unit: "cac", factor: 1 },
  "c. à c.": { unit: "cac", factor: 1 },
  cuillere_cafe: { unit: "cac", factor: 1 },
  "cuillère à café": { unit: "cac", factor: 1 },
  verre: { unit: "verre", factor: 1 },
  bol: { unit: "bol", factor: 1 },
  assiette: { unit: "assiette", factor: 1 },
  poignee: { unit: "poignee", factor: 1 },
  "poignée": { unit: "poignee", factor: 1 },
  tranche: { unit: "tranche", factor: 1 },
  tranches: { unit: "tranche", factor: 1 },
};

/** Default grams per piece/portion by food category (mirror of `config/portion_defaults.php`). */
export const PORTION_DEFAULTS_BY_CATEGORY: Record<string, number> = {
  oeuf: 55,
  fruit: 150,
  legume: 120,
  yaourt: 125,
  biscuit: 10,
  pain: 30,
  jambon: 40,
  viande: 125,
  poisson: 130,
  fromage: 30,
};

export type FoodLike = {
  serving_size_g?: number | null;
  density_g_per_ml?: number | null;
  category?: string | null;
  per_unit?: "100g" | "100ml" | null;
};

export type Conversion = {
  grams: number;
  is_estimate: boolean;
  confidence: EstimateConfidence;
  note: string | null;
};

/** « Unité », « c. à s. »… → canonical unit + multiplier (cl → ml ×10). */
export function normalizeUnit(raw: string | null | undefined): { unit: Unit; factor: number } {
  const key = (raw ?? "").trim().toLowerCase();
  if (!key) return { unit: "g", factor: 1 };
  const alias = UNIT_ALIASES[key];
  if (alias) return alias;
  if ((UNITS as string[]).includes(key)) return { unit: key as Unit, factor: 1 };
  return { unit: "g", factor: 1 };
}

export function portionFor(unit: Unit, portions: Portion[] = PORTIONS_FALLBACK): Portion {
  return portions.find((p) => p.unit === unit) ?? PORTIONS_FALLBACK.find((p) => p.unit === unit)!;
}

export function unitLabel(unit: string, portions: Portion[] = PORTIONS_FALLBACK, short = true): string {
  const { unit: canonical } = normalizeUnit(unit);
  const portion = portionFor(canonical, portions);
  return short ? portion.label_short : portion.label;
}

export function unitStep(unit: string, portions: Portion[] = PORTIONS_FALLBACK): number {
  return portionFor(normalizeUnit(unit).unit, portions).step;
}

function categoryDefault(category: string | null | undefined): number | null {
  if (!category) return null;
  const key = category.toLowerCase();
  for (const [name, grams] of Object.entries(PORTION_DEFAULTS_BY_CATEGORY)) {
    if (key.includes(name)) return grams;
  }
  return null;
}

/** Client preview of `Portions::toGrams`. */
export function toGrams(
  quantity: number,
  rawUnit: string,
  food?: FoodLike | null,
  portions: Portion[] = PORTIONS_FALLBACK,
): Conversion {
  const { unit, factor } = normalizeUnit(rawUnit);
  const qty = quantity * factor;

  if (unit === "g") {
    return { grams: qty, is_estimate: false, confidence: "haute", note: null };
  }

  if (unit === "ml") {
    const density = food?.density_g_per_ml ?? null;
    return {
      grams: qty * (density ?? 1),
      is_estimate: density === null,
      confidence: density === null ? "moyenne" : "haute",
      note: density === null ? "Densité inconnue : 1 ml ≈ 1 g." : null,
    };
  }

  if (unit === "piece" || unit === "portion") {
    if (food?.serving_size_g) {
      return {
        grams: qty * food.serving_size_g,
        is_estimate: true,
        confidence: "moyenne",
        note: `1 ${unit === "piece" ? "pièce" : "portion"} ≈ ${food.serving_size_g} g.`,
      };
    }
    const fallback = categoryDefault(food?.category);
    if (fallback !== null) {
      return {
        grams: qty * fallback,
        is_estimate: true,
        confidence: "moyenne",
        note: `Portion moyenne pour cette catégorie : ${fallback} g.`,
      };
    }
    return {
      grams: qty * 100,
      is_estimate: true,
      confidence: "faible",
      note: "Taille de portion inconnue : 100 g par défaut.",
    };
  }

  const portion = portionFor(unit, portions);
  return {
    grams: qty * (portion.grams ?? 100),
    is_estimate: true,
    confidence: "moyenne",
    note: `1 ${portion.label} ≈ ${portion.grams ?? 100} g.`,
  };
}

export type Per100 = { calories: number; proteins: number; carbs: number; fat: number };

export type NutritionPreview = Per100 & { grams: number; is_estimate: boolean };

/** Scales per-100 g macros to the converted grams. */
export function previewNutrition(
  quantity: number,
  unit: string,
  per100: Per100,
  food?: FoodLike | null,
  portions?: Portion[],
): NutritionPreview {
  const conversion = toGrams(quantity, unit, food, portions);
  const ratio = conversion.grams / 100;
  return {
    grams: conversion.grams,
    is_estimate: conversion.is_estimate,
    calories: per100.calories * ratio,
    proteins: per100.proteins * ratio,
    carbs: per100.carbs * ratio,
    fat: per100.fat * ratio,
  };
}

/** Units offered for a food in the picker (first four are visible, the rest behind « Plus… »). */
export function unitsForFood(food?: FoodLike | null): Unit[] {
  const base: Unit[] = ["g"];
  if (food?.serving_size_g) base.push("portion");
  base.push("piece", "tranche", "cas", "cac", "verre", "bol", "assiette", "poignee", "ml");
  return Array.from(new Set(base));
}
