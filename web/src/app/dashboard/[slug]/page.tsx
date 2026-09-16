import Link from "next/link";
import { notFound } from "next/navigation";
import DashboardShell from "../../../components/dashboard-shell";
import FoodEanSearch from "../../../components/food-ean-search";
import FridgePage from "../../../components/fridge-page";
import RecipePage from "../../../components/recipe-page";
import MenuIcon from "../../../components/menu-icons";
import { dashboardSections, getSectionBySlug, type DashboardTone } from "../../../lib/dashboard-sections";

type PageProps = {
  params: {
    slug: string;
  };
};

const toneStyles: Record<
  DashboardTone,
  {
    badge: string;
    bar: string;
    card: string;
    subtle: string;
  }
> = {
  emerald: {
    badge: "border-emerald-200 bg-emerald-50 text-emerald-700",
    bar: "bg-emerald-500",
    card: "border-emerald-200 bg-emerald-50",
    subtle: "text-emerald-900",
  },
  sky: {
    badge: "border-sky-200 bg-sky-50 text-sky-700",
    bar: "bg-sky-500",
    card: "border-sky-200 bg-sky-50",
    subtle: "text-sky-900",
  },
  amber: {
    badge: "border-amber-200 bg-amber-50 text-amber-800",
    bar: "bg-amber-500",
    card: "border-amber-200 bg-amber-50",
    subtle: "text-amber-950",
  },
  rose: {
    badge: "border-rose-200 bg-rose-50 text-rose-700",
    bar: "bg-rose-500",
    card: "border-rose-200 bg-rose-50",
    subtle: "text-rose-900",
  },
  violet: {
    badge: "border-violet-200 bg-violet-50 text-violet-700",
    bar: "bg-violet-500",
    card: "border-violet-200 bg-violet-50",
    subtle: "text-violet-900",
  },
  cyan: {
    badge: "border-cyan-200 bg-cyan-50 text-cyan-700",
    bar: "bg-cyan-500",
    card: "border-cyan-200 bg-cyan-50",
    subtle: "text-cyan-900",
  },
  lime: {
    badge: "border-lime-200 bg-lime-50 text-lime-700",
    bar: "bg-lime-500",
    card: "border-lime-200 bg-lime-50",
    subtle: "text-lime-900",
  },
  indigo: {
    badge: "border-indigo-200 bg-indigo-50 text-indigo-700",
    bar: "bg-indigo-500",
    card: "border-indigo-200 bg-indigo-50",
    subtle: "text-indigo-900",
  },
  teal: {
    badge: "border-teal-200 bg-teal-50 text-teal-700",
    bar: "bg-teal-500",
    card: "border-teal-200 bg-teal-50",
    subtle: "text-teal-900",
  },
  orange: {
    badge: "border-orange-200 bg-orange-50 text-orange-700",
    bar: "bg-orange-500",
    card: "border-orange-200 bg-orange-50",
    subtle: "text-orange-900",
  },
  slate: {
    badge: "border-slate-200 bg-slate-50 text-slate-700",
    bar: "bg-slate-500",
    card: "border-slate-200 bg-slate-50",
    subtle: "text-slate-900",
  },
  fuchsia: {
    badge: "border-fuchsia-200 bg-fuchsia-50 text-fuchsia-700",
    bar: "bg-fuchsia-500",
    card: "border-fuchsia-200 bg-fuchsia-50",
    subtle: "text-fuchsia-900",
  },
};

export default function DashboardMockPage({ params }: PageProps) {
  const section = getSectionBySlug(params.slug);

  if (!section) {
    notFound();
  }

  const relatedSections = dashboardSections.filter((item) => item.slug !== section.slug).slice(0, 3);
  const style = toneStyles[section.tone];

  if (section.slug === "recherche-aliments") {
    return (
      <DashboardShell>
        <FoodEanSearch />
      </DashboardShell>
    );
  }

  if (section.slug === "recettes") {
    return (
      <DashboardShell>
        <RecipePage />
      </DashboardShell>
    );
  }

  if (section.slug === "stock" || section.slug === "mon-frigo") {
    return (
      <DashboardShell>
        <FridgePage />
      </DashboardShell>
    );
  }

  return (
    <DashboardShell>
      <div className="grid min-h-[calc(100vh-5rem)] place-items-center">
        <div className="grid w-full max-w-4xl gap-4 sm:grid-cols-[1.2fr_0.8fr]">
          <div className={`rounded-[2rem] border ${style.card} p-6 shadow-[0_18px_40px_rgba(15,23,42,0.08)]`}>
            <div className={`h-2 w-20 rounded-full ${style.bar}`} />
            <div className="mt-6 grid h-48 place-items-center rounded-[1.5rem] border border-white/80 bg-white">
              <div className="grid h-20 w-20 place-items-center rounded-[1.5rem] bg-slate-950 text-white">
                <MenuIcon name={section.icon} className="h-9 w-9" strokeWidth={1.7} />
              </div>
            </div>
          </div>

          <div className="grid gap-4">
            <div className="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-[0_18px_40px_rgba(15,23,42,0.08)]">
              <h2 className="text-2xl font-semibold tracking-tight text-slate-950">{section.title}</h2>
              <div className="mt-4 flex gap-3">
                <Link
                  href="/dashboard"
                  className="rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                >
                  Retour
                </Link>
                <span className="rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-400">
                  Mock
                </span>
              </div>
            </div>

            <div className="grid grid-cols-3 gap-3">
              {relatedSections.map((item) => (
                <Link
                  key={item.slug}
                  href={`/dashboard/${item.slug}`}
                  aria-label={item.title}
                  className="grid aspect-square place-items-center rounded-[1.4rem] border border-slate-200 bg-white text-slate-400 shadow-[0_12px_30px_rgba(15,23,42,0.08)] transition hover:-translate-y-1 hover:text-slate-950"
                >
                  <MenuIcon name={item.icon} className="h-6 w-6" />
                </Link>
              ))}
            </div>
          </div>
        </div>
      </div>
    </DashboardShell>
  );
}
