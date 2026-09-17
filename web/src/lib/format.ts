/** fr-FR formatting helpers shared by every page (numbers, dates, parsing). */

const LOCALE = "fr-FR";

const intFormatter = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 0 });
const oneDecimalFormatter = new Intl.NumberFormat(LOCALE, {
  minimumFractionDigits: 0,
  maximumFractionDigits: 1,
});
const twoDecimalFormatter = new Intl.NumberFormat(LOCALE, {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
});

export function toNumber(value: unknown, fallback = 0): number {
  if (typeof value === "number") return Number.isFinite(value) ? value : fallback;
  if (typeof value === "string") {
    const parsed = parseDecimal(value);
    return parsed === null ? fallback : parsed;
  }
  return fallback;
}

/** `formatNumber(1234.5)` → « 1 234,5 » */
export function formatNumber(value: unknown, decimals: 0 | 1 | 2 = 1): string {
  const n = toNumber(value);
  if (decimals === 0) return intFormatter.format(Math.round(n));
  if (decimals === 2) return twoDecimalFormatter.format(n);
  return oneDecimalFormatter.format(n);
}

/** `formatKcal(1240)` → « 1 240 kcal » */
export function formatKcal(value: unknown, withUnit = true): string {
  const text = intFormatter.format(Math.round(toNumber(value)));
  return withUnit ? `${text} kcal` : text;
}

/** `formatGrams(12.34)` → « 12,3 g » */
export function formatGrams(value: unknown, withUnit = true): string {
  const text = oneDecimalFormatter.format(toNumber(value));
  return withUnit ? `${text} g` : text;
}

/** `formatKg(76.5)` → « 76,5 kg » */
export function formatKg(value: unknown): string {
  return `${oneDecimalFormatter.format(toNumber(value))} kg`;
}

/** `formatQty(1.5, 'portion')` → « 1,5 portion » ; `formatQty(150, 'g')` → « 150 g » */
export function formatQty(quantity: unknown, unitLabel: string): string {
  const n = toNumber(quantity);
  const text = Number.isInteger(n) ? intFormatter.format(n) : oneDecimalFormatter.format(n);
  return `${text} ${unitLabel}`.trim();
}

/** `formatPercent(0.34)`? No — takes a 0–100 value: `formatPercent(34.2)` → « 34 % » */
export function formatPercent(value: unknown): string {
  return `${intFormatter.format(Math.round(toNumber(value)))} %`;
}

/** `formatSigned(105)` → « +105 », `formatSigned(-120)` → « −120 » */
export function formatSigned(value: unknown): string {
  const n = Math.round(toNumber(value));
  if (n > 0) return `+${intFormatter.format(n)}`;
  if (n < 0) return `−${intFormatter.format(Math.abs(n))}`;
  return "0";
}

/** Minutes → « 1 h 05 » / « 45 min ». */
export function formatMinutes(value: unknown): string {
  const total = Math.max(0, Math.round(toNumber(value)));
  if (total < 60) return `${total} min`;
  const h = Math.floor(total / 60);
  const m = total % 60;
  return m === 0 ? `${h} h` : `${h} h ${String(m).padStart(2, "0")}`;
}

/* ------------------------------- dates ------------------------------- */

/** Parses `YYYY-MM-DD` (or ISO) into a local Date at midnight. */
export function parseIsoDate(value: string | Date | null | undefined): Date | null {
  if (!value) return null;
  if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  if (match) {
    const d = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return Number.isNaN(d.getTime()) ? null : d;
  }
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? null : d;
}

/** Local date → `YYYY-MM-DD`. */
export function toIsoDate(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

export function todayIso(): string {
  return toIsoDate(new Date());
}

export function addDays(date: string | Date, days: number): string {
  const base = parseIsoDate(date) ?? new Date();
  const next = new Date(base);
  next.setDate(next.getDate() + days);
  return toIsoDate(next);
}

/** Monday of the week containing `date` (ISO week start). */
export function startOfWeekMonday(date: string | Date = new Date()): string {
  const base = parseIsoDate(date) ?? new Date();
  const day = (base.getDay() + 6) % 7; // 0 = Monday
  return addDays(base, -day);
}

export function diffDays(from: string | Date, to: string | Date): number {
  const a = parseIsoDate(from);
  const b = parseIsoDate(to);
  if (!a || !b) return 0;
  return Math.round((b.getTime() - a.getTime()) / 86_400_000);
}

const dayFormatter = new Intl.DateTimeFormat(LOCALE, {
  weekday: "long",
  day: "numeric",
  month: "short",
});
const dateFormatter = new Intl.DateTimeFormat(LOCALE, {
  day: "2-digit",
  month: "2-digit",
  year: "numeric",
});
const longDateFormatter = new Intl.DateTimeFormat(LOCALE, {
  day: "numeric",
  month: "long",
  year: "numeric",
});
const monthYearFormatter = new Intl.DateTimeFormat(LOCALE, { month: "long", year: "numeric" });
const timeFormatter = new Intl.DateTimeFormat(LOCALE, { hour: "2-digit", minute: "2-digit" });

/** `formatDay('2026-09-16')` → « mardi 16 sept. » */
export function formatDay(value: string | Date | null | undefined): string {
  const d = parseIsoDate(value);
  return d ? dayFormatter.format(d) : "";
}

/** `formatDate('2026-09-16')` → « 16/09/2026 » */
export function formatDate(value: string | Date | null | undefined): string {
  const d = parseIsoDate(value);
  return d ? dateFormatter.format(d) : "";
}

/** `formatLongDate('2026-09-16')` → « 16 septembre 2026 » */
export function formatLongDate(value: string | Date | null | undefined): string {
  const d = parseIsoDate(value);
  return d ? longDateFormatter.format(d) : "";
}

/** `formatMonthYear(date)` → « septembre 2026 » */
export function formatMonthYear(value: string | Date): string {
  const d = parseIsoDate(value);
  return d ? monthYearFormatter.format(d) : "";
}

/** ISO timestamp or `HH:mm(:ss)` → « 18:30 ». */
export function formatTime(value: string | Date | null | undefined): string {
  if (!value) return "";
  if (typeof value === "string") {
    const match = /^(\d{2}):(\d{2})/.exec(value);
    if (match && !value.includes("T")) return `${match[1]}:${match[2]}`;
  }
  const d = value instanceof Date ? value : new Date(value);
  return Number.isNaN(d.getTime()) ? "" : timeFormatter.format(d);
}

/** « Aujourd’hui » / « Hier » / « Demain », otherwise `formatDay`. */
export function formatRelativeDay(value: string | Date | null | undefined): string {
  const d = parseIsoDate(value);
  if (!d) return "";
  const delta = diffDays(todayIso(), toIsoDate(d));
  if (delta === 0) return "Aujourd’hui";
  if (delta === -1) return "Hier";
  if (delta === 1) return "Demain";
  return formatDay(d);
}

/** Capitalise the first letter (« mardi 16 sept. » → « Mardi 16 sept. »). */
export function capitalize(text: string): string {
  return text ? text.charAt(0).toUpperCase() + text.slice(1) : text;
}

/* ------------------------------ parsing ------------------------------ */

/** Accepts « 1,5 », « 1.5 », « 1 250 » → number ; returns null when not numeric. */
export function parseDecimal(value: string | number | null | undefined): number | null {
  if (typeof value === "number") return Number.isFinite(value) ? value : null;
  if (value === null || value === undefined) return null;
  const cleaned = value
    .replace(/[\s  ]/g, "")
    .replace(",", ".")
    .trim();
  if (!cleaned || !/^[-+]?\d*(\.\d+)?$/.test(cleaned) || cleaned === "." || cleaned === "-") {
    return null;
  }
  const n = Number(cleaned);
  return Number.isFinite(n) ? n : null;
}

/** Rounds to a given step (0.5 → half portions). */
export function roundToStep(value: number, step: number): number {
  if (step <= 0) return value;
  const rounded = Math.round(value / step) * step;
  return Number(rounded.toFixed(2));
}
