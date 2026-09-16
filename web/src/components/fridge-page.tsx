"use client";

import { useEffect, useMemo, useRef, useState } from "react";

type FoodSearchItem = {
  id: number | null;
  barcode: string;
  name: string;
  brand: string | null;
};

type FridgeItem = {
  id: number;
  stock_id: number;
  stock_name: string | null;
  food_id: number | null;
  food_name: string | null;
  food_barcode: string | null;
  food_brand: string | null;
  quantity: number;
  unit: string;
  expires_at: string | null;
  days_left: number | null;
};

type FoodsSearchResponse = {
  data?: Array<{
    id: number;
    barcode: string;
    name: string;
    brand?: string | null;
  }>;
  message?: string;
};

type BackendFoodResponse = {
  data?: {
    id?: number;
    barcode: string;
    name: string;
    brand?: string | null;
    image_url?: string | null;
    calories?: number | null;
    fat?: number | null;
    carbs?: number | null;
    proteins?: number | null;
  };
  message?: string;
};

type OpenFoodFactsProduct = {
  product_name?: string;
  brands?: string;
  code?: string;
  image_front_url?: string;
  image_url?: string;
  nutriments?: {
    energy_kcal_100g?: number;
    energy_100g?: number;
    fat_100g?: number;
    carbohydrates_100g?: number;
    proteins_100g?: number;
  };
};

type OpenFoodFactsResponse = {
  status: number;
  product?: OpenFoodFactsProduct;
};

type OffCandidate = {
  barcode: string;
  name: string;
  brand: string;
  imageUrl: string;
  calories: number | null;
  fat: number | null;
  carbs: number | null;
  proteins: number | null;
};

type FridgeResponse = {
  data?: FridgeItem[];
  locations?: Array<{ id: number; name: string }>;
  message?: string;
};

function expiryLabel(daysLeft: number | null) {
  if (daysLeft === null) {
    return { text: "Sans date", classes: "border-slate-200 bg-slate-50 text-slate-700" };
  }

  if (daysLeft < 0) {
    return { text: "Perime", classes: "border-rose-200 bg-rose-50 text-rose-700" };
  }

  if (daysLeft <= 2) {
    return { text: `${daysLeft} j restant(s)`, classes: "border-amber-200 bg-amber-50 text-amber-800" };
  }

  return { text: `${daysLeft} j restants`, classes: "border-emerald-200 bg-emerald-50 text-emerald-700" };
}

function isLikelyBarcode(value: string) {
  return /^\d{8,14}$/.test(value);
}

function toOffCandidate(product: OpenFoodFactsProduct): OffCandidate {
  return {
    barcode: product.code ?? "",
    name: product.product_name?.trim() || "Produit sans nom",
    brand: product.brands?.split(",").map((value) => value.trim()).filter(Boolean)[0] || "",
    imageUrl: product.image_front_url || product.image_url || "",
    calories: product.nutriments?.energy_kcal_100g ?? product.nutriments?.energy_100g ?? null,
    fat: product.nutriments?.fat_100g ?? null,
    carbs: product.nutriments?.carbohydrates_100g ?? null,
    proteins: product.nutriments?.proteins_100g ?? null,
  };
}

export default function FridgePage() {
  const [query, setQuery] = useState("");
  const [searchLoading, setSearchLoading] = useState(false);
  const [enriching, setEnriching] = useState(false);
  const [searchResults, setSearchResults] = useState<FoodSearchItem[]>([]);
  const [selectedFood, setSelectedFood] = useState<FoodSearchItem | null>(null);
  const [offCandidate, setOffCandidate] = useState<OffCandidate | null>(null);

  const [items, setItems] = useState<FridgeItem[]>([]);
  const [locations, setLocations] = useState<Array<{ id: number; name: string }>>([]);
  const [selectedLocationId, setSelectedLocationId] = useState<number | null>(null);
  const [activeLocationId, setActiveLocationId] = useState<number | "all">("all");
  const [newLocationName, setNewLocationName] = useState("");
  const [creatingLocation, setCreatingLocation] = useState(false);
  const [itemsLoading, setItemsLoading] = useState(true);

  const [quantity, setQuantity] = useState("1");
  const [unit, setUnit] = useState("unite");
  const [expiresAt, setExpiresAt] = useState("");

  const [errorMessage, setErrorMessage] = useState("");
  const [successMessage, setSuccessMessage] = useState("");
  const [saving, setSaving] = useState(false);
  const [savingItemId, setSavingItemId] = useState<number | null>(null);

  const [drafts, setDrafts] = useState<Record<number, { quantity: string; unit: string; expires_at: string }>>({});
  const requestIdRef = useRef(0);
  const publicEanCacheRef = useRef<Record<string, FoodSearchItem>>({});
  const offCacheRef = useRef<Record<string, OffCandidate | null>>({});

  async function loadFridge() {
    const token = localStorage.getItem("token");

    if (!token) {
      setErrorMessage("Session invalide. Reconnecte-toi.");
      setItemsLoading(false);
      return;
    }

    try {
      const response = await fetch("/api/stocks", {
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
      });

      const payload = (await response.json()) as FridgeResponse;

      if (!response.ok) {
        throw new Error(payload.message || "Impossible de charger ton frigo.");
      }

      const list = payload.data ?? [];
      setItems(list);

      const fetchedLocations = payload.locations ?? [];
      setLocations(fetchedLocations);
      if (fetchedLocations.length > 0) {
        setSelectedLocationId((current) => current ?? fetchedLocations[0].id);
      }

      const nextDrafts: Record<number, { quantity: string; unit: string; expires_at: string }> = {};
      for (const item of list) {
        nextDrafts[item.id] = {
          quantity: String(item.quantity ?? 1),
          unit: item.unit ?? "unite",
          expires_at: item.expires_at ?? "",
        };
      }
      setDrafts(nextDrafts);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Impossible de charger ton frigo.");
    } finally {
      setItemsLoading(false);
    }
  }

  useEffect(() => {
    void loadFridge();
  }, []);

  async function handleSearch() {
    const trimmedQuery = query.trim();

    if (trimmedQuery.length < 2) {
      setErrorMessage("Saisis au moins 2 caracteres pour rechercher.");
      return;
    }

    setSearchLoading(true);
    setEnriching(false);
    setErrorMessage("");
    setSuccessMessage("");
    setOffCandidate(null);

    try {
      if (isLikelyBarcode(trimmedQuery)) {
        const requestId = requestIdRef.current + 1;
        requestIdRef.current = requestId;

        let localFood: FoodSearchItem | null = null;

        if (publicEanCacheRef.current[trimmedQuery]) {
          localFood = publicEanCacheRef.current[trimmedQuery];
        } else {
          const token = localStorage.getItem("token");
          const response = await fetch(`/api/foods/barcode/${encodeURIComponent(trimmedQuery)}`, {
            headers: {
              Accept: "application/json",
              ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
          });

          if (response.ok) {
            const payload = (await response.json()) as BackendFoodResponse;
            if (payload.data?.id) {
              localFood = {
                id: payload.data.id,
                barcode: payload.data.barcode,
                name: payload.data.name,
                brand: payload.data.brand ?? null,
              };
              publicEanCacheRef.current[trimmedQuery] = localFood;
            }
          } else if (response.status !== 404) {
            const payload = (await response.json()) as BackendFoodResponse;
            throw new Error(payload.message || "Recherche locale impossible.");
          }
        }

        if (localFood) {
          setSearchResults([localFood]);
          setSelectedFood(localFood);
          setSuccessMessage("Aliment trouve dans la base publique.");
          return;
        }

        setSearchResults([]);
        setSelectedFood(null);
        setErrorMessage("Produit absent de la base publique. Recherche Open Food Facts en cours...");
        void enrichFromOpenFoodFacts(trimmedQuery, requestId);
        return;
      }

      const response = await fetch(`/api/foods/search?q=${encodeURIComponent(trimmedQuery)}`, {
        headers: {
          Accept: "application/json",
        },
      });

      const payload = (await response.json()) as FoodsSearchResponse;

      if (!response.ok) {
        throw new Error(payload.message || "Recherche impossible.");
      }

      const results = (payload.data ?? []).map((food) => ({
        id: food.id,
        barcode: food.barcode,
        name: food.name,
        brand: food.brand ?? null,
      }));

      setSearchResults(results);
      setSelectedFood(results[0] ?? null);

      if (results.length === 0) {
        setErrorMessage("Aucun aliment trouve.");
      }
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Recherche impossible.");
    } finally {
      setSearchLoading(false);
    }
  }

  async function enrichFromOpenFoodFacts(barcode: string, requestId: number) {
    setEnriching(true);

    try {
      let candidate: OffCandidate | null;

      if (barcode in offCacheRef.current) {
        candidate = offCacheRef.current[barcode];
      } else {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 3500);

        let response: Response;
        try {
          response = await fetch(`https://world.openfoodfacts.org/api/v2/product/${barcode}.json`, {
            signal: controller.signal,
          });
        } finally {
          clearTimeout(timeoutId);
        }

        if (!response.ok) {
          throw new Error("Open Food Facts indisponible.");
        }

        const payload = (await response.json()) as OpenFoodFactsResponse;
        candidate = payload.status === 1 && payload.product ? toOffCandidate(payload.product) : null;
        offCacheRef.current[barcode] = candidate;
      }

      if (requestIdRef.current !== requestId) {
        return;
      }

      if (!candidate) {
        setErrorMessage("Aucun resultat sur Open Food Facts pour cet EAN.");
        return;
      }

      setOffCandidate(candidate);
      const offAsSelection: FoodSearchItem = {
        id: null,
        barcode: candidate.barcode || barcode,
        name: candidate.name,
        brand: candidate.brand || null,
      };
      setSearchResults([offAsSelection]);
      setSelectedFood(offAsSelection);
      setErrorMessage("");
      setSuccessMessage("Trouve sur Open Food Facts et ajoute dans Selection.");
    } catch {
      if (requestIdRef.current !== requestId) {
        return;
      }
      setErrorMessage("Recherche Open Food Facts impossible pour le moment.");
    } finally {
      if (requestIdRef.current === requestId) {
        setEnriching(false);
      }
    }
  }

  async function handleAddToFridge() {
    const token = localStorage.getItem("token");

    if (!token || !selectedFood) {
      setErrorMessage("Selectionne un aliment et reconnecte-toi.");
      return;
    }

    const parsedQuantity = Number(quantity);
    if (!Number.isFinite(parsedQuantity) || parsedQuantity <= 0) {
      setErrorMessage("La quantite doit etre superieure a 0.");
      return;
    }

    setSaving(true);
    setErrorMessage("");
    setSuccessMessage("");

    try {
      const response = await fetch("/api/stocks/items", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          stock_id: selectedLocationId,
          ...(selectedFood.id
            ? { food_id: selectedFood.id }
            : {
                food_name: selectedFood.name,
                food_barcode: selectedFood.barcode || null,
                food_brand: selectedFood.brand || null,
              }),
          quantity: parsedQuantity,
          unit: unit.trim() || "unite",
          expires_at: expiresAt || null,
        }),
      });

      const payload = (await response.json()) as FridgeResponse;

      if (!response.ok) {
        throw new Error(payload.message || "Ajout au frigo impossible.");
      }

      setSuccessMessage("Aliment ajoute au frigo.");
      setSelectedFood(null);
      setQuantity("1");
      setUnit("unite");
      setExpiresAt("");
      await loadFridge();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Ajout au frigo impossible.");
    } finally {
      setSaving(false);
    }
  }

  async function handleCreateLocation() {
    const token = localStorage.getItem("token");
    const name = newLocationName.trim();

    if (!token || !name) {
      setErrorMessage("Saisis un nom de lieu valide.");
      return;
    }

    setCreatingLocation(true);
    setErrorMessage("");
    setSuccessMessage("");

    try {
      const response = await fetch("/api/stocks", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ name }),
      });

      const payload = (await response.json()) as { data?: { id: number; name: string }; message?: string };

      if (!response.ok || !payload.data) {
        throw new Error(payload.message || "Creation du lieu impossible.");
      }

      setLocations((prev) => {
        const next = [...prev, payload.data!].sort((a, b) => a.name.localeCompare(b.name));
        return next;
      });
      setSelectedLocationId(payload.data.id);
      setActiveLocationId(payload.data.id);
      setNewLocationName("");
      setSuccessMessage("Lieu de stock ajoute.");
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Creation du lieu impossible.");
    } finally {
      setCreatingLocation(false);
    }
  }

  async function handleUpdateItem(itemId: number) {
    const token = localStorage.getItem("token");
    const draft = drafts[itemId];

    if (!token || !draft) {
      return;
    }

    const parsedQuantity = Number(draft.quantity);
    if (!Number.isFinite(parsedQuantity) || parsedQuantity <= 0) {
      setErrorMessage("La quantite doit etre superieure a 0.");
      return;
    }

    setSavingItemId(itemId);
    setErrorMessage("");
    setSuccessMessage("");

    try {
      const response = await fetch(`/api/stocks/items/${itemId}`, {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          quantity: parsedQuantity,
          unit: draft.unit.trim() || "unite",
          expires_at: draft.expires_at || null,
        }),
      });

      const payload = (await response.json()) as FridgeResponse;

      if (!response.ok) {
        throw new Error(payload.message || "Mise a jour impossible.");
      }

      setSuccessMessage("Element du frigo mis a jour.");
      await loadFridge();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Mise a jour impossible.");
    } finally {
      setSavingItemId(null);
    }
  }

  async function handleDeleteItem(itemId: number) {
    const token = localStorage.getItem("token");

    if (!token) {
      return;
    }

    setSavingItemId(itemId);
    setErrorMessage("");
    setSuccessMessage("");

    try {
      const response = await fetch(`/api/stocks/items/${itemId}`, {
        method: "DELETE",
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
      });

      const payload = (await response.json()) as FridgeResponse;

      if (!response.ok) {
        throw new Error(payload.message || "Suppression impossible.");
      }

      setSuccessMessage("Element supprime du frigo.");
      await loadFridge();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Suppression impossible.");
    } finally {
      setSavingItemId(null);
    }
  }

  const sortedItems = useMemo(() => {
    return [...items].sort((a, b) => {
      if (!a.expires_at && !b.expires_at) return 0;
      if (!a.expires_at) return 1;
      if (!b.expires_at) return -1;
      return a.expires_at.localeCompare(b.expires_at);
    });
  }, [items]);

  const filteredItems = useMemo(() => {
    if (activeLocationId === "all") {
      return sortedItems;
    }

    return sortedItems.filter((item) => item.stock_id === activeLocationId);
  }, [activeLocationId, sortedItems]);

  const activeLocationName = useMemo(() => {
    if (activeLocationId === "all") {
      return "Tous";
    }

    return locations.find((location) => location.id === activeLocationId)?.name ?? "Lieu";
  }, [activeLocationId, locations]);

  function getLocationCount(locationId: number) {
    return items.filter((item) => item.stock_id === locationId).length;
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_1fr]">
      <section className="rounded-[2rem] border border-lime-100 bg-lime-50/60 p-5 shadow-[0_18px_45px_rgba(15,23,42,0.06)]">
        <div className="space-y-2">
          <p className="text-sm font-semibold uppercase tracking-[0.14em] text-lime-700">Stock</p>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-950">Ajouter des aliments</h1>
          <p className="text-sm leading-6 text-slate-600">
            Recherche un aliment puis ajoute-le dans le lieu de ton choix (frigo, placard, etc.) avec sa date de peremption.
          </p>
        </div>

        <div className="mt-6 space-y-3 rounded-[1.5rem] border border-white/70 bg-white p-4">
          <label className="block text-sm font-medium text-slate-700" htmlFor="food-search">
            Rechercher un aliment
          </label>
          <div className="flex flex-col gap-3 sm:flex-row">
            <input
              id="food-search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Nom, marque ou EAN"
              className="min-w-0 flex-1 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-lime-600 focus:ring-4 focus:ring-lime-600/10"
            />
            <button
              type="button"
              onClick={() => void handleSearch()}
              disabled={searchLoading}
              className="rounded-2xl bg-lime-700 px-5 py-3 text-sm font-medium text-white transition hover:bg-lime-800 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {searchLoading ? "Recherche..." : "Rechercher"}
            </button>
          </div>

          {enriching ? <p className="text-xs text-slate-500">Verification Open Food Facts en arriere-plan...</p> : null}

          {offCandidate ? (
            <div className="rounded-2xl border border-cyan-200 bg-cyan-50 px-4 py-3">
              <p className="text-sm font-semibold text-cyan-900">Trouve sur Open Food Facts</p>
              <p className="mt-1 text-sm text-cyan-800">
                {offCandidate.name}
                {offCandidate.brand ? ` · ${offCandidate.brand}` : ""}
                {` · EAN ${offCandidate.barcode}`}
              </p>
              <p className="mt-2 text-xs text-cyan-800">Cet aliment est deja charge dans Selection. Clique simplement sur "Ajouter au frigo".</p>
            </div>
          ) : null}

          <div className="max-h-56 space-y-2 overflow-auto rounded-2xl border border-slate-100 p-2">
            {searchResults.length === 0 ? (
              <p className="px-2 py-3 text-sm text-slate-500">Aucun resultat pour le moment.</p>
            ) : (
              searchResults.map((food) => (
                <button
                  key={`${food.id ?? "external"}-${food.barcode || food.name}`}
                  type="button"
                  onClick={() => setSelectedFood(food)}
                  className={`w-full rounded-xl border px-3 py-2 text-left text-sm transition ${
                    selectedFood?.id === food.id && selectedFood?.barcode === food.barcode
                      ? "border-lime-300 bg-lime-50 text-lime-900"
                      : "border-slate-200 bg-white text-slate-700 hover:border-lime-200 hover:bg-lime-50/40"
                  }`}
                >
                  <p className="font-medium">{food.name}</p>
                  <p className="text-xs text-slate-500">
                    {food.brand ? `${food.brand} · ` : ""}
                    EAN: {food.barcode}
                  </p>
                </button>
              ))
            )}
          </div>

          <div className="grid gap-3 rounded-2xl border border-slate-100 p-3 sm:grid-cols-3">
            <div className="sm:col-span-3">
              <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Selection</p>
              <p className="text-sm text-slate-900">
                {selectedFood ? `${selectedFood.name} (EAN ${selectedFood.barcode})` : "Aucun aliment selectionne"}
              </p>
            </div>

            <label className="text-xs font-medium text-slate-600 sm:col-span-2">
              Lieu de stock
              <select
                value={selectedLocationId ?? ""}
                onChange={(event) => setSelectedLocationId(Number(event.target.value))}
                className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
              >
                {locations.length === 0 ? <option value="">Aucun lieu</option> : null}
                {locations.map((location) => (
                  <option key={location.id} value={location.id}>
                    {location.name}
                  </option>
                ))}
              </select>
            </label>

            <div className="text-xs font-medium text-slate-600 sm:col-span-1">
              Nouveau lieu
              <div className="mt-1 flex gap-2">
                <input
                  value={newLocationName}
                  onChange={(event) => setNewLocationName(event.target.value)}
                  placeholder="Ex: Placard"
                  className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
                />
                <button
                  type="button"
                  onClick={() => void handleCreateLocation()}
                  disabled={creatingLocation}
                  className="rounded-xl border border-lime-300 bg-lime-50 px-3 py-2 text-xs font-medium text-lime-800 transition hover:bg-lime-100 disabled:opacity-60"
                >
                  {creatingLocation ? "..." : "Ajouter"}
                </button>
              </div>
            </div>

            <label className="text-xs font-medium text-slate-600">
              Quantite
              <input
                value={quantity}
                onChange={(event) => setQuantity(event.target.value)}
                inputMode="decimal"
                className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
              />
            </label>

            <label className="text-xs font-medium text-slate-600">
              Unite
              <input
                value={unit}
                onChange={(event) => setUnit(event.target.value)}
                className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
              />
            </label>

            <label className="text-xs font-medium text-slate-600">
              Date de peremption
              <input
                type="date"
                value={expiresAt}
                onChange={(event) => setExpiresAt(event.target.value)}
                className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
              />
            </label>
          </div>

          <button
            type="button"
            onClick={() => void handleAddToFridge()}
            disabled={saving || !selectedFood || !selectedLocationId}
            className="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {saving ? "Ajout en cours..." : "Ajouter au stock"}
          </button>

          {errorMessage ? (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
              {errorMessage}
            </div>
          ) : null}

          {successMessage ? (
            <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
              {successMessage}
            </div>
          ) : null}
        </div>
      </section>

      <section className="rounded-[2rem] border border-slate-200 bg-white p-5 shadow-[0_18px_45px_rgba(15,23,42,0.06)]">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-xl font-semibold text-slate-950">Produits dans ton stock</h2>
          <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-600">
            {items.length} element(s)
          </span>
        </div>

        <div className="mb-4 flex flex-wrap gap-2">
          <button
            type="button"
            onClick={() => setActiveLocationId("all")}
            className={`rounded-full border px-4 py-2 text-xs font-medium transition ${
              activeLocationId === "all"
                ? "border-lime-700 bg-lime-700 text-white"
                : "border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50"
            }`}
          >
            Tous
          </button>

          {locations.map((location) => {
            const count = getLocationCount(location.id);
            const isActive = activeLocationId === location.id;

            return (
              <button
                key={location.id}
                type="button"
                onClick={() => setActiveLocationId(location.id)}
                className={`rounded-full border px-4 py-2 text-xs font-medium transition ${
                  isActive
                    ? "border-lime-700 bg-lime-700 text-white"
                    : "border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50"
                }`}
              >
                {location.name} ({count})
              </button>
            );
          })}
        </div>

        <p className="mb-3 text-xs text-slate-500">Lieu affiché: {activeLocationName}</p>

        {itemsLoading ? <p className="text-sm text-slate-500">Chargement du stock...</p> : null}

        {!itemsLoading && filteredItems.length === 0 ? (
          <p className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-600">
            Aucun aliment dans ce lieu pour le moment.
          </p>
        ) : null}

        <div className="space-y-3">
          {filteredItems.map((item) => {
            const badge = expiryLabel(item.days_left);
            const draft = drafts[item.id] ?? {
              quantity: String(item.quantity),
              unit: item.unit,
              expires_at: item.expires_at ?? "",
            };

            return (
              <div key={item.id} className="rounded-2xl border border-slate-200 bg-slate-50/50 p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <p className="text-sm font-semibold text-slate-900">{item.food_name ?? "Aliment"}</p>
                    <p className="text-xs text-slate-500">
                      {item.food_brand ? `${item.food_brand} · ` : ""}
                      EAN: {item.food_barcode ?? "-"}
                    </p>
                    <p className="text-xs text-slate-500">Lieu: {item.stock_name ?? "Frigo"}</p>
                  </div>
                  <span className={`rounded-full border px-3 py-1 text-xs font-medium ${badge.classes}`}>
                    {badge.text}
                  </span>
                </div>

                <div className="mt-3 grid gap-2 sm:grid-cols-3">
                  <label className="text-xs font-medium text-slate-600">
                    Quantite
                    <input
                      value={draft.quantity}
                      onChange={(event) =>
                        setDrafts((prev) => ({
                          ...prev,
                          [item.id]: { ...draft, quantity: event.target.value },
                        }))
                      }
                      className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
                    />
                  </label>

                  <label className="text-xs font-medium text-slate-600">
                    Unite
                    <input
                      value={draft.unit}
                      onChange={(event) =>
                        setDrafts((prev) => ({
                          ...prev,
                          [item.id]: { ...draft, unit: event.target.value },
                        }))
                      }
                      className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
                    />
                  </label>

                  <label className="text-xs font-medium text-slate-600">
                    Date de peremption
                    <input
                      type="date"
                      value={draft.expires_at}
                      onChange={(event) =>
                        setDrafts((prev) => ({
                          ...prev,
                          [item.id]: { ...draft, expires_at: event.target.value },
                        }))
                      }
                      className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
                    />
                  </label>
                </div>

                <div className="mt-3 flex gap-2">
                  <button
                    type="button"
                    onClick={() => void handleUpdateItem(item.id)}
                    disabled={savingItemId === item.id}
                    className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-100 disabled:opacity-60"
                  >
                    Mettre a jour
                  </button>
                  <button
                    type="button"
                    onClick={() => void handleDeleteItem(item.id)}
                    disabled={savingItemId === item.id}
                    className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700 transition hover:bg-rose-100 disabled:opacity-60"
                  >
                    Supprimer
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      </section>
    </div>
  );
}
