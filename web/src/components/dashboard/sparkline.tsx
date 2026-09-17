import { cn } from "@/lib/cn";

export type SparklinePoint = { label: string; value: number };

export type SparklineProps = {
  points: SparklinePoint[];
  /** Optional horizontal reference line (e.g. le poids cible). */
  target?: number | null;
  ariaLabel: string;
  className?: string;
  tone?: "emerald" | "sky" | "rose";
};

const strokeByTone = {
  emerald: "stroke-emerald-600",
  sky: "stroke-sky-600",
  rose: "stroke-rose-600",
} as const;

const fillByTone = {
  emerald: "fill-emerald-600",
  sky: "fill-sky-600",
  rose: "fill-rose-600",
} as const;

const WIDTH = 200;
const HEIGHT = 56;
const PADDING = 6;

/** Tiny inline SVG line chart (no dependency, readable in both column widths). */
export function Sparkline({ points, target = null, ariaLabel, className, tone = "emerald" }: SparklineProps) {
  const values = points.map((point) => point.value).filter((value) => Number.isFinite(value));
  if (values.length === 0) {
    return null;
  }

  const candidates = target !== null && Number.isFinite(target) ? [...values, target] : values;
  const min = Math.min(...candidates);
  const max = Math.max(...candidates);
  const span = max - min || 1;
  const innerWidth = WIDTH - PADDING * 2;
  const innerHeight = HEIGHT - PADDING * 2;

  const x = (index: number) =>
    points.length === 1 ? WIDTH / 2 : PADDING + (index / (points.length - 1)) * innerWidth;
  const y = (value: number) => PADDING + innerHeight - ((value - min) / span) * innerHeight;

  const path = points
    .map((point, index) => `${index === 0 ? "M" : "L"}${x(index).toFixed(1)} ${y(point.value).toFixed(1)}`)
    .join(" ");
  const lastIndex = points.length - 1;

  return (
    <svg
      viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
      role="img"
      aria-label={ariaLabel}
      className={cn("h-14 w-full", className)}
    >
      {target !== null && Number.isFinite(target) ? (
        <line
          x1={PADDING}
          x2={WIDTH - PADDING}
          y1={y(target)}
          y2={y(target)}
          className="stroke-slate-300"
          strokeWidth={1}
          strokeDasharray="4 4"
          vectorEffect="non-scaling-stroke"
        />
      ) : null}
      <path
        d={path}
        fill="none"
        strokeWidth={2}
        strokeLinecap="round"
        strokeLinejoin="round"
        vectorEffect="non-scaling-stroke"
        className={strokeByTone[tone]}
      />
      <circle cx={x(lastIndex)} cy={y(points[lastIndex].value)} r={3} className={fillByTone[tone]} />
    </svg>
  );
}

export default Sparkline;
