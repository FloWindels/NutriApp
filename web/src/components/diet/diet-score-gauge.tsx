import { cn } from "@/lib/cn";
import type { DietStatut } from "@/lib/types/api";

/** Arc colour per statut (≥85 conforme · 60–84 partiel · <60 non conforme). */
export const STATUT_TONE: Record<DietStatut, { arc: string; text: string; pill: "emerald" | "amber" | "rose" | "slate" }> = {
  conforme: { arc: "text-emerald-600", text: "text-emerald-700", pill: "emerald" },
  partiel: { arc: "text-amber-500", text: "text-amber-700", pill: "amber" },
  non_conforme: { arc: "text-rose-500", text: "text-rose-700", pill: "rose" },
  donnees_insuffisantes: { arc: "text-slate-300", text: "text-slate-500", pill: "slate" },
};

const SIZE = 168;
const CENTER = SIZE / 2;
const RADIUS = 66;
const STROKE = 14;
const START_DEG = 150;
const SWEEP_DEG = 240;

function polar(angleDeg: number): [number, number] {
  const rad = (angleDeg * Math.PI) / 180;
  return [CENTER + RADIUS * Math.cos(rad), CENTER + RADIUS * Math.sin(rad)];
}

const [startX, startY] = polar(START_DEG);
const [endX, endY] = polar(START_DEG + SWEEP_DEG);
const ARC = `M ${startX.toFixed(2)} ${startY.toFixed(2)} A ${RADIUS} ${RADIUS} 0 1 1 ${endX.toFixed(2)} ${endY.toFixed(2)}`;

export type DietScoreGaugeProps = {
  /** Score out of 100; `null` draws an empty gauge with « — ». */
  scorePct: number | null;
  statut: DietStatut;
  statutLabel?: string;
  className?: string;
};

/**
 * Score gauge drawn as an inline SVG arc (240°, `pathLength` normalised to 100
 * so the dash array is the score itself).
 */
export function DietScoreGauge({ scorePct, statut, statutLabel, className }: DietScoreGaugeProps) {
  const tone = STATUT_TONE[statut] ?? STATUT_TONE.donnees_insuffisantes;
  const value = scorePct === null ? 0 : Math.min(100, Math.max(0, Math.round(scorePct)));

  return (
    <svg
      viewBox={`0 0 ${SIZE} ${SIZE * 0.78}`}
      className={cn("h-32 w-40 shrink-0", className)}
      role="img"
      aria-label={
        scorePct === null
          ? "Score du régime non disponible"
          : `Score du régime : ${value} %${statutLabel ? ` — ${statutLabel}` : ""}`
      }
    >
      <path
        d={ARC}
        fill="none"
        stroke="currentColor"
        strokeWidth={STROKE}
        strokeLinecap="round"
        className="text-slate-200"
      />
      {value > 0 ? (
        <path
          d={ARC}
          fill="none"
          stroke="currentColor"
          strokeWidth={STROKE}
          strokeLinecap="round"
          pathLength={100}
          strokeDasharray={`${value} 100`}
          className={tone.arc}
        />
      ) : null}

      <text
        x={CENTER}
        y={CENTER - 2}
        textAnchor="middle"
        className="fill-slate-900 text-[34px] font-extrabold"
      >
        {scorePct === null ? "—" : `${value} %`}
      </text>
      {statutLabel ? (
        <text x={CENTER} y={CENTER + 20} textAnchor="middle" className={cn("text-[13px] font-bold", tone.arc.replace("text-", "fill-"))}>
          {statutLabel}
        </text>
      ) : null}
    </svg>
  );
}

export default DietScoreGauge;
