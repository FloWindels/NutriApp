import type { ReactNode } from "react";
import { cn } from "@/lib/cn";
import { toneStyles, type DashboardTone } from "@/lib/dashboard-sections";

export type SectionHeaderProps = {
  /** Small uppercase label above the title (e.g. « Nutrition »). */
  eyebrow?: ReactNode;
  title: ReactNode;
  subtitle?: ReactNode;
  /** Buttons / filters aligned to the right. */
  actions?: ReactNode;
  tone?: DashboardTone;
  /** `page` renders a large h1; `section` a medium h2. */
  level?: "page" | "section";
  className?: string;
};

export function SectionHeader({
  eyebrow,
  title,
  subtitle,
  actions,
  tone,
  level = "section",
  className,
}: SectionHeaderProps) {
  const Heading = level === "page" ? "h1" : "h2";
  return (
    <div className={cn("flex flex-wrap items-end justify-between gap-3", className)}>
      <div className="min-w-0">
        {eyebrow ? (
          <p
            className={cn(
              "mb-2 inline-flex rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]",
              tone ? toneStyles[tone].badge : "border-slate-200 bg-slate-50 text-slate-600",
            )}
          >
            {eyebrow}
          </p>
        ) : null}
        <Heading
          className={cn(
            "font-semibold tracking-tight text-slate-950",
            level === "page" ? "text-2xl sm:text-3xl" : "text-lg sm:text-xl",
          )}
        >
          {title}
        </Heading>
        {subtitle ? <p className="mt-1 text-sm leading-6 text-slate-500">{subtitle}</p> : null}
      </div>
      {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
    </div>
  );
}

export default SectionHeader;

/**
 * Intertitre discret au-dessus d'un bloc (« Nutrition », « Cette semaine »).
 *
 * Écrit vingt et une fois à l'identique dans les pages, à la marge près : celle-ci reste à
 * l'appelant via `className`, puisque c'est la seule chose qui variait réellement.
 * `as` existe parce que quatre de ces intertitres sont des `h3` ou des `th` — changer leur
 * balise casserait la hiérarchie des titres ou le tableau du planificateur.
 */
export function Overline({
  as: Tag = "p",
  className,
  children,
}: {
  as?: "p" | "h2" | "h3" | "h4" | "th" | "span";
  className?: string;
  children: ReactNode;
}) {
  return (
    <Tag className={cn("text-xs font-semibold uppercase tracking-[0.14em] text-slate-500", className)}>
      {children}
    </Tag>
  );
}
