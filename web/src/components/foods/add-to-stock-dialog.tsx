"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiGet, apiPost, getErrorMessage } from "@/lib/api-client";
import { queryKeys } from "@/lib/query-keys";
import type { DataEnvelope, ExpiryKind, Food, StockItem, StockItemInput, StocksResponse } from "@/lib/types/api";
import { usePortions } from "@/hooks/use-portions";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field, SelectField } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { QuantityUnitPicker, type QuantityUnitValue } from "@/components/ui/quantity-unit-picker";
import { SkeletonList } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";

export type AddToStockDialogProps = {
  open: boolean;
  onClose: () => void;
  food: Food | null;
};

/** « Ajouter au stock » depuis une fiche aliment : lieu, quantité, date et DLC/DDM. */
export function AddToStockDialog({ open, onClose, food }: AddToStockDialogProps) {
  const queryClient = useQueryClient();
  const { success } = useToast();
  const { portions } = usePortions();

  const [stockId, setStockId] = useState<number | null>(null);
  const [value, setValue] = useState<QuantityUnitValue>({ quantity: 1, unit: "piece" });
  const [expiresAt, setExpiresAt] = useState("");
  const [expiryKind, setExpiryKind] = useState<ExpiryKind>("dlc");
  const [error, setError] = useState<string | null>(null);

  const stocksQuery = useQuery({
    queryKey: queryKeys.stocks.list(),
    queryFn: () => apiGet<StocksResponse>("/stocks"),
    enabled: open,
  });

  const locations = stocksQuery.data?.locations ?? [];
  /** Premier lieu par défaut tant que l’utilisateur n’a pas choisi. */
  const effectiveStockId = stockId ?? locations[0]?.id ?? null;

  const mutation = useMutation({
    mutationFn: (input: StockItemInput) => apiPost<DataEnvelope<StockItem>>("/stocks/items", input),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.stocks.all });
      success("Ajouté au stock", food?.name);
      onClose();
    },
    onError: (err) => setError(getErrorMessage(err)),
  });

  function handleSubmit() {
    if (!food || effectiveStockId === null) {
      setError("Choisis d’abord un lieu de stockage.");
      return;
    }
    setError(null);
    mutation.mutate({
      stock_id: effectiveStockId,
      food_id: food.id,
      food_name: food.name,
      quantity: value.quantity,
      unit: value.unit,
      expires_at: expiresAt === "" ? null : expiresAt,
      expiry_kind: expiryKind,
    });
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Ajouter au stock"
      description={food ? food.name : undefined}
      size="md"
      locked={mutation.isPending}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
            Annuler
          </Button>
          <Button onClick={handleSubmit} loading={mutation.isPending} disabled={locations.length === 0}>
            Ajouter au stock
          </Button>
        </>
      }
    >
      {error ? <Banner tone="error" className="mb-3">{error}</Banner> : null}

      {stocksQuery.isPending ? (
        <SkeletonList rows={3} />
      ) : stocksQuery.isError ? (
        <Banner
          tone="error"
          action={
            <Button size="sm" variant="secondary" onClick={() => void stocksQuery.refetch()}>
              Réessayer
            </Button>
          }
        >
          {getErrorMessage(stocksQuery.error)}
        </Banner>
      ) : (
        <div className="space-y-4">
          <SelectField
            label="Lieu"
            value={effectiveStockId ?? ""}
            onChange={(event) => setStockId(Number(event.target.value))}
            hint={locations.length === 0 ? "Crée d’abord un lieu depuis la page Stock." : undefined}
          >
            {locations.map((location) => (
              <option key={location.id} value={location.id}>
                {location.name}
              </option>
            ))}
          </SelectField>

          <div>
            <p className="mb-1.5 text-sm font-medium text-slate-700">Quantité</p>
            <QuantityUnitPicker
              kind="stock"
              value={value}
              onChange={setValue}
              portions={portions}
              food={food ?? undefined}
              showPreview={false}
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              label="Date de péremption"
              type="date"
              value={expiresAt}
              onChange={(event) => setExpiresAt(event.target.value)}
              hint="Facultatif."
            />
            <SelectField
              label="Type de date"
              value={expiryKind}
              onChange={(event) => setExpiryKind(event.target.value as ExpiryKind)}
              hint="DLC : à consommer jusqu’au. DDM : à consommer de préférence avant."
            >
              <option value="dlc">DLC (à consommer jusqu’au)</option>
              <option value="ddm">DDM (de préférence avant le)</option>
            </SelectField>
          </div>
        </div>
      )}
    </Modal>
  );
}

export default AddToStockDialog;
