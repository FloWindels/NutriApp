"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { MealsHistory } from "@/components/meals/meals-history";
import { MealsJournal } from "@/components/meals/meals-journal";
import { invalidateMealFamilies } from "@/components/meals/meal-mutations";
import { Button } from "@/components/ui/button";
import { DateStrip } from "@/components/ui/date-strip";
import { SectionHeader } from "@/components/ui/section-header";
import { SkeletonCard } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { apiPost, getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { addDays, formatRelativeDay, todayIso } from "@/lib/format";
import type { MealCopyResponse } from "@/lib/types/api";

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
const ROUTE = "/dashboard/historique-repas-journee";

/** « Repas du jour » — journal of the day and its history sub-view (`?vue=historique`). */
export default function MealsPage() {
  return (
    <Suspense fallback={<MealsPageFallback />}>
      <MealsPageContent />
    </Suspense>
  );
}

function MealsPageFallback() {
  return (
    <div className="space-y-6">
      <SectionHeader level="page" eyebrow="Nutrition" tone="indigo" title="Repas du jour" />
      <SkeletonCard lines={4} />
    </div>
  );
}

function MealsPageContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const queryClient = useQueryClient();
  const { success, error: toastError } = useToast();
  const [copying, setCopying] = useState(false);

  const rawDate = searchParams.get("date");
  const date = rawDate && ISO_DATE.test(rawDate) ? rawDate : todayIso();
  const view = searchParams.get("vue") === "historique" ? "historique" : "journal";

  function navigate(next: { date?: string; view?: "journal" | "historique" }) {
    const params = new URLSearchParams();
    const nextDate = next.date ?? date;
    const nextView = next.view ?? view;
    if (nextDate !== todayIso()) params.set("date", nextDate);
    if (nextView === "historique") params.set("vue", "historique");
    const queryString = params.toString();
    router.replace(queryString ? `${ROUTE}?${queryString}` : ROUTE, { scroll: false });
  }

  async function copyYesterday() {
    setCopying(true);
    try {
      const response = await apiPost<MealCopyResponse>("/meals/copy", {
        from_date: addDays(date, -1),
        to_date: date,
      });
      await invalidateMealFamilies(queryClient);
      success("Repas copiés", response.message ?? "Les repas de la veille ont été ajoutés.");
    } catch (error) {
      toastError("Copie impossible", getErrorMessage(error));
    } finally {
      setCopying(false);
    }
  }

  return (
    <div className="space-y-6">
      <SectionHeader
        level="page"
        eyebrow="Nutrition"
        tone="indigo"
        title="Repas du jour"
        subtitle={
          view === "journal"
            ? "Ce que tu as mangé, repas par repas."
            : "Ta courbe de calories et ton adhérence sur les dernières semaines."
        }
      />

      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <DateStrip value={date} onChange={(next) => navigate({ date: next })} className="lg:max-w-sm lg:flex-1" />

        <div className="flex flex-wrap items-center gap-2">
          {view === "journal" ? (
            <Button variant="secondary" onClick={copyYesterday} loading={copying}>
              Copier le repas d’hier
            </Button>
          ) : null}

          <div
            role="group"
            aria-label="Vue"
            className="inline-flex rounded-2xl border border-slate-200 bg-white p-1"
          >
            {(
              [
                { key: "journal", label: "Journal" },
                { key: "historique", label: "Historique" },
              ] as const
            ).map((option) => (
              <button
                key={option.key}
                type="button"
                onClick={() => navigate({ view: option.key })}
                aria-pressed={view === option.key}
                className={cn(
                  "inline-flex h-10 items-center rounded-xl px-4 text-sm font-medium transition",
                  view === option.key
                    ? "bg-emerald-700 text-white"
                    : "text-slate-600 hover:bg-slate-50 hover:text-slate-900",
                )}
              >
                {option.label}
              </button>
            ))}
          </div>
        </div>
      </div>

      {view === "journal" ? (
        <MealsJournal date={date} />
      ) : (
        <MealsHistory
          date={date}
          onSelectDay={(day) => navigate({ date: day, view: "journal" })}
        />
      )}

      <p className="text-xs text-slate-500">
        Journée affichée : {formatRelativeDay(date)}.
      </p>
    </div>
  );
}
