import { cn } from "@/lib/cn";
import { formatGrams } from "@/lib/format";

export type MacroValues = {
  proteins: number | null | undefined;
  carbs: number | null | undefined;
  fat: number | null | undefined;
};

export type MacroPillsProps = {
  values: MacroValues;
  /** When provided, renders « P 72 / 150 g ». */
  targets?: Partial<MacroValues> | null;
  size?: "sm" | "md";
  className?: string;
};

const macroMeta = [
  { key: "proteins", short: "P", label: "Protéines", className: "border-emerald-200 bg-emerald-50 text-emerald-800" },
  { key: "carbs", short: "G", label: "Glucides", className: "border-lime-200 bg-lime-50 text-lime-800" },
  { key: "fat", short: "L", label: "Lipides", className: "border-amber-200 bg-amber-50 text-amber-900" },
] as const;

/** Three pills P / G / L, optional targets. Null values render « — ». */
export function MacroPills({ values, targets, size = "sm", className }: MacroPillsProps) {
  return (
    <div className={cn("flex flex-wrap items-center gap-1.5", className)}>
      {macroMeta.map((macro) => {
        const value = values[macro.key];
        const target = targets?.[macro.key];
        const valueText = value === null || value === undefined ? "—" : formatGrams(value, false);
        const text =
          target !== null && target !== undefined
            ? `${macro.short} ${valueText} / ${formatGrams(target)}`
            : `${macro.short} ${valueText}${value === null || value === undefined ? "" : " g"}`;
        return (
          <span
            key={macro.key}
            title={macro.label}
            aria-label={`${macro.label} : ${text.slice(2)}`}
            className={cn(
              "inline-flex items-center whitespace-nowrap rounded-full border font-medium tabular-nums",
              size === "sm" ? "px-2.5 py-0.5 text-xs" : "px-3 py-1 text-sm",
              macro.className,
            )}
          >
            {text}
          </span>
        );
      })}
    </div>
  );
}

export default MacroPills;
