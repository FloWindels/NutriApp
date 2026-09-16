"use client";

import { useRef, useState } from "react";

type FoodItem = {
  id: number | null;
  barcode: string;
  name: string;
  brand: string | null;
  imageUrl: string | null;
  calories: number | null;
  fat: number | null;
  carbs: number | null;
  proteins: number | null;
  sourceType: "manual" | "open_food_facts";
  isOwner: boolean;
};

type FormState = {
  barcode: string;
  name: string;
  brand: string;
  imageUrl: string;
  calories: string;
  fat: string;
  carbs: string;
  proteins: string;
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
    source_type?: "manual" | "open_food_facts";
    is_owner?: boolean;
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

function toNumber(value: string) {
  if (!value.trim()) {
    return null;
  }

  const parsed = Number(value);
  return Number.isNaN(parsed) ? null : parsed;
}

async function fetchFoodFromPublicCatalog(barcode: string): Promise<FoodItem | null> {
  const token = localStorage.getItem("token");
  const response = await fetch(`/api/foods/barcode/${encodeURIComponent(barcode)}`, {
    headers: {
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (response.status === 404) {
    return null;
  }

  if (!response.ok) {
    throw new Error("Impossible de lire la base publique.");
  }

  const payload = (await response.json()) as BackendFoodResponse;
  const food = payload.data;

  if (!food) {
    return null;
  }

  return {
    id: food.id ?? null,
    barcode: food.barcode,
    name: food.name,
    brand: food.brand ?? null,
    imageUrl: food.image_url ?? null,
    calories: food.calories ?? null,
    fat: food.fat ?? null,
    carbs: food.carbs ?? null,
    proteins: food.proteins ?? null,
    sourceType: food.source_type ?? "manual",
    isOwner: Boolean(food.is_owner),
  };
}

function normalizeOpenFoodFactsProduct(product: OpenFoodFactsProduct): FoodItem {
  const calories = product.nutriments?.energy_kcal_100g ?? product.nutriments?.energy_100g ?? null;

  return {
    id: null,
    barcode: product.code ?? "",
    name: product.product_name?.trim() || "Produit sans nom",
    brand: product.brands?.split(",").map((value) => value.trim()).filter(Boolean)[0] || null,
    imageUrl: product.image_front_url || product.image_url || null,
    calories: calories != null ? Math.round(calories) : null,
    fat: product.nutriments?.fat_100g != null ? Number(product.nutriments.fat_100g.toFixed(1)) : null,
    carbs:
      product.nutriments?.carbohydrates_100g != null
        ? Number(product.nutriments.carbohydrates_100g.toFixed(1))
        : null,
    proteins: product.nutriments?.proteins_100g != null ? Number(product.nutriments.proteins_100g.toFixed(1)) : null,
    sourceType: "open_food_facts",
    isOwner: false,
  };
}

async function fetchFoodFromOpenFoodFacts(barcode: string, timeoutMs = 3500): Promise<FoodItem | null> {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

  let response: Response;
  try {
    response = await fetch(`https://world.openfoodfacts.org/api/v2/product/${barcode}.json`, {
      signal: controller.signal,
    });
  } finally {
    clearTimeout(timeoutId);
  }

  if (!response.ok) {
    throw new Error("Impossible de joindre Open Food Facts.");
  }

  const payload = (await response.json()) as OpenFoodFactsResponse;

  if (payload.status !== 1 || !payload.product) {
    return null;
  }

  return normalizeOpenFoodFactsProduct(payload.product);
}

function emptyDraft(barcode = ""): FormState {
  return {
    barcode,
    name: "",
    brand: "",
    imageUrl: "",
    calories: "",
    fat: "",
    carbs: "",
    proteins: "",
  };
}

export default function FoodEanSearch() {
  const [ean, setEan] = useState("");
  const [loading, setLoading] = useState(false);
  const [enriching, setEnriching] = useState(false);
  const [saving, setSaving] = useState(false);
  const [currentFood, setCurrentFood] = useState<FoodItem | null>(null);
  const [draft, setDraft] = useState<FormState | null>(null);
  const [errorMessage, setErrorMessage] = useState("");
  const [successMessage, setSuccessMessage] = useState("");
  const [creationOpen, setCreationOpen] = useState(false);
  const [editing, setEditing] = useState(false);
  const requestIdRef = useRef(0);
  const publicCacheRef = useRef<Record<string, FoodItem>>({});
  const offCacheRef = useRef<Record<string, FoodItem | null>>({});

  async function enrichFromOpenFoodFacts(barcode: string, requestId: number) {
    setEnriching(true);

    try {
      let offFood: FoodItem | null;

      if (barcode in offCacheRef.current) {
        offFood = offCacheRef.current[barcode];
      } else {
        offFood = await fetchFoodFromOpenFoodFacts(barcode);
        offCacheRef.current[barcode] = offFood;
      }

      if (requestIdRef.current !== requestId || !offFood) {
        return;
      }

      setCurrentFood(offFood);
      setDraft({
        barcode: offFood.barcode || barcode,
        name: offFood.name,
        brand: offFood.brand ?? "",
        imageUrl: offFood.imageUrl ?? "",
        calories: offFood.calories != null ? String(offFood.calories) : "",
        fat: offFood.fat != null ? String(offFood.fat) : "",
        carbs: offFood.carbs != null ? String(offFood.carbs) : "",
        proteins: offFood.proteins != null ? String(offFood.proteins) : "",
      });
      setCreationOpen(true);
      setEditing(false);
      setSuccessMessage("Produit trouvé sur Open Food Facts. Tu peux l'ajouter à la base publique.");
      setErrorMessage("");
    } catch {
      if (requestIdRef.current !== requestId) {
        return;
      }
    } finally {
      if (requestIdRef.current === requestId) {
        setEnriching(false);
      }
    }
  }

  async function handleSearch(barcode: string) {
    const trimmedBarcode = barcode.trim();
    const requestId = requestIdRef.current + 1;
    requestIdRef.current = requestId;

    if (trimmedBarcode.length < 8) {
      setErrorMessage("Saisis un code EAN valide.");
      setSuccessMessage("");
      setCurrentFood(null);
      setDraft(null);
      setCreationOpen(false);
      setEditing(false);
      return;
    }

    setLoading(true);
    setEnriching(false);
    setErrorMessage("");
    setSuccessMessage("");

    let publicFood: FoodItem | null = null;
    let publicCatalogUnavailable = false;

    try {
      if (publicCacheRef.current[trimmedBarcode]) {
        publicFood = publicCacheRef.current[trimmedBarcode];
      } else {
        publicFood = await fetchFoodFromPublicCatalog(trimmedBarcode);
        if (publicFood) {
          publicCacheRef.current[trimmedBarcode] = publicFood;
        }
      }
    } catch {
      publicCatalogUnavailable = true;
    } finally {
      if (requestIdRef.current === requestId) {
        setLoading(false);
      }
    }

    if (requestIdRef.current !== requestId) {
      return;
    }

    if (publicFood) {
      setCurrentFood(publicFood);
      setDraft(null);
      setCreationOpen(false);
      setEditing(false);
      return;
    }

    setCurrentFood(null);
    setDraft(emptyDraft(trimmedBarcode));
    setCreationOpen(true);
    setEditing(false);
    setErrorMessage(
      publicCatalogUnavailable
        ? "Base publique temporairement indisponible. Recherche Open Food Facts en cours..."
        : "Produit absent de la base publique. Recherche Open Food Facts en cours...",
    );
    void enrichFromOpenFoodFacts(trimmedBarcode, requestId);
  }

  async function handleCreateProduct() {
    const token = localStorage.getItem("token");
    if (!token) {
      setErrorMessage("Session invalide. Reconnecte-toi pour ajouter un produit.");
      return;
    }

    if (!draft?.barcode.trim() || !draft.name.trim()) {
      setErrorMessage("Le code EAN et le nom sont obligatoires pour créer le produit.");
      return;
    }

    setSaving(true);
    setErrorMessage("");
    setSuccessMessage("");

    try {
      const response = await fetch("/api/foods", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          barcode: draft.barcode.trim(),
          name: draft.name.trim(),
          brand: draft.brand.trim() || null,
          image_url: draft.imageUrl.trim() || null,
          calories: toNumber(draft.calories),
          fat: toNumber(draft.fat),
          carbs: toNumber(draft.carbs),
          proteins: toNumber(draft.proteins),
          source_type: currentFood?.sourceType === "open_food_facts" ? "open_food_facts" : "manual",
        }),
      });

      const payload = (await response.json()) as BackendFoodResponse;

      if (!response.ok) {
        throw new Error(payload.message || "Impossible d'enregistrer le produit.");
      }

      const food = payload.data;
      if (food) {
        const normalizedFood: FoodItem = {
          id: food.id ?? null,
          barcode: food.barcode,
          name: food.name,
          brand: food.brand ?? null,
          imageUrl: food.image_url ?? null,
          calories: food.calories ?? null,
          fat: food.fat ?? null,
          carbs: food.carbs ?? null,
          proteins: food.proteins ?? null,
          sourceType: food.source_type ?? "manual",
          isOwner: Boolean(food.is_owner),
        };

        setCurrentFood(normalizedFood);
        publicCacheRef.current[normalizedFood.barcode] = normalizedFood;
      }

      setSuccessMessage("Produit enregistré dans la base publique.");
      setCreationOpen(false);
      setEditing(false);
      setDraft(null);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Impossible d'enregistrer le produit.");
    } finally {
      setSaving(false);
    }
  }

  async function handleUpdateProduct() {
    const token = localStorage.getItem("token");
    if (!token) {
      setErrorMessage("Session invalide. Reconnecte-toi pour modifier un produit.");
      return;
    }

    if (!currentFood?.id || !draft?.barcode.trim() || !draft.name.trim()) {
      setErrorMessage("Informations insuffisantes pour modifier le produit.");
      return;
    }

    setSaving(true);
    setErrorMessage("");
    setSuccessMessage("");

    try {
      const response = await fetch(`/api/foods/${currentFood.id}`, {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          barcode: draft.barcode.trim(),
          name: draft.name.trim(),
          brand: draft.brand.trim() || null,
          image_url: draft.imageUrl.trim() || null,
          calories: toNumber(draft.calories),
          fat: toNumber(draft.fat),
          carbs: toNumber(draft.carbs),
          proteins: toNumber(draft.proteins),
        }),
      });

      const payload = (await response.json()) as BackendFoodResponse;
      if (!response.ok) {
        throw new Error(payload.message || "Modification impossible.");
      }

      const food = payload.data;
      if (food) {
        const normalizedFood: FoodItem = {
          id: food.id ?? null,
          barcode: food.barcode,
          name: food.name,
          brand: food.brand ?? null,
          imageUrl: food.image_url ?? null,
          calories: food.calories ?? null,
          fat: food.fat ?? null,
          carbs: food.carbs ?? null,
          proteins: food.proteins ?? null,
          sourceType: food.source_type ?? "manual",
          isOwner: Boolean(food.is_owner),
        };

        setCurrentFood(normalizedFood);
        publicCacheRef.current[normalizedFood.barcode] = normalizedFood;
      }

      setSuccessMessage("Produit mis à jour.");
      setCreationOpen(false);
      setEditing(false);
      setDraft(null);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Modification impossible.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[0.95fr_1.05fr]">
      <section className="rounded-[2rem] border border-emerald-100 bg-emerald-50/60 p-5 shadow-[0_18px_45px_rgba(15,23,42,0.06)]">
        <div className="space-y-2">
          <p className="text-sm font-semibold uppercase tracking-[0.14em] text-emerald-700">Recherche d’aliments</p>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-950">Base publique par EAN</h1>
          <p className="text-sm leading-6 text-slate-600">
            Cherche d’abord dans la base publique du projet. Si le produit n’existe pas, il peut être ajouté pour tous les clients.
          </p>
        </div>

        <div className="mt-6 space-y-3 rounded-[1.5rem] border border-white/70 bg-white p-4">
          <label className="block text-sm font-medium text-slate-700" htmlFor="ean">
            Code EAN
          </label>
          <div className="flex flex-col gap-3 sm:flex-row">
            <input
              id="ean"
              value={ean}
              onChange={(event) => setEan(event.target.value.replace(/\s+/g, ""))}
              inputMode="numeric"
              placeholder="Ex: 3017620422003"
              className="min-w-0 flex-1 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10"
            />
            <button
              type="button"
              onClick={() => void handleSearch(ean)}
              disabled={loading}
              className="rounded-2xl bg-emerald-700 px-5 py-3 text-sm font-medium text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {loading ? "Recherche locale..." : "Rechercher"}
            </button>
          </div>

          {enriching ? <p className="text-xs text-slate-500">Vérification Open Food Facts en arrière-plan...</p> : null}

          <div className="flex flex-wrap items-center gap-3">
            <button
              type="button"
              onClick={() => {
                setCreationOpen((prev) => !prev);
                setDraft((prev) => prev ?? emptyDraft(ean.trim()));
                setEditing(false);
              }}
              className="rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-medium text-slate-700 transition hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-800"
            >
              Créer dans la base publique
            </button>

            <span className="text-xs text-slate-500">
              Si le produit n’existe pas, tu peux le créer pour qu’il soit disponible à tous les clients.
            </span>
          </div>

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

        {creationOpen ? (
          <div className="mt-5 space-y-4 rounded-[1.5rem] border border-slate-200 bg-white p-4 shadow-[0_10px_30px_rgba(15,23,42,0.05)]">
            <div>
              <p className="text-sm font-semibold text-slate-900">Créer le produit</p>
              {editing ? <p className="text-xs text-amber-700">Mode édition: réservé au créateur</p> : null}
              <p className="mt-1 text-sm text-slate-600">
                Complète les informations minimales pour l’ajouter à la base publique.
              </p>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="EAN" value={draft?.barcode ?? ""} onChange={(value) => setDraft((prev) => ({ ...(prev ?? emptyDraft()), barcode: value }))} />
              <Field label="Nom" value={draft?.name ?? ""} onChange={(value) => setDraft((prev) => ({ ...(prev ?? emptyDraft()), name: value }))} required />
              <Field label="Marque" value={draft?.brand ?? ""} onChange={(value) => setDraft((prev) => ({ ...(prev ?? emptyDraft()), brand: value }))} />
              <Field label="Image URL" value={draft?.imageUrl ?? ""} onChange={(value) => setDraft((prev) => ({ ...(prev ?? emptyDraft()), imageUrl: value }))} />
              <Field label="Calories (kcal/100g)" value={draft?.calories ?? ""} onChange={(value) => setDraft((prev) => ({ ...(prev ?? emptyDraft()), calories: value }))} />
              <Field label="Lipides (g/100g)" value={draft?.fat ?? ""} onChange={(value) => setDraft((prev) => ({ ...(prev ?? emptyDraft()), fat: value }))} />
              <Field label="Glucides (g/100g)" value={draft?.carbs ?? ""} onChange={(value) => setDraft((prev) => ({ ...(prev ?? emptyDraft()), carbs: value }))} />
              <Field label="Protéines (g/100g)" value={draft?.proteins ?? ""} onChange={(value) => setDraft((prev) => ({ ...(prev ?? emptyDraft()), proteins: value }))} />
            </div>

            <div className="flex flex-wrap gap-3">
              <button
                type="button"
                onClick={() => (editing ? void handleUpdateProduct() : void handleCreateProduct())}
                disabled={saving}
                className="rounded-2xl bg-slate-950 px-5 py-3 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {saving ? "Enregistrement..." : editing ? "Enregistrer les modifications" : "Enregistrer dans la base publique"}
              </button>
              <button
                type="button"
                onClick={() => {
                  setCreationOpen(false);
                  setEditing(false);
                  setDraft(null);
                }}
                className="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
              >
                Annuler
              </button>
            </div>
          </div>
        ) : null}

        <div className="mt-5 grid gap-3 sm:grid-cols-2">
          {[
            { label: "Calories", value: "kcal / 100g" },
            { label: "Lipides", value: "g / 100g" },
            { label: "Glucides", value: "g / 100g" },
            { label: "Protéines", value: "g / 100g" },
          ].map((item) => (
            <div key={item.label} className="rounded-2xl border border-slate-200 bg-white px-4 py-3">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">{item.label}</p>
              <p className="mt-1 text-sm font-semibold text-slate-900">{item.value}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="rounded-[2rem] border border-slate-200 bg-white p-5 shadow-[0_18px_45px_rgba(15,23,42,0.06)]">
        {!currentFood ? (
          <div className="grid h-full min-h-[360px] place-items-center rounded-[1.5rem] border border-dashed border-slate-200 bg-slate-50 px-6 text-center text-sm text-slate-500">
            Lance une recherche pour afficher la fiche nutritionnelle du produit.
          </div>
        ) : (
          <div className="space-y-5">
            <div className="flex flex-col gap-4 rounded-[1.5rem] border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center">
              <div className="grid h-24 w-24 shrink-0 place-items-center overflow-hidden rounded-2xl border border-white bg-white">
                {currentFood.imageUrl ? (
                  <img src={currentFood.imageUrl} alt={currentFood.name} className="h-full w-full object-cover" />
                ) : (
                  <span className="text-xs font-semibold text-slate-500">Sans image</span>
                )}
              </div>

              <div className="min-w-0 flex-1">
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-emerald-700">
                  {currentFood.sourceType === "open_food_facts" ? "Produit Open Food Facts" : "Produit de la base publique"}
                </p>
                <h2 className="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{currentFood.name}</h2>
                <p className="mt-1 text-sm text-slate-600">{currentFood.brand ?? "Marque inconnue"}</p>
                <p className="mt-2 text-xs text-slate-500">EAN {currentFood.barcode}</p>
              </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard label="Calories" value={currentFood.calories != null ? `${currentFood.calories} kcal` : "N/A"} tone="emerald" />
              <StatCard label="Lipides" value={currentFood.fat != null ? `${currentFood.fat} g` : "N/A"} tone="amber" />
              <StatCard label="Glucides" value={currentFood.carbs != null ? `${currentFood.carbs} g` : "N/A"} tone="sky" />
              <StatCard label="Protéines" value={currentFood.proteins != null ? `${currentFood.proteins} g` : "N/A"} tone="rose" />
            </div>

            {currentFood.sourceType === "open_food_facts" ? (
              <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Ce produit vient d’Open Food Facts. Clique sur "Enregistrer dans la base publique" pour le partager à tous les clients.
              </div>
            ) : null}

            {currentFood.sourceType !== "open_food_facts" ? (
              <div className="flex flex-wrap items-center gap-3">
                <button
                  type="button"
                  disabled={!currentFood.isOwner}
                  onClick={() => {
                    setDraft({
                      barcode: currentFood.barcode,
                      name: currentFood.name,
                      brand: currentFood.brand ?? "",
                      imageUrl: currentFood.imageUrl ?? "",
                      calories: currentFood.calories != null ? String(currentFood.calories) : "",
                      fat: currentFood.fat != null ? String(currentFood.fat) : "",
                      carbs: currentFood.carbs != null ? String(currentFood.carbs) : "",
                      proteins: currentFood.proteins != null ? String(currentFood.proteins) : "",
                    });
                    setCreationOpen(true);
                    setEditing(true);
                  }}
                  className="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  Modifier cet aliment
                </button>
                {!currentFood.isOwner ? (
                  <span className="text-xs text-slate-500">Seul le créateur peut modifier cet aliment.</span>
                ) : null}
              </div>
            ) : null}

          </div>
        )}
      </section>
    </div>
  );
}

function Field({
  label,
  value,
  onChange,
  required = false,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  required?: boolean;
}) {
  return (
    <label className="space-y-1.5">
      <span className="block text-sm font-medium text-slate-700">
        {label}
        {required ? <span className="ml-1 text-rose-500">*</span> : null}
      </span>
      <input
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10"
      />
    </label>
  );
}

function StatCard({
  label,
  value,
  tone,
}: {
  label: string;
  value: string;
  tone: "emerald" | "amber" | "sky" | "rose";
}) {
  const toneClasses = {
    emerald: "border-emerald-200 bg-emerald-50 text-emerald-900",
    amber: "border-amber-200 bg-amber-50 text-amber-900",
    sky: "border-sky-200 bg-sky-50 text-sky-900",
    rose: "border-rose-200 bg-rose-50 text-rose-900",
  }[tone];

  return (
    <div className={`rounded-2xl border px-4 py-3 ${toneClasses}`}>
      <p className="text-xs uppercase tracking-[0.14em] opacity-80">{label}</p>
      <p className="mt-1 text-xl font-semibold">{value}</p>
    </div>
  );
}
