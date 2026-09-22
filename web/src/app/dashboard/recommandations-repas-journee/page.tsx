"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { RecommendationCard } from "@/components/coach/recommendation-card";
import { AddToMealDialog, type AddToMealPreset } from "@/components/ui/add-to-meal-dialog";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { DateStrip } from "@/components/ui/date-strip";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { SectionHeader } from "@/components/ui/section-header";
import { SkeletonCard } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { apiDelete, apiGet, apiPost, apiPut, getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { todayIso } from "@/lib/format";
import { messages } from "@/lib/messages";
import { queryKeys } from "@/lib/query-keys";
import type {
  DataEnvelope,
  Food,
  ListEnvelope,
  MealType,
  MessageEnvelope,
  Recipe,
  Recommendation,
  RecommendationAction,
  RecommendationStatus,
  ShoppingItem,
} from "@/lib/types/api";

const ROUTE = "/dashboard/recommandations-repas-journee";
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

/** « Coach du jour » — the recommendations of a day (brief §8 & §16.4). */
export default function CoachPage() {
  return (
    <Suspense fallback={<CoachPageFallback />}>
      <CoachPageContent />
    </Suspense>
  );
}

function CoachHeader() {
  return (
    <SectionHeader
      level="page"
      eyebrow="Nutrition"
      tone="teal"
      title="Coach du jour"
      subtitle="Les conseils calculés à partir de tes repas, de ton stock et de tes séances."
    />
  );
}

function CoachPageFallback() {
  return (
    <div className="space-y-6">
      <CoachHeader />
      <SkeletonCard lines={3} />
    </div>
  );
}

function CoachPageContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const queryClient = useQueryClient();
  const { toast, success, error: toastError } = useToast();

  const rawDate = searchParams.get("date");
  const date = rawDate && ISO_DATE.test(rawDate) ? rawDate : todayIso();
  const showIgnored = searchParams.get("ignorees") === "1";

  const [dialog, setDialog] = useState<{
    open: boolean;
    mealType?: MealType;
    preset: AddToMealPreset | null;
  }>({ open: false, preset: null });
  const [pendingKey, setPendingKey] = useState<string | null>(null);
  const [confirmStockId, setConfirmStockId] = useState<number | null>(null);

  const query = useQuery({
    queryKey: queryKeys.recommendations.day(date, showIgnored),
    queryFn: () =>
      apiGet<ListEnvelope<Recommendation>>("/recommendations", {
        date,
        ...(showIgnored ? { all: 1 } : {}),
      }),
  });

  const statusMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: RecommendationStatus }) =>
      apiPut<DataEnvelope<Recommendation>>(`/recommendations/${id}`, { status }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.recommendations.all }),
  });

  const statusPendingId = statusMutation.isPending ? statusMutation.variables?.id ?? null : null;

  function navigate(next: { date?: string; ignored?: boolean }) {
    const params = new URLSearchParams();
    const nextDate = next.date ?? date;
    const nextIgnored = next.ignored ?? showIgnored;
    if (nextDate !== todayIso()) params.set("date", nextDate);
    if (nextIgnored) params.set("ignorees", "1");
    const queryString = params.toString();
    router.replace(queryString ? `${ROUTE}?${queryString}` : ROUTE, { scroll: false });
  }

  async function setStatus(recommendation: Recommendation, status: RecommendationStatus) {
    await statusMutation.mutateAsync({ id: recommendation.id, status });
  }

  async function handleIgnore(recommendation: Recommendation) {
    try {
      await setStatus(recommendation, "ignoree");
      toast({
        title: "Conseil ignoré.",
        description: recommendation.title,
        action: { label: messages.undo, onClick: () => handleRestore(recommendation) },
      });
    } catch (error) {
      toastError("Impossible d’ignorer ce conseil", getErrorMessage(error));
    }
  }

  async function handleRestore(recommendation: Recommendation) {
    try {
      await setStatus(recommendation, "new");
      success("Conseil rétabli.", recommendation.title);
    } catch (error) {
      toastError("Impossible de rétablir ce conseil", getErrorMessage(error));
    }
  }

  async function handleAction(action: RecommendationAction, key: string) {
    switch (action.kind) {
      case "ajouter_au_repas": {
        setPendingKey(key);
        try {
          if (action.food_id) {
            const response = await apiGet<DataEnvelope<Food>>(`/foods/${action.food_id}`);
            setDialog({
              open: true,
              mealType: action.meal_type,
              preset: { kind: "food", food: response.data },
            });
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
        router.push("/dashboard/stock");
        return;

      case "generer_seance":
        router.push("/dashboard/sport?generer=1");
        return;

      case "ajouter_courses": {
        setPendingKey(key);
        const label = (action.label ?? "").trim();
        try {
          await apiPost<DataEnvelope<ShoppingItem>>("/shopping-list/items", {
            label,
            quantity: action.quantity ?? null,
            unit: action.unit ?? null,
          });
          await queryClient.invalidateQueries({ queryKey: queryKeys.shopping.all });
          toast({
            tone: "success",
            title: `${label} ajouté à ta liste de courses.`,
            action: { label: "Voir la liste", onClick: () => router.push("/dashboard/liste-course") },
          });
        } catch (error) {
          toastError("Ajout impossible", getErrorMessage(error));
        } finally {
          setPendingKey(null);
        }
        return;
      }

      case "ouvrir_planificateur":
        router.push(`/dashboard/planificateur-semaine?date=${action.date}`);
        return;

      case "supprimer_stock":
        setConfirmStockId(action.stock_item_id);
        return;

      default:
        return;
    }
  }

  async function deleteStockItem() {
    if (confirmStockId === null) return;
    await apiDelete<MessageEnvelope>(`/stocks/items/${confirmStockId}`);
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: queryKeys.stocks.all }),
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all }),
      queryClient.invalidateQueries({ queryKey: queryKeys.recommendations.all }),
    ]);
    success("Article retiré de ton stock.");
  }

  const recommendations = query.data?.data ?? [];

  const controls = (
    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
      <DateStrip value={date} onChange={(next) => navigate({ date: next })} className="lg:max-w-sm lg:flex-1" />

      <button
        type="button"
        role="switch"
        aria-checked={showIgnored}
        onClick={() => navigate({ ignored: !showIgnored })}
        className="inline-flex h-11 items-center gap-3 self-start rounded-2xl border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400/40"
      >
        <span
          aria-hidden="true"
          className={cn(
            "relative h-6 w-11 shrink-0 rounded-full transition",
            showIgnored ? "bg-emerald-600" : "bg-slate-300",
          )}
        >
          <span
            className={cn(
              "absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all",
              showIgnored ? "left-[1.375rem]" : "left-0.5",
            )}
          />
        </span>
        Afficher les ignorées
      </button>
    </div>
  );

  return (
    <div className="space-y-6">
      <CoachHeader />
      {controls}

      {query.isPending ? (
        <div className="space-y-4">
          <SkeletonCard lines={3} />
          <SkeletonCard lines={3} />
          <SkeletonCard lines={2} />
        </div>
      ) : query.isError ? (
        <ErrorState
          message={getErrorMessage(query.error)}
          onRetry={() => void query.refetch()}
          retrying={query.isFetching}
        />
      ) : recommendations.length === 0 ? (
        <EmptyState
          title="Ton coach n’a rien à signaler aujourd’hui"
          message={
            showIgnored
              ? "Aucun conseil pour cette journée. Enregistre tes repas pour que le coach puisse t’aider."
              : "Enregistre tes repas de la journée pour que le coach puisse t’aider."
          }
          action={<Button onClick={() => setDialog({ open: true, preset: null })}>Enregistrer un repas</Button>}
        />
      ) : (
        <ul className="space-y-4">
          {recommendations.map((recommendation) => (
            <li key={recommendation.id}>
              <RecommendationCard
                recommendation={recommendation}
                onAction={handleAction}
                onIgnore={handleIgnore}
                onRestore={handleRestore}
                pendingKey={pendingKey}
                statusPending={statusPendingId === recommendation.id}
              />
            </li>
          ))}
        </ul>
      )}

      <p className="text-center text-xs leading-5 text-slate-500">{messages.nutritionDisclaimer}</p>

      <AddToMealDialog
        open={dialog.open}
        onClose={() => setDialog({ open: false, preset: null })}
        date={date}
        mealType={dialog.mealType}
        preset={dialog.preset}
        mode={dialog.preset ? "portionOnly" : "search"}
        onAdded={() => void query.refetch()}
      />

      <ConfirmDialog
        open={confirmStockId !== null}
        onClose={() => setConfirmStockId(null)}
        title="Retirer du stock ?"
        message="L’article sera supprimé de ton stock. Cette action est définitive."
        confirmLabel={messages.delete}
        danger
        onConfirm={deleteStockItem}
      />
    </div>
  );
}
