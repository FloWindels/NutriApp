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

export type DashboardSection = {
  slug: string;
  title: string;
  category: "Nutrition" | "Planification" | "Compte";
  icon: IconName;
  tone: DashboardTone;
};

export const dashboardSections: DashboardSection[] = [
  {
    slug: "recettes",
    title: "Recettes",
    category: "Nutrition",
    icon: "recipe",
    tone: "emerald",
  },
  {
    slug: "famille",
    title: "Famille",
    category: "Compte",
    icon: "family",
    tone: "sky",
  },
  {
    slug: "sport",
    title: "Sport",
    category: "Nutrition",
    icon: "sport",
    tone: "amber",
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
    title: "Settings",
    category: "Compte",
    icon: "settings",
    tone: "violet",
  },
  {
    slug: "recherche-aliments",
    title: "Recherche d’aliments",
    category: "Nutrition",
    icon: "search",
    tone: "cyan",
  },
  {
    slug: "stock",
    title: "Stock",
    category: "Planification",
    icon: "fridge",
    tone: "lime",
  },
  {
    slug: "historique-repas-journee",
    title: "Historique des repas du jour",
    category: "Nutrition",
    icon: "history",
    tone: "indigo",
  },
  {
    slug: "recommandations-repas-journee",
    title: "Recommandations du jour",
    category: "Nutrition",
    icon: "recommendation",
    tone: "teal",
  },
  {
    slug: "planificateur-semaine",
    title: "Planificateur de repas",
    category: "Planification",
    icon: "planner",
    tone: "orange",
  },
  {
    slug: "liste-course",
    title: "Liste de course",
    category: "Planification",
    icon: "shopping",
    tone: "slate",
  },
  {
    slug: "regime-reconnu",
    title: "Régime reconnu",
    category: "Nutrition",
    icon: "diet",
    tone: "fuchsia",
  },
];

export function getSectionBySlug(slug: string) {
  return dashboardSections.find((section) => section.slug === slug);
}
