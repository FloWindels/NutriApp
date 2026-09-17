"use client";

import { useEffect, useMemo, useState } from "react";
import { cn } from "@/lib/cn";
import { formatGrams, formatKcal, formatNumber, parseDecimal, roundToStep } from "@/lib/format";
import type { Portion, RecipePerServing, Unit } from "@/lib/types/api";
import {
  normalizeUnit,
  PORTIONS_FALLBACK,
  portionFor,
  previewNutrition,
  toGrams,
  unitsForFood,
  type FoodLike,
  type Per100,
} from "@/lib/units";
import { EstimatePill } from "./pill";

export type QuantityUnitValue = { quantity: number; unit: string };

export type PickerFood = FoodLike & Partial<Per100> & { name?: string };

export type QuantityUnitPickerProps = {
  /** `food`: unit chips + preview from per-100 g; `recipe`: portions only; `stock`: item unit first. */
  kind: "food" | "recipe" | "stock";
  value: QuantityUnitValue;
  onChange: (value: QuantityUnitValue) => void;
  /** Units table from `GET /portions` (falls back to the client table). */
  portions?: Portion[];
  /** Food (or stock item's food) for conversions and the live preview. */
  food?: PickerFood | null;
  /** Recipe per-serving macros. */
  perServing?: RecipePerServing | null;
  /** Stock: the item's own unit (shown first) and the available quantity. */
  stockUnit?: string;
  maxQuantity?: number | null;
  showPreview?: boolean;
  disabled?: boolean;
  className?: string;
};

const VISIBLE_UNITS = 4;
const QUICK_MASS = [50, 100, 150, 200];
const QUICK_PORTIONS = [0.5, 1, 2];

function toInputText(value: number): string {
  if (!Number.isFinite(value)) return "";
  return Number.isInteger(value) ? String(value) : String(Number(value.toFixed(2))).replace(".", ",");
}

/**
 * Web version of the mobile `QuantityUnitPicker` (brief §16.1): unit chips, −/+ stepper,
 * quick chips, live « ≈ 150 g · 210 kcal · P 12 g · G 30 g · L 4 g » preview and the
 * amber « estimation » pill when the unit is not g/ml.
 */
export function QuantityUnitPicker({
  kind,
  value,
  onChange,
  portions = PORTIONS_FALLBACK,
  food,
  perServing,
  stockUnit,
  maxQuantity,
  showPreview = true,
  disabled = false,
  className,
}: QuantityUnitPickerProps) {
  const [showAllUnits, setShowAllUnits] = useState(false);
  const [text, setText] = useState(() => toInputText(value.quantity));

  useEffect(() => {
    // Keep the field in sync when the parent changes the quantity (quick chips, ±).
    setText((current) => (parseDecimal(current) === value.quantity ? current : toInputText(value.quantity)));
  }, [value.quantity]);

  const units = useMemo<string[]>(() => {
    if (kind === "recipe") return ["portion"];
    const base: string[] = unitsForFood(food);
    if (kind === "stock" && stockUnit) {
      const canonical = normalizeUnit(stockUnit).unit;
      return [stockUnit, ...base.filter((u) => u !== canonical && u !== stockUnit)];
    }
    return base;
  }, [kind, food, stockUnit]);

  const canonicalUnit: Unit = normalizeUnit(value.unit).unit;
  const portion = portionFor(canonicalUnit, portions);
  const step = kind === "recipe" ? 0.5 : portion.step;
  const isMass = canonicalUnit === "g" || canonicalUnit === "ml";
  const isEstimateUnit = kind === "recipe" ? false : !isMass;
  const visibleUnits = showAllUnits ? units : units.slice(0, VISIBLE_UNITS);

  function commit(quantity: number, unit = value.unit) {
    let next = Math.max(0, quantity);
    if (maxQuantity !== null && maxQuantity !== undefined && normalizeUnit(unit).unit === normalizeUnit(stockUnit ?? unit).unit) {
      next = Math.min(next, maxQuantity);
    }
    next = Number(next.toFixed(2));
    onChange({ quantity: next, unit });
  }

  function handleText(raw: string) {
    setText(raw);
    const parsed = parseDecimal(raw);
    if (parsed !== null) commit(parsed);
  }

  function nudge(direction: -1 | 1) {
    const base = Number.isFinite(value.quantity) ? value.quantity : 0;
    const next = roundToStep(base + direction * step, step);
    commit(next < 0 ? 0 : next);
    setText(toInputText(next < 0 ? 0 : next));
  }

  function selectUnit(unit: string) {
    // Reasonable default quantity when switching between mass and countable units.
    const nextCanonical = normalizeUnit(unit).unit;
    const nextIsMass = nextCanonical === "g" || nextCanonical === "ml";
    let quantity = value.quantity;
    if (nextIsMass && !isMass) quantity = 100;
    if (!nextIsMass && isMass) quantity = 1;
    commit(quantity, unit);
    setText(toInputText(quantity));
  }

  const preview = useMemo(() => {
    if (!showPreview) return null;
    if (kind === "recipe") {
      if (!perServing) return null;
      const factor = value.quantity;
      return {
        grams: null as number | null,
        calories: perServing.calories * factor,
        proteins: perServing.proteins === null ? null : perServing.proteins * factor,
        carbs: perServing.carbs === null ? null : perServing.carbs * factor,
        fat: perServing.fat === null ? null : perServing.fat * factor,
        is_estimate: false,
      };
    }
    const per100 =
      food && typeof food.calories === "number"
        ? {
            calories: food.calories ?? 0,
            proteins: food.proteins ?? 0,
            carbs: food.carbs ?? 0,
            fat: food.fat ?? 0,
          }
        : null;
    if (!per100) {
      const conversion = toGrams(value.quantity, value.unit, food, portions);
      return { grams: conversion.grams, calories: null, proteins: null, carbs: null, fat: null, is_estimate: conversion.is_estimate };
    }
    const result = previewNutrition(value.quantity, value.unit, per100, food, portions);
    return { ...result, grams: result.grams as number | null };
  }, [showPreview, kind, perServing, food, value.quantity, value.unit, portions]);

  const quickValues = kind === "recipe" || !isMass ? QUICK_PORTIONS : QUICK_MASS;

  return (
    <div className={cn("space-y-3", className)}>
      {units.length > 1 ? (
        <div className="flex flex-wrap items-center gap-1.5" role="radiogroup" aria-label="Unité">
          {visibleUnits.map((unit) => {
            const canonical = normalizeUnit(unit).unit;
            const def = portionFor(canonical, portions);
            const active = canonicalUnit === canonical;
            const label =
              canonical === "portion" && food?.serving_size_g
                ? `portion (${formatGrams(food.serving_size_g)})`
                : unit === stockUnit && kind === "stock"
                  ? `${def.label_short} (stock)`
                  : def.label_short;
            return (
              <button
                key={unit}
                type="button"
                role="radio"
                aria-checked={active}
                disabled={disabled}
                onClick={() => selectUnit(unit)}
                className={cn(
                  "h-9 rounded-full border px-3 text-sm font-medium transition",
                  active
                    ? "border-emerald-700 bg-emerald-700 text-white"
                    : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50",
                  disabled && "cursor-not-allowed opacity-60",
                )}
              >
                {label}
              </button>
            );
          })}
          {units.length > VISIBLE_UNITS ? (
            <button
              type="button"
              onClick={() => setShowAllUnits((current) => !current)}
              className="h-9 rounded-full px-3 text-sm font-medium text-emerald-700 hover:underline"
            >
              {showAllUnits ? "Moins" : "Plus…"}
            </button>
          ) : null}
        </div>
      ) : null}

      <div className="flex items-stretch gap-2">
        <button
          type="button"
          onClick={() => nudge(-1)}
          disabled={disabled || value.quantity <= 0}
          aria-label="Diminuer la quantité"
          className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl border border-slate-200 bg-white text-xl text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
        >
          −
        </button>
        <div className="relative min-w-0 flex-1">
          <input
            type="text"
            inputMode="decimal"
            value={text}
            disabled={disabled}
            onChange={(event) => handleText(event.target.value)}
            onBlur={() => setText(toInputText(value.quantity))}
            aria-label="Quantité"
            className="h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 pr-20 text-center text-lg font-semibold tabular-nums text-slate-900 outline-none transition focus:border-lime-600 focus:ring-4 focus:ring-lime-600/10"
          />
          <span className="pointer-events-none absolute inset-y-0 right-4 flex items-center text-sm text-slate-500">
            {kind === "recipe" ? (value.quantity > 1 ? "portions" : "portion") : portion.label_short}
          </span>
        </div>
        <button
          type="button"
          onClick={() => nudge(1)}
          disabled={disabled || (maxQuantity !== null && maxQuantity !== undefined && value.quantity >= maxQuantity)}
          aria-label="Augmenter la quantité"
          className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl border border-slate-200 bg-white text-xl text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
        >
          +
        </button>
      </div>

      <div className="flex flex-wrap items-center gap-1.5">
        {quickValues.map((quick) => (
          <button
            key={quick}
            type="button"
            disabled={disabled}
            onClick={() => {
              commit(quick);
              setText(toInputText(quick));
            }}
            className={cn(
              "h-8 rounded-full border px-3 text-xs font-medium transition",
              value.quantity === quick
                ? "border-slate-900 bg-slate-900 text-white"
                : "border-slate-200 bg-white text-slate-600 hover:bg-slate-50",
            )}
          >
            {quick === 0.5 ? "½" : formatNumber(quick, 1)} {kind === "recipe" || !isMass ? "" : portion.label_short}
          </button>
        ))}
        {maxQuantity !== null && maxQuantity !== undefined ? (
          <span className="ml-auto text-xs text-slate-500">
            Max. {formatNumber(maxQuantity, 1)} {portionFor(normalizeUnit(stockUnit ?? value.unit).unit, portions).label_short}
          </span>
        ) : null}
      </div>

      {preview ? (
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-2xl bg-slate-50 px-3 py-2 text-sm text-slate-700">
          <span className="font-medium">
            ≈{" "}
            {preview.grams !== null ? `${formatGrams(preview.grams)}` : `${formatNumber(value.quantity, 1)} portion${value.quantity > 1 ? "s" : ""}`}
          </span>
          {preview.calories !== null ? (
            <>
              <span aria-hidden="true">·</span>
              <span>{formatKcal(preview.calories)}</span>
              <span aria-hidden="true">·</span>
              <span className="tabular-nums">
                P {preview.proteins === null ? "—" : formatGrams(preview.proteins)} · G{" "}
                {preview.carbs === null ? "—" : formatGrams(preview.carbs)} · L{" "}
                {preview.fat === null ? "—" : formatGrams(preview.fat)}
              </span>
            </>
          ) : null}
          {isEstimateUnit || preview.is_estimate ? <EstimatePill className="ml-auto" /> : null}
        </div>
      ) : null}
    </div>
  );
}

export default QuantityUnitPicker;
