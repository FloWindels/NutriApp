"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { GenerateWeekModal } from "@/components/planner/generate-week-modal";
import { LogPlanModal } from "@/components/planner/log-plan-modal";
import { PlanSlotModal } from "@/components/planner/plan-slot-modal";
import { WeekGrid } from "@/components/planner/week-grid";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { SectionHeader } from "@/components/ui/section-header";
import { Skeleton, SkeletonCard } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { apiDelete, apiGet, apiPost, getErrorMessage } from "@/lib/api-client";
import { addDays, formatDay, startOfWeekMonday, todayIso } from "@/lib/format";
import { MEAL_MUTATION_KEYS, queryKeys } from "@/lib/query-keys";
import type {
  DataEnvelope,
  MealPlan,
  MealPlanInput,
  MealType,
  Planner,
  ShoppingGenerateResponse,
} from "@/lib/types/api";

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
const ROUTE = "/dashboard/planificateur-semaine";

/** « Planificateur de la semaine » (brief §12 & §16.4). */
export default function PlannerPage() {
  return (
    <Suspense fallback={<PlannerFallback />}>
      <PlannerContent />
    </Suspense>
  );
}

function PageHeader({ subtitle }: { subtitle?: string }) {
  return (
    <SectionHeader
      level="page"
      eyebrow="Planification"
      tone="orange"
      title="Planificateur de la semaine"
      subtitle={subtitle ?? "Compose tes repas de la semaine, puis envoie les ingrédients aux courses."}
    />
  );
}

function PlannerFallback() {
  return (
    <div className="space-y-6">
      <PageHeader />
      <Skeleton className="h-12 w-full max-w-lg" />
      <SkeletonCard lines={6} />
    </div>
  );
}

function PlannerContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const queryClient = useQueryClient();
  const { toast, success, error: toastError } = useToast();

  const rawDate = searchParams.get("date") ?? searchParams.get("semaine");
  const weekStart = startOfWeekMonday(rawDate && ISO_DATE.test(rawDate) ? rawDate : todayIso());
  const currentWeek = startOfWeekMonday(todayIso());

  const [slotModal, setSlotModal] = useState<{
    open: boolean;
    date: string;
    mealType: MealType;
    plan: MealPlan | null;
  }>({ open: false, date: weekStart, mealType: "dejeuner", plan: null });
  const [logTarget, setLogTarget] = useState<MealPlan | null>(null);
  const [logOpen, setLogOpen] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<MealPlan | null>(null);
  const [generateOpen, setGenerateOpen] = useState(false);

  const query = useQuery({
    queryKey: queryKeys.planner.week(weekStart),
    queryFn: () => apiGet<DataEnvelope<Planner>>("/planner", { week_start: weekStart }),
  });

  function goToWeek(date: string) {
    router.replace(date === currentWeek ? ROUTE : `${ROUTE}?date=${date}`, { scroll: false });
  }

  function invalidatePlanner() {
    return queryClient.invalidateQueries({ queryKey: queryKeys.planner.all });
  }

  const sendToShoppingList = useMutation({
    mutationFn: () =>
      apiPost<ShoppingGenerateResponse>("/shopping-list/generate", { week_start: weekStart }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.shopping.all });
      const count = response.added_count ?? 0;
      toast({
        title:
          count === 0
            ? "Rien à ajouter"
            : count > 1
              ? `${count} articles ajoutés à ta liste`
              : "1 article ajouté à ta liste",
        description:
          count === 0 ? "Tout est déjà dans ta liste ou dans ton stock." : undefined,
        tone: count === 0 ? "neutral" : "success",
        action: { label: "Voir la liste", onClick: () => router.push("/dashboard/liste-course") },
      });
    },
    onError: (error) => toastError("Envoi impossible", getErrorMessage(error)),
  });

  const deletePlan = useMutation({
    mutationFn: (plan: MealPlan) => apiDelete(`/planner/${plan.id}`),
    onSuccess: async (_response, plan) => {
      await invalidatePlanner();
      toast({
        title: "Repas retiré du planning",
        description: plan.title,
        action: { label: "Annuler", onClick: () => restorePlan(plan) },
      });
    },
    // Failures surface inline in the confirmation dialog (which stays open).
  });

  async function restorePlan(plan: MealPlan) {
    const payload: MealPlanInput = {
      date: plan.date,
      meal_type: plan.meal_type,
      servings: plan.servings,
      notes: plan.notes,
      ...(plan.recipe_id ? { recipe_id: plan.recipe_id } : { title: plan.title }),
    };
    try {
      await apiPost<DataEnvelope<MealPlan>>("/planner", payload);
      await invalidatePlanner();
    } catch (error) {
      toastError("Restauration impossible", getErrorMessage(error));
    }
  }

  const planner = query.data?.data;
  const subtitle = `Semaine du ${formatDay(weekStart)}`;
  const isEmpty =
    planner?.days.every((day) =>
      Object.values(day.slots ?? {}).every((plans) => (plans ?? []).length === 0),
    ) ?? false;

  const toolbar = (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div className="flex items-center gap-2">
        <Button
          variant="secondary"
          onClick={() => goToWeek(addDays(weekStart, -7))}
          aria-label="Semaine précédente"
          className="w-11 px-0"
        >
          <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
            <path d="M12.5 4L6.5 10L12.5 16" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </Button>
        <p className="min-w-[10rem] text-center text-sm font-semibold text-slate-900">
          Semaine du {formatDay(weekStart)}
        </p>
        <Button
          variant="secondary"
          onClick={() => goToWeek(addDays(weekStart, 7))}
          aria-label="Semaine suivante"
          className="w-11 px-0"
        >
          <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
            <path d="M7.5 4L13.5 10L7.5 16" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </Button>
        {weekStart !== currentWeek ? (
          <Button variant="ghost" onClick={() => goToWeek(currentWeek)}>
            Cette semaine
          </Button>
        ) : null}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <Button variant="secondary" onClick={() => setGenerateOpen(true)}>
          Générer la semaine
        </Button>
        <Button
          onClick={() => sendToShoppingList.mutate()}
          loading={sendToShoppingList.isPending}
        >
          Envoyer à la liste de courses
        </Button>
      </div>
    </div>
  );

  if (query.isPending) {
    return (
      <div className="space-y-6">
        <PageHeader subtitle={subtitle} />
        {toolbar}
        <SkeletonCard lines={6} />
        <SkeletonCard lines={6} />
      </div>
    );
  }

  if (query.isError || !planner) {
    return (
      <div className="space-y-6">
        <PageHeader subtitle={subtitle} />
        {toolbar}
        <ErrorState
          message={getErrorMessage(query.error)}
          onRetry={() => void query.refetch()}
          retrying={query.isFetching}
        />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader subtitle={subtitle} />
      {toolbar}

      {isEmpty ? (
        <EmptyState
          compact
          title="Aucun repas prévu cette semaine"
          message="Ajoute un repas dans un créneau ci-dessous ou laisse Mavi’oh composer ta semaine."
          action={<Button onClick={() => setGenerateOpen(true)}>Générer la semaine</Button>}
        />
      ) : null}

      <WeekGrid
        planner={planner}
        onAdd={(date, mealType) => setSlotModal({ open: true, date, mealType, plan: null })}
        onEdit={(plan) =>
          setSlotModal({ open: true, date: plan.date, mealType: plan.meal_type, plan })
        }
        onLog={(plan) => {
          setLogTarget(plan);
          setLogOpen(true);
        }}
        onDelete={(plan) => setDeleteTarget(plan)}
      />

      <p className="text-xs text-slate-500">
        Les calories des repas prévus sont des estimations issues des recettes, pas une mesure.
      </p>

      <PlanSlotModal
        open={slotModal.open}
        onClose={() => setSlotModal((current) => ({ ...current, open: false }))}
        date={slotModal.date}
        mealType={slotModal.mealType}
        plan={slotModal.plan}
        onSaved={async (mode) => {
          await invalidatePlanner();
          success(mode === "create" ? "Repas ajouté au planning" : "Planning mis à jour");
        }}
      />

      <LogPlanModal
        open={logOpen}
        onClose={() => setLogOpen(false)}
        plan={logTarget}
        onLogged={async (plan, decrementStock) => {
          await Promise.all([
            invalidatePlanner(),
            ...MEAL_MUTATION_KEYS.map((queryKey) => queryClient.invalidateQueries({ queryKey })),
            queryClient.invalidateQueries({ queryKey: queryKeys.stocks.all }),
          ]);
          success(
            "Repas enregistré",
            decrementStock ? `${plan.title} · stock mis à jour` : plan.title,
          );
        }}
      />

      <GenerateWeekModal
        open={generateOpen}
        onClose={() => setGenerateOpen(false)}
        weekStart={weekStart}
        onGenerated={async (count) => {
          await invalidatePlanner();
          if (count === 0) {
            toast({
              title: "Rien à générer",
              description: "Les créneaux choisis sont déjà remplis ou aucune recette ne convient.",
            });
          } else {
            success(count > 1 ? `${count} repas planifiés` : "1 repas planifié");
          }
        }}
      />

      <ConfirmDialog
        open={deleteTarget !== null}
        onClose={() => setDeleteTarget(null)}
        danger
        title="Supprimer du planning ?"
        message={
          deleteTarget
            ? `« ${deleteTarget.title} » sera retiré de ta semaine. Les repas déjà enregistrés ne changent pas.`
            : undefined
        }
        confirmLabel="Supprimer"
        onConfirm={async () => {
          if (deleteTarget) await deletePlan.mutateAsync(deleteTarget);
        }}
      />
    </div>
  );
}
