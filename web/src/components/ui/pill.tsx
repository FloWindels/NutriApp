import type { ComponentPropsWithoutRef, ReactNode } from "react";
import { cn } from "@/lib/cn";

export type PillTone =
  | "slate"
  | "emerald"
  | "lime"
  | "amber"
  | "rose"
  | "sky"
  | "violet"
  | "indigo"
  | "teal"
  | "orange"
  | "cyan"
  | "fuchsia"
  | "dark";

export type PillProps = ComponentPropsWithoutRef<"span"> & {
  tone?: PillTone;
  size?: "sm" | "md";
  /** Leading dot in the tone colour. */
  dot?: boolean;
  children: ReactNode;
};

const toneClasses: Record<PillTone, string> = {
  slate: "border-slate-200 bg-slate-50 text-slate-700",
  emerald: "border-emerald-200 bg-emerald-50 text-emerald-700",
  lime: "border-lime-200 bg-lime-50 text-lime-700",
  amber: "border-amber-200 bg-amber-50 text-amber-800",
  rose: "border-rose-200 bg-rose-50 text-rose-700",
  sky: "border-sky-200 bg-sky-50 text-sky-700",
  violet: "border-violet-200 bg-violet-50 text-violet-700",
  indigo: "border-indigo-200 bg-indigo-50 text-indigo-700",
  teal: "border-teal-200 bg-teal-50 text-teal-700",
  orange: "border-orange-200 bg-orange-50 text-orange-700",
  cyan: "border-cyan-200 bg-cyan-50 text-cyan-700",
  fuchsia: "border-fuchsia-200 bg-fuchsia-50 text-fuchsia-700",
  dark: "border-slate-900 bg-slate-900 text-white",
};

const dotClasses: Record<PillTone, string> = {
  slate: "bg-slate-500",
  emerald: "bg-emerald-500",
  lime: "bg-lime-500",
  amber: "bg-amber-500",
  rose: "bg-rose-500",
  sky: "bg-sky-500",
  violet: "bg-violet-500",
  indigo: "bg-indigo-500",
  teal: "bg-teal-500",
  orange: "bg-orange-500",
  cyan: "bg-cyan-500",
  fuchsia: "bg-fuchsia-500",
  dark: "bg-white",
};

export function Pill({ tone = "slate", size = "sm", dot = false, className, children, ...props }: PillProps) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border font-medium",
        size === "sm" ? "px-2.5 py-0.5 text-xs" : "px-3 py-1 text-sm",
        toneClasses[tone],
        className,
      )}
      {...props}
    >
      {dot ? <span aria-hidden="true" className={cn("h-1.5 w-1.5 rounded-full", dotClasses[tone])} /> : null}
      {children}
    </span>
  );
}

/** Amber « estimation » marker used wherever a value is derived (brief §0.1). */
export function EstimatePill({ className }: { className?: string }) {
  return (
    <Pill tone="amber" className={className} title="Valeur estimée à partir d’une conversion ou d’un modèle">
      estimation
    </Pill>
  );
}

export default Pill;
