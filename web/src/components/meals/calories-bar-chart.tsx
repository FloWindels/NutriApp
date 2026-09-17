import { formatDate, formatKcal, parseIsoDate } from "@/lib/format";
import type { HistoryDay } from "@/lib/types/api";

export type CaloriesBarChartProps = {
  days: HistoryDay[];
  className?: string;
};

const WIDTH = 600;
const HEIGHT = 200;
const PADDING_X = 8;
const PADDING_TOP = 12;
const PADDING_BOTTOM = 22;

function barTone(calories: number, target: number): string {
  if (!target || calories === 0) return "fill-slate-300";
  if (calories > target * 1.1) return "fill-rose-400";
  if (calories < target * 0.9) return "fill-amber-400";
  return "fill-emerald-500";
}

/** Calories per day against the target line, as an inline SVG (no chart dependency). */
export function CaloriesBarChart({ days, className }: CaloriesBarChartProps) {
  if (days.length === 0) return null;

  const maxValue = Math.max(
    1,
    ...days.map((day) => Math.max(day.calories ?? 0, day.target_calories ?? 0)),
  );
  const scaleMax = maxValue * 1.1;
  const innerWidth = WIDTH - PADDING_X * 2;
  const innerHeight = HEIGHT - PADDING_TOP - PADDING_BOTTOM;
  const slot = innerWidth / days.length;
  const barWidth = Math.max(1.5, slot * 0.62);

  const y = (value: number) => PADDING_TOP + innerHeight - (value / scaleMax) * innerHeight;
  const centerX = (index: number) => PADDING_X + slot * index + slot / 2;

  const targetPath = days
    .map((day, index) => `${index === 0 ? "M" : "L"}${centerX(index).toFixed(1)} ${y(day.target_calories ?? 0).toFixed(1)}`)
    .join(" ");

  const firstDate = days[0]?.date;
  const lastDate = days[days.length - 1]?.date;

  return (
    <div className={className}>
      <svg
        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
        role="img"
        aria-label={`Calories consommées par jour du ${formatDate(firstDate)} au ${formatDate(lastDate)}, comparées à l’objectif`}
        className="h-auto w-full"
      >
        <line
          x1={PADDING_X}
          x2={WIDTH - PADDING_X}
          y1={PADDING_TOP + innerHeight}
          y2={PADDING_TOP + innerHeight}
          className="stroke-slate-200"
          strokeWidth={1}
          vectorEffect="non-scaling-stroke"
        />
        {days.map((day, index) => {
          const value = Math.max(0, day.calories ?? 0);
          const top = y(value);
          const height = Math.max(value > 0 ? 1.5 : 0, PADDING_TOP + innerHeight - top);
          return (
            <rect
              key={day.date}
              x={centerX(index) - barWidth / 2}
              y={top}
              width={barWidth}
              height={height}
              rx={Math.min(2, barWidth / 2)}
              className={barTone(value, day.target_calories ?? 0)}
            >
              <title>{`${formatDate(day.date)} · ${formatKcal(value)} (objectif ${formatKcal(day.target_calories ?? 0)})`}</title>
            </rect>
          );
        })}
        <path
          d={targetPath}
          fill="none"
          strokeWidth={1.5}
          strokeDasharray="5 4"
          vectorEffect="non-scaling-stroke"
          className="stroke-slate-500"
        />
      </svg>
      <div className="mt-1 flex items-center justify-between text-xs text-slate-500">
        <span>{parseIsoDate(firstDate) ? formatDate(firstDate) : ""}</span>
        <span className="flex items-center gap-1.5">
          <span aria-hidden="true" className="inline-block h-0.5 w-5 border-t border-dashed border-slate-500" />
          objectif
        </span>
        <span>{parseIsoDate(lastDate) ? formatDate(lastDate) : ""}</span>
      </div>
    </div>
  );
}

export default CaloriesBarChart;
