"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { EstimatePill } from "@/components/ui/pill";
import { QuantityUnitPicker, type QuantityUnitValue } from "@/components/ui/quantity-unit-picker";
import { useToast } from "@/components/ui/toast";
import { apiDelete, apiPost, apiPut, getErrorMessage } from "@/lib/api-client";
import { formatGrams, formatKcal, formatQty } from "@/lib/format";
import { messages } from "@/lib/messages";
import type {
  MealItem,
  MealItemCreateResponse,
  MealItemDeleteResponse,
  MealItemUpdateResponse,
  Portion,
} from "@/lib/types/api";
import { unitLabel } from "@/lib/units";
import { invalidateMealFamilies, itemInputFromMealItem } from "./meal-mutations";

export type MealItemRowProps = {
  item: MealItem;
  mealId: number;
  portions: Portion[];
};

/** One logged item: inline quantity edit (PUT) and delete with an undo toast. */
export function MealItemRow({ item, mealId, portions }: MealItemRowProps) {
  const queryClient = useQueryClient();
  const { toast, success, error: toastError } = useToast();
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState<QuantityUnitValue>({ quantity: item.quantity, unit: item.unit });
  const [busy, setBusy] = useState(false);
  const [rowError, setRowError] = useState<string | null>(null);

  function startEditing() {
    setValue({ quantity: item.quantity, unit: item.unit });
    setRowError(null);
    setEditing(true);
  }

  async function saveQuantity() {
    setBusy(true);
    setRowError(null);
    try {
      await apiPut<MealItemUpdateResponse>(`/meals/${mealId}/items/${item.id}`, {
        quantity: value.quantity,
        unit: value.unit,
      });
      await invalidateMealFamilies(queryClient);
      success("Quantité mise à jour", item.label);
      setEditing(false);
    } catch (error) {
      setRowError(getErrorMessage(error));
    } finally {
      setBusy(false);
    }
  }

  async function removeItem() {
    setBusy(true);
    setRowError(null);
    try {
      await apiDelete<MealItemDeleteResponse>(`/meals/${mealId}/items/${item.id}`);
      await invalidateMealFamilies(queryClient);
      toast({
        title: `${item.label} retiré du repas`,
        description: formatKcal(item.calories),
        action: {
          label: messages.undo,
          onClick: async () => {
            try {
              await apiPost<MealItemCreateResponse>(
                `/meals/${mealId}/items`,
                itemInputFromMealItem(item),
              );
              await invalidateMealFamilies(queryClient);
            } catch (error) {
              toastError("Impossible de rétablir cet aliment", getErrorMessage(error));
            }
          },
        },
      });
    } catch (error) {
      setRowError(getErrorMessage(error));
    } finally {
      setBusy(false);
    }
  }

  const quantityText = formatQty(item.quantity, unitLabel(item.unit, portions));
  const gramsText = item.grams_equivalent ? ` (≈ ${formatGrams(item.grams_equivalent)})` : "";

  return (
    <li className="py-2.5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <p className="truncate text-sm font-medium text-slate-900">{item.label}</p>
            {item.is_estimate ? <EstimatePill /> : null}
          </div>
          <p className="mt-0.5 text-xs text-slate-500">
            {quantityText}
            {gramsText}
          </p>
        </div>

        <div className="flex items-center gap-2">
          <span className="whitespace-nowrap text-sm font-semibold tabular-nums text-slate-900">
            {formatKcal(item.calories)}
          </span>
          <button
            type="button"
            onClick={() => (editing ? setEditing(false) : startEditing())}
            aria-expanded={editing}
            aria-label={`Modifier la quantité de ${item.label}`}
            className="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
          >
            {editing ? messages.close : messages.edit}
          </button>
          <button
            type="button"
            onClick={removeItem}
            disabled={busy}
            aria-label={`Supprimer ${item.label}`}
            className="inline-flex h-10 items-center rounded-xl border border-rose-200 bg-rose-50 px-3 text-xs font-medium text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 disabled:opacity-60"
          >
            {messages.delete}
          </button>
        </div>
      </div>

      {editing ? (
        <div className="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
          <p className="mb-2 text-xs text-slate-500">
            Unité sélectionnée : {unitLabel(value.unit, portions, false)}
          </p>
          <QuantityUnitPicker
            kind={item.source_type === "recipe" ? "recipe" : "food"}
            value={value}
            onChange={setValue}
            portions={portions}
            showPreview={false}
            disabled={busy}
          />
          <div className="mt-3 flex flex-wrap justify-end gap-2">
            <Button variant="secondary" onClick={() => setEditing(false)} disabled={busy}>
              {messages.cancel}
            </Button>
            <Button onClick={saveQuantity} loading={busy}>
              {messages.save}
            </Button>
          </div>
        </div>
      ) : null}

      {rowError ? (
        <Banner tone="error" className="mt-2">
          {rowError}
        </Banner>
      ) : null}
    </li>
  );
}

export default MealItemRow;
