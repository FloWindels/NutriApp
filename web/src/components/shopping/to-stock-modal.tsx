"use client";

import { useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field, SelectField } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { SkeletonList } from "@/components/ui/skeleton";
import { apiGet, apiPost, getErrorMessage, isApiError } from "@/lib/api-client";
import { formatNumber, parseDecimal, todayIso } from "@/lib/format";
import { messages } from "@/lib/messages";
import { queryKeys } from "@/lib/query-keys";
import type { DataEnvelope, ShoppingItem, StockItem, StocksResponse } from "@/lib/types/api";
import { UNITS, unitLabel } from "@/lib/units";
import { useResetOnChange } from "@/lib/use-reset-on-change";

export type ToStockModalProps = {
  open: boolean;
  onClose: () => void;
  item: ShoppingItem | null;
  onStored: (item: ShoppingItem, stockItem: StockItem) => void;
};

/**
 * « Mettre au stock » — `POST /shopping-list/items/{id}/to-stock`:
 * the article leaves the list and lands in the chosen storage place.
 */
export function ToStockModal({ open, onClose, item, onStored }: ToStockModalProps) {
  const [stockId, setStockId] = useState<string>("");
  const [quantity, setQuantity] = useState<string>("");
  const [unit, setUnit] = useState<string>("");
  const [expiresAt, setExpiresAt] = useState<string>("");
  const [error, setError] = useState<string | null>(null);
  const [quantityError, setQuantityError] = useState<string | null>(null);

  const stocks = useQuery({
    queryKey: queryKeys.stocks.list(),
    queryFn: () => apiGet<StocksResponse>("/stocks"),
    enabled: open,
    staleTime: 60_000,
  });

  const locations = stocks.data?.locations ?? [];

  useResetOnChange(`${open}:${item?.id ?? ""}`, () => {
    if (!open || !item) return;
    setQuantity(item.quantity !== null ? formatNumber(item.quantity, 2) : "");
    setUnit(item.unit ?? "");
    setExpiresAt("");
    setError(null);
    setQuantityError(null);
  });

  // Lieu par defaut derive des donnees chargees plutot que recopie dans l'etat.
  const effectiveStockId = stockId || (locations.length > 0 ? String(locations[0].id) : "");

  const store = useMutation({
    mutationFn: (target: ShoppingItem) => {
      const parsed = quantity.trim() ? parseDecimal(quantity) : null;
      return apiPost<DataEnvelope<StockItem>>(`/shopping-list/items/${target.id}/to-stock`, {
        stock_id: effectiveStockId ? Number(effectiveStockId) : undefined,
        quantity: parsed ?? undefined,
        unit: unit || undefined,
        expires_at: expiresAt || null,
      });
    },
    onSuccess: (response, target) => {
      onStored(target, response.data);
      onClose();
    },
    onError: (err) => {
      if (isApiError(err) && err.isValidation) {
        setQuantityError(err.fieldError("quantity") ?? null);
        setError(
          err.fieldError("stock_id") ??
            err.fieldError("unit") ??
            err.fieldError("expires_at") ??
            (err.fieldError("quantity") ? null : err.message),
        );
        return;
      }
      setError(getErrorMessage(err));
    },
  });

  function submit() {
    if (!item) return;
    const raw = quantity.trim();
    const parsed = raw ? parseDecimal(raw) : null;
    if (raw && (parsed === null || parsed <= 0)) {
      setQuantityError("Indique une quantité supérieure à 0.");
      return;
    }
    if (!effectiveStockId) {
      setError("Choisis un lieu de rangement.");
      return;
    }
    setError(null);
    setQuantityError(null);
    store.mutate(item);
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      locked={store.isPending}
      title="Mettre au stock"
      description={item ? `${item.label} sera retiré de la liste et rangé dans ton stock.` : undefined}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={store.isPending}>
            {messages.cancel}
          </Button>
          <Button
            onClick={submit}
            loading={store.isPending}
            disabled={stocks.isPending || locations.length === 0}
          >
            Ranger dans le stock
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error ? <Banner tone="error">{error}</Banner> : null}

        {stocks.isPending ? (
          <SkeletonList rows={3} />
        ) : stocks.isError ? (
          <Banner
            tone="error"
            action={
              <Button variant="secondary" onClick={() => void stocks.refetch()}>
                {messages.retry}
              </Button>
            }
          >
            {getErrorMessage(stocks.error)}
          </Banner>
        ) : locations.length === 0 ? (
          <Banner tone="warning">
            Tu n’as pas encore de lieu de rangement. Crée-en un depuis la section « Stock ».
          </Banner>
        ) : (
          <>
            <SelectField
              label="Lieu de rangement"
              value={effectiveStockId}
              onChange={(event) => setStockId(event.target.value)}
              disabled={store.isPending}
            >
              {locations.map((location) => (
                <option key={location.id} value={location.id}>
                  {location.name}
                </option>
              ))}
            </SelectField>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field
                label="Quantité"
                inputMode="decimal"
                placeholder="Ex. : 1,5"
                value={quantity}
                onChange={(event) => setQuantity(event.target.value)}
                error={quantityError ?? undefined}
                disabled={store.isPending}
              />
              <SelectField
                label="Unité"
                value={unit}
                onChange={(event) => setUnit(event.target.value)}
                disabled={store.isPending}
              >
                <option value="">Sans unité</option>
                {UNITS.map((value) => (
                  <option key={value} value={value}>
                    {unitLabel(value, undefined, false)}
                  </option>
                ))}
              </SelectField>
            </div>

            <Field
              label="Date de péremption (facultatif)"
              type="date"
              min={todayIso()}
              value={expiresAt}
              onChange={(event) => setExpiresAt(event.target.value)}
              disabled={store.isPending}
            />
          </>
        )}
      </div>
    </Modal>
  );
}

export default ToStockModal;
