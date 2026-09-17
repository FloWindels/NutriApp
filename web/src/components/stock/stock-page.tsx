"use client";

import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiDelete, apiGet, apiPost, apiPut, getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { queryKeys } from "@/lib/query-keys";
import type {
  DataEnvelope,
  StockItem,
  StockItemUpdateInput,
  StockLocation,
  StocksResponse,
} from "@/lib/types/api";
import { usePortions } from "@/hooks/use-portions";
import { AddToMealDialog } from "@/components/ui/add-to-meal-dialog";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Modal } from "@/components/ui/modal";
import { SectionHeader } from "@/components/ui/section-header";
import { SkeletonList } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { AddStockItemDialog } from "./add-stock-item-dialog";
import { LocationForm } from "./location-form";
import { StockItemRow } from "./stock-item-row";

type AlertFilter = "expiring" | "expired" | "low";

function matchesFilter(item: StockItem, filter: AlertFilter): boolean {
  if (filter === "expired") return item.expiry_status === "perime" || item.expiry_status === "ddm_depassee";
  if (filter === "expiring") return item.expiry_status === "bientot" || item.expiry_status === "aujourdhui";
  return item.is_depleted || item.quantity <= 0 || (item.min_quantity !== null && item.quantity <= item.min_quantity);
}

/**
 * « Stock » (brief §6 et §17) : alertes cliquables, lieux avec compteurs,
 * édition en place, consommation vers un repas et ajout d’articles.
 */
export function StockPage() {
  const queryClient = useQueryClient();
  const { success } = useToast();
  const { portions } = usePortions();

  const [includeDepleted, setIncludeDepleted] = useState(false);
  const [alertFilter, setAlertFilter] = useState<AlertFilter | null>(null);
  const [locationId, setLocationId] = useState<number | null>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [addOpen, setAddOpen] = useState(false);
  const [locationModal, setLocationModal] = useState<{ mode: "create" | "rename"; location: StockLocation | null } | null>(
    null,
  );
  const [locationToDelete, setLocationToDelete] = useState<StockLocation | null>(null);
  const [itemToDelete, setItemToDelete] = useState<StockItem | null>(null);
  const [itemToConsume, setItemToConsume] = useState<StockItem | null>(null);

  const stocksQuery = useQuery({
    queryKey: queryKeys.stocks.list({ include_depleted: includeDepleted }),
    queryFn: () => apiGet<StocksResponse>("/stocks", includeDepleted ? { include_depleted: 1 } : undefined),
  });

  const locations = useMemo(() => stocksQuery.data?.locations ?? [], [stocksQuery.data]);
  const alerts = stocksQuery.data?.alerts;
  const householdId = stocksQuery.data?.household_id ?? null;

  const items = useMemo(() => {
    const all = stocksQuery.data?.data ?? [];
    return all.filter((item) => {
      if (locationId !== null && item.stock_id !== locationId) return false;
      if (alertFilter !== null && !matchesFilter(item, alertFilter)) return false;
      return true;
    });
  }, [stocksQuery.data, locationId, alertFilter]);

  const invalidate = () => queryClient.invalidateQueries({ queryKey: queryKeys.stocks.all });

  const updateItemMutation = useMutation({
    mutationFn: ({ id, input }: { id: number; input: StockItemUpdateInput }) =>
      apiPut<DataEnvelope<StockItem>>(`/stocks/items/${id}`, input),
    onSuccess: async (response) => {
      await invalidate();
      setEditingId(null);
      success("Article mis à jour", response.data.food_name);
    },
  });

  const deleteItemMutation = useMutation({
    mutationFn: (id: number) => apiDelete(`/stocks/items/${id}`),
    onSuccess: async () => {
      await invalidate();
      success("Article supprimé");
    },
  });

  const deleteLocationMutation = useMutation({
    mutationFn: (id: number) => apiDelete(`/stocks/${id}`),
    onSuccess: async () => {
      await invalidate();
      setLocationId(null);
      success("Lieu supprimé");
    },
  });

  async function submitLocation(name: string) {
    const target = locationModal?.location ?? null;
    if (locationModal?.mode === "rename" && target) {
      await apiPut<DataEnvelope<StockLocation>>(`/stocks/${target.id}`, { name });
    } else {
      const response = await apiPost<DataEnvelope<StockLocation>>("/stocks", { name });
      setLocationId(response.data.id);
    }
    await invalidate();
    setLocationModal(null);
    success(locationModal?.mode === "rename" ? "Lieu renommé" : "Lieu créé", name);
  }

  const selectedLocation = locations.find((location) => location.id === locationId) ?? null;
  const mutationError = updateItemMutation.error ?? deleteItemMutation.error ?? null;

  const chips: { key: AlertFilter; label: string; count: number; className: string }[] = [
    {
      key: "expiring",
      label: "Bientôt périmés",
      count: alerts?.expiring_count ?? 0,
      className: "border-amber-200 bg-amber-50 text-amber-900",
    },
    {
      key: "expired",
      label: "Périmés",
      count: alerts?.expired_count ?? 0,
      className: "border-rose-200 bg-rose-50 text-rose-800",
    },
    {
      key: "low",
      label: "Stock bas",
      count: alerts?.low_count ?? 0,
      className: "border-slate-200 bg-slate-50 text-slate-700",
    },
  ];

  return (
    <div className="space-y-6">
      <SectionHeader
        level="page"
        tone="orange"
        eyebrow="Planification"
        title="Stock"
        subtitle="Ce que tu as sous la main, par lieu, avec les dates de péremption et les quantités restantes."
        actions={
          <>
            <Button variant="secondary" onClick={() => setLocationModal({ mode: "create", location: null })}>
              Nouveau lieu
            </Button>
            <Button onClick={() => setAddOpen(true)} disabled={locations.length === 0}>
              Ajouter un article
            </Button>
          </>
        }
      />

      {householdId !== null ? (
        <Banner tone="info" title="Stock partagé">
          Ce stock est celui de ton foyer : tes ajouts et tes retraits sont visibles par tous ses membres.
        </Banner>
      ) : null}

      {mutationError ? <Banner tone="error">{getErrorMessage(mutationError)}</Banner> : null}

      <div className="flex flex-wrap items-center gap-2">
        {chips.map((chip) => {
          const active = alertFilter === chip.key;
          return (
            <button
              key={chip.key}
              type="button"
              aria-pressed={active}
              onClick={() => setAlertFilter(active ? null : chip.key)}
              className={cn(
                "inline-flex h-10 items-center gap-2 rounded-full border px-4 text-sm font-medium transition",
                active ? "border-slate-900 bg-slate-900 text-white" : chip.className,
              )}
            >
              {chip.label}
              <span className="rounded-full bg-white/70 px-2 py-0.5 text-xs font-semibold tabular-nums text-slate-800">
                {chip.count}
              </span>
            </button>
          );
        })}
        <label className="ml-auto inline-flex h-10 cursor-pointer items-center gap-2 rounded-full border border-slate-200 bg-white px-4 text-sm text-slate-700">
          <input
            type="checkbox"
            checked={includeDepleted}
            onChange={(event) => setIncludeDepleted(event.target.checked)}
            className="h-5 w-5 rounded-md border-slate-300 accent-emerald-700"
          />
          Afficher les épuisés
        </label>
      </div>

      <div className="flex flex-wrap items-center gap-2" role="tablist" aria-label="Lieux de stockage">
        <button
          type="button"
          role="tab"
          aria-selected={locationId === null}
          onClick={() => setLocationId(null)}
          className={cn(
            "h-10 rounded-full border px-4 text-sm font-medium transition",
            locationId === null
              ? "border-emerald-700 bg-emerald-700 text-white"
              : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50",
          )}
        >
          Tous les lieux
        </button>
        {locations.map((location) => (
          <button
            key={location.id}
            type="button"
            role="tab"
            aria-selected={locationId === location.id}
            onClick={() => setLocationId(location.id)}
            className={cn(
              "h-10 rounded-full border px-4 text-sm font-medium transition",
              locationId === location.id
                ? "border-emerald-700 bg-emerald-700 text-white"
                : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50",
            )}
          >
            {location.name}
            {typeof location.items_count === "number" ? (
              <span className="ml-2 text-xs tabular-nums opacity-80">{location.items_count}</span>
            ) : null}
          </button>
        ))}
        {selectedLocation ? (
          <span className="flex items-center gap-1">
            <Button
              size="md"
              variant="ghost"
              onClick={() => setLocationModal({ mode: "rename", location: selectedLocation })}
            >
              Renommer
            </Button>
            <Button size="md" variant="ghost" onClick={() => setLocationToDelete(selectedLocation)}>
              Supprimer le lieu
            </Button>
          </span>
        ) : null}
      </div>

      <Card padding="sm">
        {stocksQuery.isPending ? (
          <SkeletonList rows={5} />
        ) : stocksQuery.isError ? (
          <ErrorState
            message={getErrorMessage(stocksQuery.error)}
            onRetry={() => void stocksQuery.refetch()}
            retrying={stocksQuery.isFetching}
          />
        ) : items.length === 0 ? (
          <EmptyState
            title={
              alertFilter !== null || locationId !== null ? "Rien à afficher avec ce filtre" : "Ton stock est vide"
            }
            message={
              alertFilter !== null || locationId !== null
                ? "Enlève le filtre ou choisis un autre lieu pour voir le reste de ton stock."
                : "Ajoute ce que tu as dans le frigo, le congélateur ou le placard : Mavi’oh te préviendra avant les dates limites."
            }
            action={
              locations.length === 0 ? (
                <Button onClick={() => setLocationModal({ mode: "create", location: null })}>Créer un lieu</Button>
              ) : (
                <Button onClick={() => setAddOpen(true)}>Ajouter un article</Button>
              )
            }
          />
        ) : (
          <ul className="space-y-2">
            {items.map((item) => (
              <StockItemRow
                key={item.id}
                item={item}
                locations={locations}
                portions={portions}
                showLocation={locationId === null}
                editing={editingId === item.id}
                saving={updateItemMutation.isPending && updateItemMutation.variables?.id === item.id}
                onToggleEdit={() => setEditingId((current) => (current === item.id ? null : item.id))}
                onCancelEdit={() => setEditingId(null)}
                onSave={(input) => updateItemMutation.mutate({ id: item.id, input })}
                onDelete={() => setItemToDelete(item)}
                onConsume={() => setItemToConsume(item)}
              />
            ))}
          </ul>
        )}
      </Card>

      <AddStockItemDialog
        open={addOpen}
        onClose={() => setAddOpen(false)}
        locations={locations}
        defaultStockId={locationId ?? locations[0]?.id ?? null}
      />

      <Modal
        open={locationModal !== null}
        onClose={() => setLocationModal(null)}
        title={locationModal?.mode === "rename" ? "Renommer le lieu" : "Nouveau lieu"}
        size="sm"
      >
        {locationModal ? (
          <LocationForm
            mode={locationModal.mode}
            initialName={locationModal.location?.name ?? ""}
            submit={submitLocation}
            onCancel={() => setLocationModal(null)}
          />
        ) : null}
      </Modal>

      <ConfirmDialog
        open={locationToDelete !== null}
        onClose={() => setLocationToDelete(null)}
        title="Supprimer ce lieu ?"
        message={
          locationToDelete
            ? `« ${locationToDelete.name} » disparaîtra de ton stock. Un lieu doit être vide avant d’être supprimé.`
            : undefined
        }
        confirmLabel="Supprimer"
        danger
        onConfirm={async () => {
          if (locationToDelete) await deleteLocationMutation.mutateAsync(locationToDelete.id);
        }}
      />

      <ConfirmDialog
        open={itemToDelete !== null}
        onClose={() => setItemToDelete(null)}
        title="Supprimer cet article ?"
        message={itemToDelete ? `« ${itemToDelete.food_name} » sera retiré de ton stock.` : undefined}
        confirmLabel="Supprimer"
        danger
        onConfirm={async () => {
          if (itemToDelete) await deleteItemMutation.mutateAsync(itemToDelete.id);
        }}
      />

      <AddToMealDialog
        open={itemToConsume !== null}
        onClose={() => setItemToConsume(null)}
        preset={itemToConsume ? { kind: "stock", item: itemToConsume } : null}
        mode="portionOnly"
      />
    </div>
  );
}

export default StockPage;
