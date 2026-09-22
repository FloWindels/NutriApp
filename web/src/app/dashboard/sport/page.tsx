"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense } from "react";
import { CalendarTab } from "@/components/sport/calendar-tab";
import { SessionsTab } from "@/components/sport/sessions-tab";
import { TodayTab } from "@/components/sport/today-tab";
import { SectionHeader } from "@/components/ui/section-header";
import { SkeletonCard } from "@/components/ui/skeleton";
import { cn } from "@/lib/cn";

const ROUTE = "/dashboard/sport";

const TABS = [
  { key: "aujourdhui", label: "Aujourd’hui" },
  { key: "calendrier", label: "Calendrier" },
  { key: "seances", label: "Séances" },
] as const;

type TabKey = (typeof TABS)[number]["key"];

function isTab(value: string | null): value is TabKey {
  return value === "aujourdhui" || value === "calendrier" || value === "seances";
}

/** Section « Sport » — aujourd’hui, calendrier et séances (addendum §D et §E). */
export default function SportPage() {
  return (
    <Suspense fallback={<SportPageFallback />}>
      <SportPageContent />
    </Suspense>
  );
}

function SportPageContent() {
  const router = useRouter();
  const params = useSearchParams();

  const raw = params.get("onglet");
  const tab: TabKey = isTab(raw) ? raw : "aujourdhui";
  const autoGenerate = params.get("generer") === "1";

  function selectTab(next: TabKey) {
    const query = new URLSearchParams(params.toString());
    query.set("onglet", next);
    query.delete("generer");
    router.replace(`${ROUTE}?${query.toString()}`, { scroll: false });
  }

  return (
    <div className="space-y-6">
      <SectionHeader
        eyebrow="Sport"
        title="Tes séances"
        subtitle="Planifie tes jours d’entraînement, enregistre ce que tu fais, et récupère les calories brûlées dans ton budget."
        tone="amber"
      />

      <div
        role="tablist"
        aria-label="Vues du module sport"
        className="flex flex-wrap gap-1 rounded-2xl border border-slate-200 bg-white p-1"
      >
        {TABS.map((item) => {
          const active = item.key === tab;
          return (
            <button
              key={item.key}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => selectTab(item.key)}
              className={cn(
                "min-h-10 flex-1 rounded-xl px-4 py-2 text-sm font-medium transition",
                active
                  ? "bg-emerald-700 text-white shadow-sm"
                  : "text-slate-600 hover:bg-slate-50 hover:text-slate-900",
              )}
            >
              {item.label}
            </button>
          );
        })}
      </div>

      {tab === "aujourdhui" ? <TodayTab autoGenerate={autoGenerate} /> : null}
      {tab === "calendrier" ? <CalendarTab /> : null}
      {tab === "seances" ? <SessionsTab /> : null}
    </div>
  );
}

function SportPageFallback() {
  return (
    <div className="space-y-6">
      <SectionHeader eyebrow="Sport" title="Tes séances" tone="amber" />
      <SkeletonCard />
    </div>
  );
}
