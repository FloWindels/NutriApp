"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { BudgetCard } from "@/components/dashboard/budget-card";
import { CoachCard } from "@/components/dashboard/coach-card";
import { NextMealCard } from "@/components/dashboard/next-meal-card";
import { SportCard } from "@/components/dashboard/sport-card";
import { StockAlertCard } from "@/components/dashboard/stock-alert-card";
import { WeightCard } from "@/components/dashboard/weight-card";
import { AddToMealDialog, type AddToMealPreset } from "@/components/ui/add-to-meal-dialog";
import { Card } from "@/components/ui/card";
import { ErrorState } from "@/components/ui/error-state";
import { SectionHeader } from "@/components/ui/section-header";
import { Skeleton, SkeletonCard } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { apiGet, getErrorMessage } from "@/lib/api-client";
import { capitalize, formatDay, todayIso } from "@/lib/format";
import { queryKeys } from "@/lib/query-keys";
import { useSession } from "@/lib/session";
import type {
  Dashboard,
  DataEnvelope,
  Food,
  MealType,
  Recipe,
  RecommendationAction,
} from "@/lib/types/api";

/** Accueil — the real dashboard from `GET /api/dashboard` (brief §7 & §16.2). */
export default function DashboardPage() {
  const router = useRouter();
  const { error: toastError } = useToast();
  const { user } = useSession();
  const date = useMemo(() => todayIso(), []);

  const [dialog, setDialog] = useState<{
    open: boolean;
    mealType?: MealType;
    preset: AddToMealPreset | null;
  }>({ open: false, preset: null });
  const [pendingKey, setPendingKey] = useState<string | null>(null);

  const query = useQuery({
    queryKey: queryKeys.dashboard.day(date),
    queryFn: () => apiGet<DataEnvelope<Dashboard>>("/dashboard", { date }),
  });

  const dashboard = query.data?.data;
  const firstName = dashboard?.user?.first_name ?? user?.name?.split(" ")[0] ?? "";

  async function handleRecommendationAction(action: RecommendationAction, key: string) {
    switch (action.kind) {
      case "ajouter_au_repas": {
        setPendingKey(key);
        try {
          if (action.food_id) {
            const response = await apiGet<DataEnvelope<Food>>(`/foods/${action.food_id}`);
            setDialog({ open: true, mealType: action.meal_type, preset: { kind: "food", food: response.data } });
          } else if (action.recipe_id) {
            const response = await apiGet<DataEnvelope<Recipe>>(`/recipes/${action.recipe_id}`);
            setDialog({
              open: true,
              mealType: action.meal_type,
              preset: { kind: "recipe", recipe: response.data },
            });
          } else {
            setDialog({ open: true, mealType: action.meal_type, preset: null });
          }
        } catch (error) {
          toastError("Impossible d’ouvrir cette suggestion", getErrorMessage(error));
        } finally {
          setPendingKey(null);
        }
        return;
      }
      case "ouvrir_recette":
        router.push(`/dashboard/recettes?recette=${action.recipe_id}`);
        return;
      case "ouvrir_stock":
      case "supprimer_stock":
        router.push("/dashboard/stock");
        return;
      case "generer_seance":
        router.push("/dashboard/sport?generer=1");
        return;
      case "ajouter_courses":
        router.push("/dashboard/liste-course");
        return;
      case "ouvrir_planificateur":
        router.push(`/dashboard/planificateur-semaine?semaine=${action.date}`);
        return;
      default:
        return;
    }
  }

  const greeting = (
    <SectionHeader
      level="page"
      eyebrow="Accueil"
      tone="emerald"
      title={firstName ? `Bonjour ${firstName}` : "Bonjour"}
      subtitle={capitalize(formatDay(date))}
    />
  );

  if (query.isPending) {
    return (
      <div className="space-y-6">
        {greeting}
        <div className="grid gap-4 lg:grid-cols-[1.15fr_0.85fr]">
          <div className="space-y-4">
            <SkeletonCard lines={4} />
            <SkeletonCard lines={2} />
          </div>
          <div className="space-y-4">
            <SkeletonCard lines={4} />
            <SkeletonCard lines={3} />
            <SkeletonCard lines={3} />
          </div>
        </div>
        <Skeleton className="h-4 w-48" />
      </div>
    );
  }

  if (query.isError || !dashboard) {
    return (
      <div className="space-y-6">
        {greeting}
        <ErrorState
          message={getErrorMessage(query.error)}
          onRetry={() => void query.refetch()}
          retrying={query.isFetching}
        />
      </div>
    );
  }

  if (!dashboard.has_profile) {
    return (
      <div className="space-y-6">
        {greeting}
        <Card tone="rose" className="text-center">
          <h2 className="text-xl font-semibold tracking-tight text-rose-950">
            Complète ton profil pour obtenir tes objectifs personnalisés.
          </h2>
          <p className="mx-auto mt-2 max-w-xl text-sm leading-6 text-rose-900/80">
            Quelques informations (taille, poids, objectif, niveau d’activité) suffisent pour calculer
            tes calories et tes macros, puis suivre ta progression au quotidien.
          </p>
          <Link
            href="/dashboard/profil"
            className="mt-6 inline-flex h-12 items-center rounded-2xl border border-emerald-700 bg-emerald-700 px-5 text-base font-medium text-white transition hover:bg-emerald-800"
          >
            Compléter mon profil
          </Link>
        </Card>
      </div>
    );
  }

  const nextMeal = dashboard.meals.find((meal) => meal.type === dashboard.next_meal_type) ?? null;

  return (
    <div className="space-y-6">
      {greeting}

      <div className="grid items-start gap-4 lg:grid-cols-[1.15fr_0.85fr]">
        <div className="space-y-4">
          <BudgetCard
            targets={dashboard.targets}
            consumed={dashboard.consumed}
            remaining={dashboard.remaining}
            caloriesBonus={dashboard.calories_bonus}
            progressPct={dashboard.progress_pct}
            isEstimate={dashboard.is_estimate}
            href="/dashboard/historique-repas-journee"
          />
          <NextMealCard
            mealType={dashboard.next_meal_type}
            meal={nextMeal}
            onAdd={() => setDialog({ open: true, mealType: dashboard.next_meal_type, preset: null })}
          />
        </div>

        <div className="space-y-4">
          <CoachCard
            recommendations={dashboard.recommendations ?? []}
            onAction={handleRecommendationAction}
            pendingKey={pendingKey}
          />
          <StockAlertCard stock={dashboard.stock} />
          <SportCard sport={dashboard.sport} />
          <WeightCard weight={dashboard.weight} date={dashboard.date} />
        </div>
      </div>

      <AddToMealDialog
        open={dialog.open}
        onClose={() => setDialog({ open: false, preset: null })}
        date={dashboard.date}
        mealType={dialog.mealType}
        preset={dialog.preset}
        mode={dialog.preset ? "portionOnly" : "search"}
      />
    </div>
  );
}
