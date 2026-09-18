import type { ReactNode } from "react";
import { cn } from "@/lib/cn";
import type { RecommendationPriority, RecommendationType } from "@/lib/types/api";

const stroke = {
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 1.8,
  strokeLinecap: "round" as const,
  strokeLinejoin: "round" as const,
};

/** One glyph per recommendation type (brief §8) — same outline style as the menu icons. */
const glyphs: Record<RecommendationType, ReactNode> = {
  sous_plancher: (
    <>
      <path {...stroke} d="M12 4V15" />
      <path {...stroke} d="M8 11L12 15L16 11" />
      <path {...stroke} d="M5 19H19" />
    </>
  ),
  produit_perime: (
    <>
      <path {...stroke} d="M12 4.5L20.5 19H3.5L12 4.5Z" />
      <path {...stroke} d="M12 10V13.5" />
      <path {...stroke} d="M12 16.2V16.3" />
    </>
  ),
  alerte_budget: (
    <>
      <path {...stroke} d="M4 16L9.5 10.5L13 14L20 7" />
      <path {...stroke} d="M15 7H20V12" />
      <path {...stroke} d="M4 20H20" />
    </>
  ),
  budget_restant: (
    <>
      <circle {...stroke} cx="12" cy="12" r="7.5" />
      <path {...stroke} d="M12 8V12L14.5 14" />
    </>
  ),
  manque_proteines: (
    <>
      <path {...stroke} d="M12 4C15 4 17 7 17 11C17 16 14.5 20 12 20C9.5 20 7 16 7 11C7 7 9 4 12 4Z" />
      <path {...stroke} d="M9.5 11.5H14.5" />
    </>
  ),
  anti_gaspillage: (
    <>
      <path {...stroke} d="M12 20C16 18.5 18.5 15.5 18.5 11V6.5L12 4L5.5 6.5V11C5.5 15.5 8 18.5 12 20Z" />
      <path {...stroke} d="M9 12L11.2 14L15 9.5" />
    </>
  ),
  suggestion_repas: (
    <>
      <circle {...stroke} cx="11" cy="12" r="6" />
      <path {...stroke} d="M19 5V19" />
      <path {...stroke} d="M17.5 7H20.5" />
    </>
  ),
  ajustement_portions: (
    <>
      <path {...stroke} d="M12 4V20" />
      <path {...stroke} d="M6 8H18" />
      <path {...stroke} d="M4 15C4 13 5 11 6 11C7 11 8 13 8 15C8 15 7 16 6 16C5 16 4 15 4 15Z" />
      <path {...stroke} d="M16 15C16 13 17 11 18 11C19 11 20 13 20 15C20 15 19 16 18 16C17 16 16 15 16 15Z" />
    </>
  ),
  sport_pre: (
    <>
      <circle {...stroke} cx="14" cy="5.5" r="2" />
      <path {...stroke} d="M8 20L11 15L9 12L10.5 8L14 9.5L17 12" />
      <path {...stroke} d="M11 15L15 16.5L16.5 20" />
      <path {...stroke} d="M4 11H7" />
    </>
  ),
  sport_post: (
    <>
      <path {...stroke} d="M7 9V15M17 9V15" />
      <path {...stroke} d="M4.5 10.5V13.5M19.5 10.5V13.5" />
      <path {...stroke} d="M7 12H17" />
    </>
  ),
  courses: (
    <>
      <path {...stroke} d="M4 8H20L18.5 18H5.5L4 8Z" />
      <path {...stroke} d="M9 8L10.5 4H13.5L15 8" />
      <path {...stroke} d="M10 12V15M14 12V15" />
    </>
  ),
  hydratation: (
    <>
      <path {...stroke} d="M12 4C12 4 6.5 10.2 6.5 14C6.5 17 9 19.5 12 19.5C15 19.5 17.5 17 17.5 14C17.5 10.2 12 4 12 4Z" />
      <path {...stroke} d="M9.5 14.5C9.5 16 10.5 17 12 17.2" />
    </>
  ),
  profil_incomplet: (
    <>
      <circle {...stroke} cx="12" cy="8.5" r="3" />
      <path {...stroke} d="M5.5 19C6.2 15.8 8.8 14 12 14C15.2 14 17.8 15.8 18.5 19" />
    </>
  ),
};

const fallbackGlyph = (
  <>
    <circle {...stroke} cx="12" cy="12" r="7.5" />
    <path {...stroke} d="M12 8.5V12.5" />
    <path {...stroke} d="M12 15.5V15.6" />
  </>
);

/** Icon badge background per priority (1 sécurité, 2 objectif du jour, 3 confort). */
export const priorityBadgeStyles: Record<RecommendationPriority, string> = {
  1: "bg-rose-100 text-rose-700",
  2: "bg-amber-100 text-amber-800",
  3: "bg-slate-100 text-slate-600",
};

export type RecommendationIconProps = {
  type: RecommendationType;
  className?: string;
};

export function RecommendationIcon({ type, className = "h-5 w-5" }: RecommendationIconProps) {
  return (
    <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
      {glyphs[type] ?? fallbackGlyph}
    </svg>
  );
}

/** Rounded badge holding the type icon, coloured by priority. */
export function RecommendationBadge({
  type,
  priority,
  className,
}: {
  type: RecommendationType;
  priority: RecommendationPriority;
  className?: string;
}) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        "grid h-10 w-10 shrink-0 place-items-center rounded-2xl",
        priorityBadgeStyles[priority] ?? priorityBadgeStyles[3],
        className,
      )}
    >
      <RecommendationIcon type={type} />
    </span>
  );
}

export default RecommendationIcon;
