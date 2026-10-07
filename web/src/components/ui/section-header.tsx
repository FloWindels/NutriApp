"use client";

import type { ReactNode } from "react";
import { cn } from "@/lib/cn";
import { toneStyles, type DashboardTone } from "@/lib/dashboard-sections";
import { useTitreBarre } from "@/lib/titre-barre";

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
  // Sur téléphone, la barre du haut porte déjà le titre : pas de doublon à l'écran.
  const titreBarre = useTitreBarre();
  const doublon = level === "page" && typeof title === "string" && title === titreBarre;
  return (
    <div
      className={cn("flex flex-wrap items-end justify-between gap-3", className)}
      data-tour={level === "page" ? "entete-page" : undefined}
    >
      <div className="min-w-0">
        {eyebrow ? (
          <p
            className={cn(
              "mb-1 text-[11px] font-bold uppercase tracking-[0.1em]",
              level === "page" && "max-lg:hidden",
              tone ? toneStyles[tone].subtle : "text-slate-500",
            )}
          >
            {eyebrow}
          </p>
        ) : null}
        <Heading
          className={cn(
            "tracking-tight text-slate-900",
            doublon && "max-lg:sr-only",
            level === "page" ? "text-[26px] font-extrabold leading-tight sm:text-3xl" : "text-lg font-bold sm:text-xl",
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
