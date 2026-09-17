import type { IconName } from "../components/menu-icons";

export type DashboardTone =
  | "emerald"
  | "sky"
  | "amber"
  | "rose"
  | "violet"
  | "cyan"
  | "lime"
  | "indigo"
  | "teal"
  | "orange"
  | "slate"
  | "fuchsia";

export type DashboardCategory = "Nutrition" | "Planification" | "Compte";

export type DashboardSection = {
  /** Frozen slug (brief: navigation table). */
  slug: string;
  title: string;
  category: DashboardCategory;
  icon: IconName;
  tone: DashboardTone;
};

export const categoryOrder: DashboardCategory[] = ["Nutrition", "Planification", "Compte"];

/** The 12 navigable sections (plus « Accueil » at `/dashboard`). Order = navigation table. */
export const dashboardSections: DashboardSection[] = [
  {
    slug: "historique-repas-journee",
    title: "Repas du jour",
    category: "Nutrition",
    icon: "history",
    tone: "indigo",
  },
  {
    slug: "recherche-aliments",
    title: "Recherche d’aliments",
    category: "Nutrition",
    icon: "search",
    tone: "cyan",
  },
  {
    slug: "recettes",
    title: "Recettes",
    category: "Nutrition",
    icon: "recipe",
    tone: "emerald",
  },
  {
    slug: "recommandations-repas-journee",
    title: "Coach du jour",
    category: "Nutrition",
    icon: "recommendation",
    tone: "teal",
  },
  {
    slug: "regime-reconnu",
    title: "Régime reconnu",
    category: "Nutrition",
    icon: "diet",
    tone: "fuchsia",
  },
  {
    slug: "stock",
    title: "Stock",
    category: "Planification",
    icon: "fridge",
    tone: "lime",
  },
  {
    slug: "planificateur-semaine",
    title: "Planificateur de la semaine",
    category: "Planification",
    icon: "planner",
    tone: "orange",
  },
  {
    slug: "liste-course",
    title: "Liste de courses",
    category: "Planification",
    icon: "shopping",
    tone: "slate",
  },
  {
    slug: "sport",
    title: "Sport",
    category: "Planification",
    icon: "sport",
    tone: "amber",
  },
  {
    slug: "famille",
    title: "Famille",
    category: "Compte",
    icon: "family",
    tone: "sky",
  },
  {
    slug: "profil",
    title: "Profil",
    category: "Compte",
    icon: "profile",
    tone: "rose",
  },
  {
    slug: "settings",
    title: "Paramètres",
    category: "Compte",
    icon: "settings",
    tone: "violet",
  },
];

export function getSectionBySlug(slug: string): DashboardSection | undefined {
  return dashboardSections.find((section) => section.slug === slug);
}

export function sectionsByCategory(): { category: DashboardCategory; sections: DashboardSection[] }[] {
  return categoryOrder.map((category) => ({
    category,
    sections: dashboardSections.filter((section) => section.category === category),
  }));
}

export type ToneStyle = {
  /** Small badge / pill. */
  badge: string;
  /** Solid accent bar or dot. */
  bar: string;
  /** Tinted card surface. */
  card: string;
  /** Dark text on the tinted surface. */
  subtle: string;
  /** Soft icon background. */
  soft: string;
};

/** Tailwind classes per tone, shared by the shell, section pages and UI components. */
export const toneStyles: Record<DashboardTone, ToneStyle> = {
  emerald: {
    badge: "border-emerald-200 bg-emerald-50 text-emerald-700",
    bar: "bg-emerald-500",
    card: "border-emerald-200 bg-emerald-50",
    subtle: "text-emerald-900",
    soft: "bg-emerald-100 text-emerald-700",
  },
  sky: {
    badge: "border-sky-200 bg-sky-50 text-sky-700",
    bar: "bg-sky-500",
    card: "border-sky-200 bg-sky-50",
    subtle: "text-sky-900",
    soft: "bg-sky-100 text-sky-700",
  },
  amber: {
    badge: "border-amber-200 bg-amber-50 text-amber-800",
    bar: "bg-amber-500",
    card: "border-amber-200 bg-amber-50",
    subtle: "text-amber-950",
    soft: "bg-amber-100 text-amber-800",
  },
  rose: {
    badge: "border-rose-200 bg-rose-50 text-rose-700",
    bar: "bg-rose-500",
    card: "border-rose-200 bg-rose-50",
    subtle: "text-rose-900",
    soft: "bg-rose-100 text-rose-700",
  },
  violet: {
    badge: "border-violet-200 bg-violet-50 text-violet-700",
    bar: "bg-violet-500",
    card: "border-violet-200 bg-violet-50",
    subtle: "text-violet-900",
    soft: "bg-violet-100 text-violet-700",
  },
  cyan: {
    badge: "border-cyan-200 bg-cyan-50 text-cyan-700",
    bar: "bg-cyan-500",
    card: "border-cyan-200 bg-cyan-50",
    subtle: "text-cyan-900",
    soft: "bg-cyan-100 text-cyan-700",
  },
  lime: {
    badge: "border-lime-200 bg-lime-50 text-lime-700",
    bar: "bg-lime-500",
    card: "border-lime-200 bg-lime-50",
    subtle: "text-lime-900",
    soft: "bg-lime-100 text-lime-700",
  },
  indigo: {
    badge: "border-indigo-200 bg-indigo-50 text-indigo-700",
    bar: "bg-indigo-500",
    card: "border-indigo-200 bg-indigo-50",
    subtle: "text-indigo-900",
    soft: "bg-indigo-100 text-indigo-700",
  },
  teal: {
    badge: "border-teal-200 bg-teal-50 text-teal-700",
    bar: "bg-teal-500",
    card: "border-teal-200 bg-teal-50",
    subtle: "text-teal-900",
    soft: "bg-teal-100 text-teal-700",
  },
  orange: {
    badge: "border-orange-200 bg-orange-50 text-orange-700",
    bar: "bg-orange-500",
    card: "border-orange-200 bg-orange-50",
    subtle: "text-orange-900",
    soft: "bg-orange-100 text-orange-700",
  },
  slate: {
    badge: "border-slate-200 bg-slate-50 text-slate-700",
    bar: "bg-slate-500",
    card: "border-slate-200 bg-slate-50",
    subtle: "text-slate-900",
    soft: "bg-slate-100 text-slate-700",
  },
  fuchsia: {
    badge: "border-fuchsia-200 bg-fuchsia-50 text-fuchsia-700",
    bar: "bg-fuchsia-500",
    card: "border-fuchsia-200 bg-fuchsia-50",
    subtle: "text-fuchsia-900",
    soft: "bg-fuchsia-100 text-fuchsia-700",
  },
};
