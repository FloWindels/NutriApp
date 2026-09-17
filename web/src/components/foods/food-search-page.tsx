"use client";

import { useMemo, useState } from "react";
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiDelete, apiGet, apiPost, getErrorMessage, isApiError } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { queryKeys } from "@/lib/query-keys";
import type { DataEnvelope, Food, ListEnvelope, PaginatedEnvelope } from "@/lib/types/api";
import { AddToMealDialog } from "@/components/ui/add-to-meal-dialog";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Modal } from "@/components/ui/modal";
import { SectionHeader } from "@/components/ui/section-header";
import { Skeleton } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { AddToStockDialog } from "./add-to-stock-dialog";
import { FoodCard } from "./food-card";
import { FoodDetailPanel } from "./food-detail-panel";
import { FoodForm } from "./food-form";
import { isBarcode } from "./food-utils";
import { useDebounced } from "./hooks";

type Tab = "recherche" | "favoris";

const PER_PAGE = 24;

/**
 * « Recherche d’aliments » (brief §3 et §17) : une seule barre pour le nom, la
 * marque et le code-barres (8 à 14 chiffres → `/foods/barcode/{ean}`), un onglet
 * favoris, une fiche détaillée et la création d’un aliment. Open Food Facts est
 * interrogé **par le serveur** (`off=1`), jamais depuis le navigateur.
 */
export function FoodSearchPage() {
  const queryClient = useQueryClient();
  const { toast, error: toastError } = useToast();

  const [tab, setTab] = useState<Tab>("recherche");
  const [term, setTerm] = useState("");
  const debouncedTerm = useDebounced(term.trim(), 350);
  const [selected, setSelected] = useState<Food | null>(null);
  const [favoriteOverrides, setFavoriteOverrides] = useState<Record<number, boolean>>({});
  const [createOpen, setCreateOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [mealDialogOpen, setMealDialogOpen] = useState(false);
  const [stockDialogOpen, setStockDialogOpen] = useState(false);

  const barcode = isBarcode(debouncedTerm) ? debouncedTerm : null;
  const searchEnabled = tab === "recherche" && !barcode && debouncedTerm.length >= 2;

  const searchQuery = useInfiniteQuery({
    queryKey: queryKeys.foods.search({ q: debouncedTerm }),
    enabled: searchEnabled,
    initialPageParam: 1,
    queryFn: ({ pageParam }) =>
      apiGet<PaginatedEnvelope<Food>>("/foods/search", {
        q: debouncedTerm,
        page: pageParam,
        per_page: PER_PAGE,
        off: 1,
      }),
    getNextPageParam: (lastPage) => {
      const meta = lastPage.meta;
      if (!meta || meta.current_page >= meta.last_page) return undefined;
      return meta.current_page + 1;
    },
  });

  const barcodeQuery = useQuery({
    queryKey: queryKeys.foods.barcode(barcode ?? ""),
    enabled: tab === "recherche" && barcode !== null,
    queryFn: () => apiGet<DataEnvelope<Food>>(`/foods/barcode/${encodeURIComponent(barcode ?? "")}`),
    retry: (failureCount, error) => !(isApiError(error) && error.status < 500) && failureCount < 1,
  });

  const favoritesQuery = useQuery({
    queryKey: queryKeys.foods.favorites,
    enabled: tab === "favoris",
    queryFn: () => apiGet<ListEnvelope<Food>>("/foods/favorites"),
  });

  const favoriteMutation = useMutation({
    mutationFn: ({ id, next }: { id: number; next: boolean }) =>
      next ? apiPost(`/foods/${id}/favorite`) : apiDelete(`/foods/${id}/favorite`),
    onMutate: ({ id, next }) => {
      setFavoriteOverrides((current) => ({ ...current, [id]: next }));
    },
    onError: (error, { id, next }) => {
      setFavoriteOverrides((current) => ({ ...current, [id]: !next }));
      toastError("Favori non enregistré", getErrorMessage(error));
    },
    onSuccess: async (_data, { id }) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.foods.all });
      setFavoriteOverrides((current) => {
        const next = { ...current };
        delete next[id];
        return next;
      });
    },
  });

  const isFavorite = (food: Food) => favoriteOverrides[food.id] ?? food.is_favorite;

  const results = useMemo<Food[]>(() => {
    if (tab === "favoris") return favoritesQuery.data?.data ?? [];
    if (barcode) return barcodeQuery.data?.data ? [barcodeQuery.data.data] : [];
    return searchQuery.data?.pages.flatMap((page) => page.data) ?? [];
  }, [tab, barcode, favoritesQuery.data, barcodeQuery.data, searchQuery.data]);

  const offQueried = Boolean(searchQuery.data?.pages.some((page) => page.meta?.off_queried));
  const total = searchQuery.data?.pages[0]?.meta?.total ?? null;

  const isPending =
    tab === "favoris" ? favoritesQuery.isPending : barcode ? barcodeQuery.isPending : searchEnabled && searchQuery.isPending;
  const activeError = tab === "favoris" ? favoritesQuery.error : barcode ? barcodeQuery.error : searchQuery.error;
  const activeFetching =
    tab === "favoris" ? favoritesQuery.isFetching : barcode ? barcodeQuery.isFetching : searchQuery.isFetching;
  const isBarcodeMissing = Boolean(barcode) && isApiError(barcodeQuery.error) && barcodeQuery.error.status === 404;
  const isError = activeError !== null && activeError !== undefined && !isBarcodeMissing;

  function retryActive() {
    if (tab === "favoris") void favoritesQuery.refetch();
    else if (barcode) void barcodeQuery.refetch();
    else void searchQuery.refetch();
  }

  function handleSaved(food: Food, message?: string) {
    setCreateOpen(false);
    setEditOpen(false);
    setSelected(food);
    void queryClient.invalidateQueries({ queryKey: queryKeys.foods.all });
    if (message && message.toLowerCase().includes("déjà")) {
      toast({ title: "Produit déjà présent dans la base.", description: food.name, tone: "warning" });
      return;
    }
    toast({ title: message ?? "Aliment enregistré.", description: food.name, tone: "success" });
  }

  return (
    <div className="space-y-6">
      <SectionHeader
        level="page"
        tone="cyan"
        eyebrow="Nutrition"
        title="Recherche d’aliments"
        subtitle="Cherche par nom, par marque ou tape un code-barres. Les produits manquants sont complétés par Open Food Facts côté serveur."
        actions={
          <Button variant="secondary" onClick={() => setCreateOpen(true)}>
            Créer un aliment
          </Button>
        }
      />

      <div className="flex flex-wrap items-center gap-2" role="tablist" aria-label="Mode d’affichage">
        {([
          { key: "recherche", label: "Recherche" },
          { key: "favoris", label: "Favoris" },
        ] as const).map((entry) => (
          <button
            key={entry.key}
            type="button"
            role="tab"
            aria-selected={tab === entry.key}
            onClick={() => setTab(entry.key)}
            className={cn(
              "h-10 rounded-full border px-4 text-sm font-medium transition",
              tab === entry.key
                ? "border-emerald-700 bg-emerald-700 text-white"
                : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50",
            )}
          >
            {entry.label}
          </button>
        ))}
      </div>

      {tab === "recherche" ? (
        <div>
          <label htmlFor="food-search" className="mb-1.5 block text-sm font-medium text-slate-700">
            Nom, marque ou code-barres
          </label>
          <input
            id="food-search"
            type="search"
            value={term}
            onChange={(event) => setTerm(event.target.value)}
            placeholder="Ex. : yaourt nature, Bjorg, 3256540000000"
            autoComplete="off"
            className="h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-lime-600 focus:ring-4 focus:ring-lime-600/10"
          />
          <p className="mt-1.5 text-xs text-slate-500">
            {barcode
              ? `Recherche du code-barres ${barcode}…`
              : total !== null && searchEnabled
                ? `${total} aliment${total > 1 ? "s" : ""} dans la base pour « ${debouncedTerm} ».`
                : "Tape au moins 2 caractères, ou 8 à 14 chiffres pour un code-barres."}
          </p>
        </div>
      ) : null}

      {offQueried ? (
        <Banner tone="info" title="Résultats complétés par Open Food Facts">
          Ces fiches viennent de la base collaborative Open Food Facts (licence ODbL) : vérifie les valeurs avant de les
          utiliser.
        </Banner>
      ) : null}

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div className="min-w-0">
          {isPending ? (
            <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
              {Array.from({ length: 6 }).map((_, index) => (
                <Skeleton key={index} className="h-40 w-full rounded-[1.5rem]" />
              ))}
            </div>
          ) : isError ? (
            <ErrorState message={getErrorMessage(activeError)} onRetry={retryActive} retrying={activeFetching} />
          ) : results.length === 0 ? (
            tab === "favoris" ? (
              <EmptyState
                title="Aucun favori pour l’instant"
                message="Ajoute un aliment à tes favoris avec le cœur : tu le retrouveras ici en un clin d’œil."
                action={<Button onClick={() => setTab("recherche")}>Chercher un aliment</Button>}
              />
            ) : searchEnabled || barcode ? (
              <EmptyState
                title={barcode ? "Produit introuvable, même sur Open Food Facts." : "Aucun aliment ne correspond."}
                message="Tu peux créer cet aliment toi-même : le code-barres est facultatif."
                action={<Button onClick={() => setCreateOpen(true)}>Créer un aliment</Button>}
              />
            ) : (
              <EmptyState
                title="Cherche un aliment"
                message="Tape un nom, une marque ou un code-barres pour voir les calories et les macros pour 100 g."
                action={<Button onClick={() => setCreateOpen(true)}>Créer un aliment</Button>}
              />
            )
          ) : (
            <>
              <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                {results.map((food) => (
                  <FoodCard
                    key={food.id}
                    food={food}
                    favorite={isFavorite(food)}
                    selected={selected?.id === food.id}
                    onSelect={() => setSelected(food)}
                    onToggleFavorite={() => favoriteMutation.mutate({ id: food.id, next: !isFavorite(food) })}
                    favoriteBusy={favoriteMutation.isPending && favoriteMutation.variables?.id === food.id}
                  />
                ))}
              </div>
              {tab === "recherche" && !barcode && searchQuery.hasNextPage ? (
                <div className="mt-4 flex justify-center">
                  <Button
                    variant="secondary"
                    onClick={() => void searchQuery.fetchNextPage()}
                    loading={searchQuery.isFetchingNextPage}
                  >
                    Voir plus
                  </Button>
                </div>
              ) : null}
            </>
          )}
        </div>

        <aside className="min-w-0">
          {selected ? (
            <FoodDetailPanel
              food={selected}
              favorite={isFavorite(selected)}
              favoriteBusy={favoriteMutation.isPending && favoriteMutation.variables?.id === selected.id}
              onToggleFavorite={() => favoriteMutation.mutate({ id: selected.id, next: !isFavorite(selected) })}
              onAddToMeal={() => setMealDialogOpen(true)}
              onAddToStock={() => setStockDialogOpen(true)}
              onEdit={() => setEditOpen(true)}
            />
          ) : (
            <Card padding="md">
              <EmptyState
                compact
                title="Aucune fiche ouverte"
                message="Choisis un aliment pour voir ses valeurs détaillées, l’ajouter à un repas ou à ton stock."
              />
            </Card>
          )}
        </aside>
      </div>

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Créer un aliment" size="lg">
        <FoodForm
          mode="create"
          defaultBarcode={barcode}
          onSaved={handleSaved}
          onCancel={() => setCreateOpen(false)}
        />
      </Modal>

      <Modal open={editOpen && selected !== null} onClose={() => setEditOpen(false)} title="Modifier l’aliment" size="lg">
        {selected ? (
          <FoodForm mode="edit" food={selected} onSaved={handleSaved} onCancel={() => setEditOpen(false)} />
        ) : null}
      </Modal>

      <AddToMealDialog
        open={mealDialogOpen && selected !== null}
        onClose={() => setMealDialogOpen(false)}
        preset={selected ? { kind: "food", food: selected } : null}
        mode="portionOnly"
      />

      <AddToStockDialog open={stockDialogOpen && selected !== null} onClose={() => setStockDialogOpen(false)} food={selected} />
    </div>
  );
}

export default FoodSearchPage;
