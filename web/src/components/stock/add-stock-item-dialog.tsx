"use client";

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiGet, apiPost, getErrorMessage, isApiError } from "@/lib/api-client";
import { queryKeys } from "@/lib/query-keys";
import type {
  DataEnvelope,
  ExpiryKind,
  Food,
  PaginatedEnvelope,
  StockItem,
  StockItemInput,
  StockLocation,
} from "@/lib/types/api";
import { usePortions } from "@/hooks/use-portions";
import { FoodRow } from "@/components/foods/food-row";
import { isBarcode } from "@/components/foods/food-utils";
import { useDebounced } from "@/components/foods/hooks";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { Field, SelectField } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { QuantityUnitPicker, type QuantityUnitValue } from "@/components/ui/quantity-unit-picker";
import { SkeletonList } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { ManualFoodForm, type ManualFood } from "./manual-food-form";

export type AddStockItemDialogProps = {
  open: boolean;
  onClose: () => void;
  locations: StockLocation[];
  defaultStockId: number | null;
};

type Selection = { kind: "food"; food: Food } | { kind: "manual"; food: ManualFood };

/** Ajout d’un article : recherche (ou code-barres), lieu, quantité, date et DLC/DDM. */
export function AddStockItemDialog({ open, onClose, locations, defaultStockId }: AddStockItemDialogProps) {
  const queryClient = useQueryClient();
  const { success } = useToast();
  const { portions } = usePortions();

  const [term, setTerm] = useState("");
  const debouncedTerm = useDebounced(term.trim(), 350);
  const [selection, setSelection] = useState<Selection | null>(null);
  const [manualOpen, setManualOpen] = useState(false);
  const [stockId, setStockId] = useState<number | null>(defaultStockId);
  const [newLocationOpen, setNewLocationOpen] = useState(false);
  const [newLocationName, setNewLocationName] = useState("");
  const [value, setValue] = useState<QuantityUnitValue>({ quantity: 1, unit: "piece" });
  const [expiresAt, setExpiresAt] = useState("");
  const [expiryKind, setExpiryKind] = useState<ExpiryKind>("dlc");
  const [error, setError] = useState<string | null>(null);

  const barcode = isBarcode(debouncedTerm) ? debouncedTerm : null;
  const searchEnabled = open && !selection && !barcode && debouncedTerm.length >= 2;

  useEffect(() => {
    if (!open) return;
    setTerm("");
    setSelection(null);
    setManualOpen(false);
    setNewLocationOpen(false);
    setNewLocationName("");
    setValue({ quantity: 1, unit: "piece" });
    setExpiresAt("");
    setExpiryKind("dlc");
    setError(null);
    setStockId(defaultStockId);
  }, [open, defaultStockId]);

  const searchQuery = useQuery({
    queryKey: queryKeys.foods.search({ q: debouncedTerm, scope: "stock" }),
    enabled: searchEnabled,
    queryFn: () =>
      apiGet<PaginatedEnvelope<Food>>("/foods/search", { q: debouncedTerm, per_page: 12, off: 1 }),
  });

  const barcodeQuery = useQuery({
    queryKey: queryKeys.foods.barcode(barcode ?? ""),
    enabled: open && !selection && barcode !== null,
    queryFn: () => apiGet<DataEnvelope<Food>>(`/foods/barcode/${encodeURIComponent(barcode ?? "")}`),
    retry: (failureCount, err) => !(isApiError(err) && err.status < 500) && failureCount < 1,
  });

  const barcodeMissing = Boolean(barcode) && isApiError(barcodeQuery.error) && barcodeQuery.error.status === 404;
  const barcodeFood = barcodeQuery.data?.data ?? null;

  const locationMutation = useMutation({
    mutationFn: (name: string) => apiPost<DataEnvelope<StockLocation>>("/stocks", { name }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.stocks.all });
      setStockId(response.data.id);
      setNewLocationOpen(false);
      setNewLocationName("");
    },
    onError: (err) => setError(getErrorMessage(err)),
  });

  const itemMutation = useMutation({
    mutationFn: (input: StockItemInput) => apiPost<DataEnvelope<StockItem>>("/stocks/items", input),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.stocks.all });
      success("Ajouté au stock", response.data.food_name);
      onClose();
    },
    onError: (err) => setError(getErrorMessage(err)),
  });

  function submit() {
    if (!selection) return;
    if (stockId === null) {
      setError("Choisis un lieu de stockage.");
      return;
    }
    setError(null);
    const common = {
      stock_id: stockId,
      quantity: value.quantity,
      unit: value.unit,
      expires_at: expiresAt === "" ? null : expiresAt,
      expiry_kind: expiryKind,
    };
    if (selection.kind === "food") {
      itemMutation.mutate({ ...common, food_id: selection.food.id, food_name: selection.food.name });
      return;
    }
    const manual = selection.food;
    itemMutation.mutate({
      ...common,
      food_name: manual.name,
      food_brand: manual.brand,
      food_barcode: manual.barcode,
      calories: manual.calories,
      proteins: manual.proteins,
      carbs: manual.carbs,
      fat: manual.fat,
    });
  }

  const selectedName = selection ? selection.food.name : null;
  const pickerFood =
    selection?.kind === "food"
      ? selection.food
      : selection?.kind === "manual"
        ? {
            calories: selection.food.calories ?? undefined,
            proteins: selection.food.proteins ?? undefined,
            carbs: selection.food.carbs ?? undefined,
            fat: selection.food.fat ?? undefined,
          }
        : null;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Ajouter un article"
      size="lg"
      locked={itemMutation.isPending}
      footer={
        selection ? (
          <>
            <Button variant="secondary" onClick={onClose} disabled={itemMutation.isPending}>
              Annuler
            </Button>
            <Button onClick={submit} loading={itemMutation.isPending}>
              Ajouter au stock
            </Button>
          </>
        ) : (
          <Button variant="secondary" onClick={onClose}>
            Fermer
          </Button>
        )
      }
    >
      {error ? (
        <Banner tone="error" className="mb-3">
          {error}
        </Banner>
      ) : null}

      {selection === null ? (
        <div className="space-y-4">
          <div>
            <label htmlFor="stock-add-search" className="mb-1.5 block text-sm font-medium text-slate-700">
              Nom, marque ou code-barres
            </label>
            <input
              id="stock-add-search"
              type="search"
              value={term}
              onChange={(event) => {
                setTerm(event.target.value);
                setManualOpen(false);
              }}
              placeholder="Ex. : lait demi-écrémé, 3256540000000"
              autoComplete="off"
              className="h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-lime-600 focus:ring-4 focus:ring-lime-600/10"
            />
            <p className="mt-1.5 text-xs text-slate-500">
              8 à 14 chiffres lancent la recherche par code-barres (Open Food Facts est interrogé par le serveur).
            </p>
          </div>

          {manualOpen ? (
            <ManualFoodForm
              defaultName={barcode ? "" : debouncedTerm}
              defaultBarcode={barcode}
              onConfirm={(food) => {
                setSelection({ kind: "manual", food });
                setManualOpen(false);
              }}
              onCancel={() => setManualOpen(false)}
            />
          ) : barcode ? (
            barcodeQuery.isPending ? (
              <SkeletonList rows={1} />
            ) : barcodeMissing ? (
              <EmptyState
                compact
                title="Produit introuvable, même sur Open Food Facts."
                message="Saisis-le à la main : ses valeurs seront enregistrées avec l’article."
                action={<Button onClick={() => setManualOpen(true)}>Saisir l’aliment</Button>}
              />
            ) : barcodeQuery.isError ? (
              <Banner
                tone="error"
                action={
                  <Button size="sm" variant="secondary" onClick={() => void barcodeQuery.refetch()}>
                    Réessayer
                  </Button>
                }
              >
                {getErrorMessage(barcodeQuery.error)}
              </Banner>
            ) : barcodeFood ? (
              <FoodRow food={barcodeFood} onClick={() => setSelection({ kind: "food", food: barcodeFood })} />
            ) : null
          ) : searchEnabled ? (
            searchQuery.isPending ? (
              <SkeletonList rows={4} />
            ) : searchQuery.isError ? (
              <Banner
                tone="error"
                action={
                  <Button size="sm" variant="secondary" onClick={() => void searchQuery.refetch()}>
                    Réessayer
                  </Button>
                }
              >
                {getErrorMessage(searchQuery.error)}
              </Banner>
            ) : (searchQuery.data?.data.length ?? 0) === 0 ? (
              <EmptyState
                compact
                title="Aucun aliment ne correspond."
                message="Saisis-le à la main pour l’ajouter quand même à ton stock."
                action={<Button onClick={() => setManualOpen(true)}>Saisir l’aliment</Button>}
              />
            ) : (
              <div className="space-y-2">
                {searchQuery.data?.data.map((food) => (
                  <FoodRow key={food.id} food={food} onClick={() => setSelection({ kind: "food", food })} />
                ))}
                <div className="pt-1">
                  <Button variant="ghost" size="sm" onClick={() => setManualOpen(true)}>
                    Aucun ne convient : saisir l’aliment
                  </Button>
                </div>
              </div>
            )
          ) : (
            <EmptyState
              compact
              title="Cherche l’aliment à ranger"
              message="Tape au moins 2 caractères, ou un code-barres."
              action={<Button variant="secondary" onClick={() => setManualOpen(true)}>Saisir l’aliment</Button>}
            />
          )}
        </div>
      ) : (
        <div className="space-y-4">
          <div className="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
            <div className="min-w-0">
              <p className="truncate text-sm font-semibold text-slate-900">{selectedName}</p>
              <p className="text-xs text-slate-500">
                {selection.kind === "food" ? (selection.food.brand ?? "Marque inconnue") : "Aliment saisi à la main"}
              </p>
            </div>
            <Button variant="ghost" size="sm" onClick={() => setSelection(null)}>
              Changer
            </Button>
          </div>

          <div className="space-y-2">
            <SelectField
              label="Lieu"
              value={stockId ?? ""}
              onChange={(event) => setStockId(Number(event.target.value))}
            >
              {locations.map((location) => (
                <option key={location.id} value={location.id}>
                  {location.name}
                </option>
              ))}
            </SelectField>
            {newLocationOpen ? (
              <div className="flex flex-wrap items-end gap-2">
                <Field
                  label="Nouveau lieu"
                  value={newLocationName}
                  onChange={(event) => setNewLocationName(event.target.value)}
                  placeholder="Ex. : Cellier"
                  wrapperClassName="flex-1"
                />
                <Button
                  onClick={() => locationMutation.mutate(newLocationName.trim())}
                  loading={locationMutation.isPending}
                  disabled={newLocationName.trim().length < 2}
                >
                  Créer
                </Button>
                <Button variant="ghost" onClick={() => setNewLocationOpen(false)}>
                  Annuler
                </Button>
              </div>
            ) : (
              <Button variant="ghost" size="sm" onClick={() => setNewLocationOpen(true)}>
                Nouveau lieu
              </Button>
            )}
          </div>

          <div>
            <p className="mb-1.5 text-sm font-medium text-slate-700">Quantité</p>
            <QuantityUnitPicker
              kind="stock"
              value={value}
              onChange={setValue}
              portions={portions}
              food={pickerFood}
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
              hint="DLC : à consommer jusqu’au. DDM : de préférence avant le."
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

export default AddStockItemDialog;
