import { z } from "zod";
import { parseDecimal } from "@/lib/format";

/**
 * Zod helpers for numeric text inputs: the form keeps strings (so « 12,5 » is
 * accepted as typed) and the submit handler converts with `numOrNull`.
 */

const NUMBER_MESSAGE = "Indique un nombre positif (ex. : 12,5).";

export const decimalOptional = z
  .string()
  .refine((value) => value.trim() === "" || (parseDecimal(value) ?? -1) >= 0, { message: NUMBER_MESSAGE });

export const decimalRequired = z
  .string()
  .refine((value) => (parseDecimal(value) ?? -1) >= 0, { message: NUMBER_MESSAGE });

/** `YYYY-MM-DD` or empty. */
export const isoDateOptional = z
  .string()
  .refine((value) => value === "" || /^\d{4}-\d{2}-\d{2}$/.test(value), { message: "Date invalide." });

/** « 12,5 » → 12.5 ; « » → null. */
export function numOrNull(value: string | null | undefined): number | null {
  if (value === null || value === undefined || value.trim() === "") return null;
  return parseDecimal(value);
}

/** Number → text for a controlled input (comma decimal separator, no trailing zeros). */
export function toText(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value)) return "";
  return Number.isInteger(value) ? String(value) : String(Number(value.toFixed(2))).replace(".", ",");
}

/** Empty string → null (optional text fields). */
export function textOrNull(value: string | null | undefined): string | null {
  const trimmed = value?.trim() ?? "";
  return trimmed === "" ? null : trimmed;
}
