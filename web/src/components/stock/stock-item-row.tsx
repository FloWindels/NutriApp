"use client";

import { useEffect, useState } from "react";
import { cn } from "@/lib/cn";
import { formatKcal, formatNumber } from "@/lib/format";
import type { ExpiryKind, Portion, StockItem, StockItemUpdateInput, StockLocation } from "@/lib/types/api";
import { unitLabel } from "@/lib/units";
import { numOrNull, toText } from "@/components/foods/form-helpers";
import { Button } from "@/components/ui/button";
import { ExpiryBadge } from "@/components/ui/expiry-badge";
import { Field, SelectField } from "@/components/ui/field";
import { MacroPills } from "@/components/ui/macro-pills";
import { Pill } from "@/components/ui/pill";
import { QuantityUnitPicker, type QuantityUnitValue } from "@/components/ui/quantity-unit-picker";

export type StockItemRowProps = {
  item: StockItem;
  locations: StockLocation[];
  portions: Portion[];
  /** Show the location name (all-locations view). */
  showLocation: boolean;
  editing: boolean;
  saving?: boolean;
  onToggleEdit: () => void;
  onCancelEdit: () => void;
  onSave: (input: StockItemUpdateInput) => void;
  onDelete: () => void;
  onConsume: () => void;
};

type Draft = {
  value: QuantityUnitValue;
  expiresAt: string;
  expiryKind: ExpiryKind;
  minQuantity: string;
  openedAt: string;
  stockId: number;
};

function draftFrom(item: StockItem): Draft {
  return {
    value: { quantity: item.quantity, unit: item.unit },
    expiresAt: item.expires_at ?? "",
    expiryKind: item.expiry_kind,
    minQuantity: toText(item.min_quantity),
    openedAt: item.opened_at ?? "",
    stockId: item.stock_id,
  };
}

/** Ligne d’article : nom, quantité, péremption, macros — et tiroir d’édition en place. */
export function StockItemRow({
  item,
  locations,
  portions,
  showLocation,
  editing,
  saving = false,
  onToggleEdit,
  onCancelEdit,
  onSave,
  onDelete,
  onConsume,
}: StockItemRowProps) {
  const [draft, setDraft] = useState<Draft>(() => draftFrom(item));

  useEffect(() => {
    if (editing) setDraft(draftFrom(item));
  }, [editing, item]);

  const pickerFood = item.food
    ? {
        calories: item.food.calories ?? undefined,
        proteins: item.food.proteins ?? undefined,
        carbs: item.food.carbs ?? undefined,
        fat: item.food.fat ?? undefined,
        serving_size_g: item.food.serving_size_g,
      }
    : undefined;
  const depleted = item.is_depleted || item.quantity <= 0;
  const low = !depleted && item.min_quantity !== null && item.quantity <= item.min_quantity;

  return (
    <li
      className={cn(
        "rounded-2xl border bg-white transition",
        depleted ? "border-slate-200 bg-slate-50" : "border-slate-200 hover:border-slate-300",
      )}
    >
      <div className="flex flex-wrap items-center gap-3 px-4 py-3">
        <div className={cn("min-w-0 flex-1", depleted && "opacity-60")}>
          <p className="truncate text-sm font-semibold text-slate-900">{item.food_name}</p>
          <p className="truncate text-xs text-slate-500">
            {[item.food_brand, showLocation ? item.stock_name : null].filter(Boolean).join(" · ") || "Sans marque"}
          </p>
          {item.food ? (
            <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
              {item.food.calories !== null ? (
                <Pill tone="slate">{formatKcal(item.food.calories)} / 100 g</Pill>
              ) : null}
              <MacroPills
                values={{ proteins: item.food.proteins, carbs: item.food.carbs, fat: item.food.fat }}
              />
            </div>
          ) : null}
        </div>

        <div className="flex shrink-0 flex-wrap items-center gap-2">
          <span className="whitespace-nowrap text-sm font-medium tabular-nums text-slate-800">
            {formatNumber(item.quantity, 1)} {unitLabel(item.unit, portions)}
          </span>
          {depleted ? (
            <Pill tone="slate" dot>
              Épuisé
            </Pill>
          ) : low ? (
            <Pill tone="amber" dot>
              Stock bas
            </Pill>
          ) : null}
          <ExpiryBadge
            status={item.expiry_status}
            daysLeft={item.days_left}
            expiresAt={item.expires_at}
            kind={item.expiry_kind}
          />
        </div>

        <div className="flex shrink-0 flex-wrap items-center gap-2">
          <Button size="md" variant="secondary" onClick={onConsume} disabled={depleted}>
            Consommer
          </Button>
          <Button size="md" variant="ghost" onClick={onToggleEdit} aria-expanded={editing}>
            {editing ? "Fermer" : "Modifier"}
          </Button>
          <Button size="md" variant="danger" onClick={onDelete}>
            Supprimer
          </Button>
        </div>
      </div>

      {editing ? (
        <div className="space-y-4 border-t border-slate-100 bg-slate-50/70 px-4 py-4">
          <div>
            <p className="mb-1.5 text-sm font-medium text-slate-700">Quantité restante</p>
            <QuantityUnitPicker
              kind="stock"
              value={draft.value}
              onChange={(value) => setDraft((current) => ({ ...current, value }))}
              portions={portions}
              food={pickerFood}
              stockUnit={item.unit}
              showPreview={false}
            />
            <p className="mt-1 text-xs text-slate-500">La virgule est acceptée (ex. : 1,5).</p>
          </div>

          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Field
              label="Date de péremption"
              type="date"
              value={draft.expiresAt}
              onChange={(event) => setDraft((current) => ({ ...current, expiresAt: event.target.value }))}
            />
            <SelectField
              label="Type de date"
              value={draft.expiryKind}
              onChange={(event) => setDraft((current) => ({ ...current, expiryKind: event.target.value as ExpiryKind }))}
            >
              <option value="dlc">DLC (à consommer jusqu’au)</option>
              <option value="ddm">DDM (de préférence avant le)</option>
            </SelectField>
            <Field
              label="Seuil de réapprovisionnement"
              hint="Alerte « stock bas » en dessous de cette quantité."
              inputMode="decimal"
              value={draft.minQuantity}
              onChange={(event) => setDraft((current) => ({ ...current, minQuantity: event.target.value }))}
            />
            <Field
              label="Ouvert le"
              type="date"
              value={draft.openedAt}
              onChange={(event) => setDraft((current) => ({ ...current, openedAt: event.target.value }))}
            />
            <SelectField
              label="Lieu"
              value={draft.stockId}
              onChange={(event) => setDraft((current) => ({ ...current, stockId: Number(event.target.value) }))}
            >
              {locations.map((location) => (
                <option key={location.id} value={location.id}>
                  {location.name}
                </option>
              ))}
            </SelectField>
          </div>

          <div className="flex flex-wrap items-center justify-end gap-2">
            <Button variant="secondary" onClick={onCancelEdit} disabled={saving}>
              Annuler
            </Button>
            <Button
              loading={saving}
              onClick={() =>
                onSave({
                  quantity: draft.value.quantity,
                  unit: draft.value.unit,
                  expires_at: draft.expiresAt === "" ? null : draft.expiresAt,
                  expiry_kind: draft.expiryKind,
                  min_quantity: numOrNull(draft.minQuantity),
                  opened_at: draft.openedAt === "" ? null : draft.openedAt,
                  stock_id: draft.stockId,
                })
              }
            >
              Enregistrer
            </Button>
          </div>
        </div>
      ) : null}
    </li>
  );
}

export default StockItemRow;
