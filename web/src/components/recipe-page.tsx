"use client";

import Image from "next/image";
import { useEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";

type RecipeIngredient = {
  name: string;
  ean: string | null;
  amount: number | null;
  unit: string | null;
};

type RecipeRecord = {
  id: number;
  title: string;
  description: string | null;
  prep_time_minutes: number | null;
  calories: number;
  image_url: string | null;
  ingredients: RecipeIngredient[];
  ingredients_count: number;
  is_public: boolean;
  is_owner: boolean;
  created_by_user_id: number | null;
  created_at: string | null;
  updated_at: string | null;
};

type RecipeIngredientDraft = {
  name: string;
  amount: string;
  unit: string;
};

type RecipeFormState = {
  title: string;
  description: string;
  calories: string;
  prepTimeMinutes: string;
  imageUrl: string;
  isPublic: boolean;
  ingredients: RecipeIngredientDraft[];
};

type ApiResult = {
  message?: string;
  data?: RecipeRecord[] | RecipeRecord;
};

type FoodSearchItem = {
  calories?: number | null;
  fat?: number | null;
  carbs?: number | null;
  proteins?: number | null;
};

type FoodSearchResult = {
  data?: FoodSearchItem[];
};

type NutritionPer100 = {
  calories: number | null;
  fat: number | null;
  carbs: number | null;
  proteins: number | null;
};

type ActiveRecipeNutrition = {
  fat: number;
  carbs: number;
  proteins: number;
  resolvedCount: number;
  totalCount: number;
};

const emptyIngredient = (): RecipeIngredientDraft => ({
  name: "",
  amount: "",
  unit: "",
});

const emptyForm = (): RecipeFormState => ({
  title: "",
  description: "",
  calories: "",
  prepTimeMinutes: "",
  imageUrl: "",
  isPublic: false,
  ingredients: [emptyIngredient()],
});

function buildIngredientExpansionState(ingredients: RecipeIngredientDraft[]) {
  if (ingredients.length <= 4) {
    return ingredients.map(() => true);
  }

  return ingredients.map((_, index) => index < 2);
}

function ingredientPreviewLabel(ingredient: RecipeIngredientDraft) {
  const name = ingredient.name.trim() || "Aliment sans nom";
  const amount = ingredient.amount.trim();
  const unit = ingredient.unit.trim();

  if (!amount && !unit) {
    return name;
  }

  return `${name} · ${amount || "?"}${unit ? ` ${unit}` : ""}`;
}

function formatCalories(value: number | string) {
  const numberValue = typeof value === "number" ? value : Number(value);
  if (Number.isNaN(numberValue)) {
    return "0 kcal";
  }

  return `${Math.round(numberValue).toLocaleString("fr-FR")} kcal`;
}

export default function RecipePage() {
  const router = useRouter();
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const caloriesCacheRef = useRef<Record<string, number | null>>({});
  const nutritionCacheRef = useRef<Record<string, NutritionPer100 | null>>({});
  const [recipes, setRecipes] = useState<RecipeRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [autoCalculatingCalories, setAutoCalculatingCalories] = useState(false);
  const [calorieHint, setCalorieHint] = useState("");
  const [activeRecipeNutritionLoading, setActiveRecipeNutritionLoading] = useState(false);
  const [activeRecipeNutrition, setActiveRecipeNutrition] = useState<ActiveRecipeNutrition | null>(null);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");
  const [filter, setFilter] = useState<"public" | "mine" | "all">("public");
  const [createOpen, setCreateOpen] = useState(false);
  const [dragActive, setDragActive] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [featuredRecipeId, setFeaturedRecipeId] = useState<number | null>(null);
  const [form, setForm] = useState<RecipeFormState>(emptyForm());
  const [expandedIngredients, setExpandedIngredients] = useState<boolean[]>([true]);

  const publicRecipes = useMemo(() => recipes.filter((recipe) => recipe.is_public), [recipes]);
  const myRecipes = recipes.filter((recipe) => recipe.is_owner);
  const privateRecipes = recipes.filter((recipe) => recipe.is_owner && !recipe.is_public);
  const visibleRecipes = useMemo(() => {
    if (filter === "mine") {
      return myRecipes;
    }

    if (filter === "all") {
      return recipes;
    }

    return publicRecipes;
  }, [filter, myRecipes, publicRecipes, recipes]);

  const activeRecipe = useMemo(() => {
    if (featuredRecipeId !== null) {
      return recipes.find((recipe) => recipe.id === featuredRecipeId) ?? publicRecipes[0] ?? visibleRecipes[0] ?? null;
    }

    return publicRecipes[0] ?? visibleRecipes[0] ?? null;
  }, [featuredRecipeId, publicRecipes, recipes, visibleRecipes]);

  useEffect(() => {
    const token = localStorage.getItem("token");

    if (!token) {
      router.replace("/login");
      return;
    }

    let isMounted = true;

    async function loadRecipes() {
      try {
        const response = await fetch("/api/recipes", {
          method: "GET",
          headers: {
            Accept: "application/json",
            Authorization: `Bearer ${token}`,
          },
        });

        const payload = (await response.json()) as ApiResult;

        if (!response.ok) {
          throw new Error(payload.message || "Impossible de charger les recettes.");
        }

        if (!isMounted) {
          return;
        }

        const nextRecipes = Array.isArray(payload.data) ? payload.data : [];
        setRecipes(nextRecipes);
        setError("");
      } catch (loadError) {
        if (isMounted) {
          setError(loadError instanceof Error ? loadError.message : "Impossible de charger les recettes.");
        }
      } finally {
        if (isMounted) {
          setLoading(false);
        }
      }
    }

    loadRecipes();

    return () => {
      isMounted = false;
    };
  }, [router]);

  function resetForm() {
    setEditingId(null);
    setForm(emptyForm());
    setExpandedIngredients([true]);
    setSuccess("");
    setError("");
  }

  function openCreateModal() {
    resetForm();
    setCreateOpen(true);
  }

  function startEdit(recipe: RecipeRecord) {
    const ingredientsDraft =
      recipe.ingredients.length > 0
        ? recipe.ingredients.map((ingredient) => ({
            name: ingredient.ean ?? ingredient.name,
            amount: ingredient.amount === null ? "" : String(ingredient.amount),
            unit: ingredient.unit ?? "",
          }))
        : [emptyIngredient()];

    setEditingId(recipe.id);
    setFeaturedRecipeId(recipe.id);
    setForm({
      title: recipe.title,
      description: recipe.description ?? "",
      calories: String(recipe.calories),
      prepTimeMinutes: recipe.prep_time_minutes ? String(recipe.prep_time_minutes) : "",
      imageUrl: recipe.image_url ?? "",
      isPublic: recipe.is_public,
      ingredients: ingredientsDraft,
    });
    setExpandedIngredients(buildIngredientExpansionState(ingredientsDraft));
    setSuccess("");
    setError("");
    setCreateOpen(true);
  }

  async function readFileAsDataUrl(file: File) {
    return await new Promise<string>((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(typeof reader.result === "string" ? reader.result : "");
      reader.onerror = () => reject(new Error("Impossible de lire le fichier image."));
      reader.readAsDataURL(file);
    });
  }

  async function setImageFile(file: File | null) {
    if (!file) {
      return;
    }

    if (!file.type.startsWith("image/")) {
      setError("Le fichier déposé doit être une image.");
      return;
    }

    if (file.size > 5 * 1024 * 1024) {
      setError("L'image doit faire moins de 5 Mo.");
      return;
    }

    const dataUrl = await readFileAsDataUrl(file);
    setForm((current) => ({ ...current, imageUrl: dataUrl }));
    setError("");
  }

  async function lookupIngredientByEan(index: number) {
    const ingredient = form.ingredients[index];
    const rawInput = ingredient?.name.trim() ?? "";
    const extractedBarcode = rawInput.match(/\d{8,14}/)?.[0] ?? rawInput;
    const barcode = extractedBarcode.trim();

    if (!/^\d{8,14}$/.test(barcode)) {
      setError("Saisis un EAN pour rechercher l'aliment.");
      return;
    }

    const token = localStorage.getItem("token");

    try {
      let foodNameFromEan: string | null = null;
      let foodCaloriesFromEan: number | null = null;

      const response = await fetch(`/api/foods/barcode/${encodeURIComponent(barcode)}`, {
        headers: {
          Accept: "application/json",
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
      });

      if (response.ok) {
        const payload = (await response.json()) as { data?: { name?: string; calories?: number | null } };
        const localFoodName = payload.data?.name?.trim();
        const localCalories = payload.data?.calories;

        if (localFoodName) {
          foodNameFromEan = localFoodName;
        }

        if (localCalories !== null && localCalories !== undefined && !Number.isNaN(Number(localCalories))) {
          foodCaloriesFromEan = Number(localCalories);
        }
      }

      // Fallback to Open Food Facts when local public catalog has no match.
      if (!foodNameFromEan) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 4500);

        try {
          const offResponse = await fetch(`https://world.openfoodfacts.org/api/v2/product/${barcode}.json`, {
            signal: controller.signal,
          });

          if (offResponse.ok) {
            const offPayload = (await offResponse.json()) as {
              status?: number;
              product?: {
                product_name?: string;
                nutriments?: { energy_kcal_100g?: number; energy_100g?: number };
              };
            };

            if (offPayload.status === 1) {
              const offName = offPayload.product?.product_name?.trim();
              const offCalories = offPayload.product?.nutriments?.energy_kcal_100g ?? offPayload.product?.nutriments?.energy_100g ?? null;
              if (offName) {
                foodNameFromEan = offName;
              }
              if (offCalories !== null && !Number.isNaN(Number(offCalories))) {
                foodCaloriesFromEan = Number(offCalories);
              }
            }
          }
        } catch {
          // Ignore fallback network issues and report a user-friendly message below.
        } finally {
          clearTimeout(timeoutId);
        }
      }

      if (!foodNameFromEan) {
        throw new Error("Produit introuvable pour cet EAN.");
      }

      if (foodCaloriesFromEan !== null) {
        caloriesCacheRef.current[`ean:${barcode}`] = foodCaloriesFromEan;
        caloriesCacheRef.current[`name:${foodNameFromEan.toLowerCase()}`] = foodCaloriesFromEan;
      }

      setForm((current) => ({
        ...current,
        ingredients: current.ingredients.map((item, itemIndex) =>
          itemIndex === index
            ? {
                ...item,
                name: foodNameFromEan!,
              }
            : item,
        ),
      }));
      setError("");
      setSuccess(`Aliment chargé depuis l'EAN ${barcode}.`);
    } catch (lookupError) {
      setError(lookupError instanceof Error ? lookupError.message : "Impossible de charger cet EAN.");
    }
  }

  async function handleFileChange(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0] ?? null;
    await setImageFile(file);
    event.target.value = "";
  }

  async function handleDrop(event: React.DragEvent<HTMLLabelElement>) {
    event.preventDefault();
    setDragActive(false);

    const file = event.dataTransfer.files?.[0] ?? null;
    await setImageFile(file);
  }

  function handleDragOver(event: React.DragEvent<HTMLLabelElement>) {
    event.preventDefault();
    setDragActive(true);
  }

  function handleDragLeave() {
    setDragActive(false);
  }

  function addIngredient() {
    setForm((current) => ({
      ...current,
      ingredients: [...current.ingredients, emptyIngredient()],
    }));
    setExpandedIngredients((current) => [...current, true]);
  }

  function updateIngredient(index: number, field: keyof RecipeIngredientDraft, value: string) {
    setForm((current) => ({
      ...current,
      ingredients: current.ingredients.map((ingredient, ingredientIndex) =>
        ingredientIndex === index ? { ...ingredient, [field]: value } : ingredient,
      ),
    }));
  }

  function extractBarcodeFromText(value: string) {
    return value.match(/\d{8,14}/)?.[0] ?? null;
  }

  function normalizeUnit(unitRaw: string) {
    return unitRaw.trim().toLowerCase();
  }

  function computeServingFactor(amountValue: string, unitValue: string) {
    const amount = Number(amountValue);
    const hasAmount = !Number.isNaN(amount) && amount > 0;

    if (!hasAmount) {
      return 1;
    }

    const unit = normalizeUnit(unitValue);

    if (unit === "kg" || unit.includes("kilo")) {
      return amount * 10;
    }

    if (unit === "g" || unit.includes("gram")) {
      return amount / 100;
    }

    if (unit === "ml") {
      return amount / 100;
    }

    if (unit === "cl") {
      return amount / 10;
    }

    if (unit === "l") {
      return amount * 10;
    }

    // Fallback: treat an explicit numeric quantity as grams-equivalent.
    return amount / 100;
  }

  async function fetchCaloriesFromOpenFoodFacts(barcode: string) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 4500);

    try {
      const response = await fetch(`https://world.openfoodfacts.org/api/v2/product/${barcode}.json`, {
        signal: controller.signal,
      });

      if (!response.ok) {
        return null;
      }

      const payload = (await response.json()) as {
        status?: number;
        product?: {
          nutriments?: {
            energy_kcal_100g?: number;
            energy_100g?: number;
          };
        };
      };

      if (payload.status !== 1) {
        return null;
      }

      const kcal100 = payload.product?.nutriments?.energy_kcal_100g ?? payload.product?.nutriments?.energy_100g ?? null;
      return kcal100 !== null && !Number.isNaN(Number(kcal100)) ? Number(kcal100) : null;
    } catch {
      return null;
    } finally {
      clearTimeout(timeoutId);
    }
  }

  async function fetchNutritionFromOpenFoodFacts(barcode: string): Promise<NutritionPer100 | null> {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 4500);

    try {
      const response = await fetch(`https://world.openfoodfacts.org/api/v2/product/${barcode}.json`, {
        signal: controller.signal,
      });

      if (!response.ok) {
        return null;
      }

      const payload = (await response.json()) as {
        status?: number;
        product?: {
          nutriments?: {
            energy_kcal_100g?: number;
            energy_100g?: number;
            fat_100g?: number;
            carbohydrates_100g?: number;
            proteins_100g?: number;
          };
        };
      };

      if (payload.status !== 1) {
        return null;
      }

      const nutriments = payload.product?.nutriments;
      if (!nutriments) {
        return null;
      }

      const calories = nutriments.energy_kcal_100g ?? nutriments.energy_100g ?? null;
      const fat = nutriments.fat_100g ?? null;
      const carbs = nutriments.carbohydrates_100g ?? null;
      const proteins = nutriments.proteins_100g ?? null;

      if (calories === null && fat === null && carbs === null && proteins === null) {
        return null;
      }

      return {
        calories: calories !== null && !Number.isNaN(Number(calories)) ? Number(calories) : null,
        fat: fat !== null && !Number.isNaN(Number(fat)) ? Number(fat) : null,
        carbs: carbs !== null && !Number.isNaN(Number(carbs)) ? Number(carbs) : null,
        proteins: proteins !== null && !Number.isNaN(Number(proteins)) ? Number(proteins) : null,
      };
    } catch {
      return null;
    } finally {
      clearTimeout(timeoutId);
    }
  }

  async function resolveNutritionPer100(ingredientName: string): Promise<NutritionPer100 | null> {
    const nameTrimmed = ingredientName.trim();

    if (!nameTrimmed) {
      return null;
    }

    const token = localStorage.getItem("token");
    const barcode = extractBarcodeFromText(nameTrimmed);

    if (barcode) {
      const barcodeCacheKey = `ean:${barcode}`;
      if (barcodeCacheKey in nutritionCacheRef.current) {
        return nutritionCacheRef.current[barcodeCacheKey];
      }

      let result: NutritionPer100 | null = null;

      try {
        const response = await fetch(`/api/foods/barcode/${encodeURIComponent(barcode)}`, {
          headers: {
            Accept: "application/json",
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
          },
        });

        if (response.ok) {
          const payload = (await response.json()) as {
            data?: {
              calories?: number | null;
              fat?: number | null;
              carbs?: number | null;
              proteins?: number | null;
            };
          };

          const item = payload.data;
          if (item) {
            result = {
              calories: item.calories !== null && item.calories !== undefined && !Number.isNaN(Number(item.calories)) ? Number(item.calories) : null,
              fat: item.fat !== null && item.fat !== undefined && !Number.isNaN(Number(item.fat)) ? Number(item.fat) : null,
              carbs: item.carbs !== null && item.carbs !== undefined && !Number.isNaN(Number(item.carbs)) ? Number(item.carbs) : null,
              proteins: item.proteins !== null && item.proteins !== undefined && !Number.isNaN(Number(item.proteins)) ? Number(item.proteins) : null,
            };

            if (result.calories === null && result.fat === null && result.carbs === null && result.proteins === null) {
              result = null;
            }
          }
        }
      } catch {
        result = null;
      }

      if (!result) {
        result = await fetchNutritionFromOpenFoodFacts(barcode);
      }

      nutritionCacheRef.current[barcodeCacheKey] = result;
      return result;
    }

    const nameCacheKey = `name:${nameTrimmed.toLowerCase()}`;
    if (nameCacheKey in nutritionCacheRef.current) {
      return nutritionCacheRef.current[nameCacheKey];
    }

    let result: NutritionPer100 | null = null;

    try {
      const response = await fetch(`/api/foods/search?q=${encodeURIComponent(nameTrimmed)}`, {
        headers: {
          Accept: "application/json",
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
      });

      if (response.ok) {
        const payload = (await response.json()) as FoodSearchResult;
        const firstWithNutrition = (payload.data ?? []).find((item) => {
          const hasCalories = item.calories !== null && item.calories !== undefined && !Number.isNaN(Number(item.calories));
          const hasFat = item.fat !== null && item.fat !== undefined && !Number.isNaN(Number(item.fat));
          const hasCarbs = item.carbs !== null && item.carbs !== undefined && !Number.isNaN(Number(item.carbs));
          const hasProteins = item.proteins !== null && item.proteins !== undefined && !Number.isNaN(Number(item.proteins));
          return hasCalories || hasFat || hasCarbs || hasProteins;
        });

        if (firstWithNutrition) {
          result = {
            calories: firstWithNutrition.calories !== null && firstWithNutrition.calories !== undefined && !Number.isNaN(Number(firstWithNutrition.calories)) ? Number(firstWithNutrition.calories) : null,
            fat: firstWithNutrition.fat !== null && firstWithNutrition.fat !== undefined && !Number.isNaN(Number(firstWithNutrition.fat)) ? Number(firstWithNutrition.fat) : null,
            carbs: firstWithNutrition.carbs !== null && firstWithNutrition.carbs !== undefined && !Number.isNaN(Number(firstWithNutrition.carbs)) ? Number(firstWithNutrition.carbs) : null,
            proteins: firstWithNutrition.proteins !== null && firstWithNutrition.proteins !== undefined && !Number.isNaN(Number(firstWithNutrition.proteins)) ? Number(firstWithNutrition.proteins) : null,
          };
        }
      }
    } catch {
      result = null;
    }

    nutritionCacheRef.current[nameCacheKey] = result;
    return result;
  }

  async function resolveCaloriesPer100(ingredientName: string) {
    const nutrition = await resolveNutritionPer100(ingredientName);
    return nutrition?.calories ?? null;
  }

  useEffect(() => {
    if (!activeRecipe) {
      setActiveRecipeNutrition(null);
      return;
    }

    let cancelled = false;

    async function computeActiveRecipeNutrition() {
      const activeIngredients = (activeRecipe.ingredients ?? []).filter((ingredient) => (ingredient.ean ?? ingredient.name).trim().length > 0);

      if (activeIngredients.length === 0) {
        setActiveRecipeNutrition({
          fat: 0,
          carbs: 0,
          proteins: 0,
          resolvedCount: 0,
          totalCount: 0,
        });
        return;
      }

      setActiveRecipeNutritionLoading(true);

      let totalFat = 0;
      let totalCarbs = 0;
      let totalProteins = 0;
      let resolvedCount = 0;

      for (const ingredient of activeIngredients) {
        const identifier = (ingredient.ean ?? ingredient.name).trim();
        const nutrition = await resolveNutritionPer100(identifier);

        if (cancelled) {
          return;
        }

        if (!nutrition) {
          continue;
        }

        const factor = computeServingFactor(String(ingredient.amount ?? ""), ingredient.unit ?? "");
        const hasAnyMacro = nutrition.fat !== null || nutrition.carbs !== null || nutrition.proteins !== null;

        if (hasAnyMacro) {
          totalFat += (nutrition.fat ?? 0) * factor;
          totalCarbs += (nutrition.carbs ?? 0) * factor;
          totalProteins += (nutrition.proteins ?? 0) * factor;
          resolvedCount += 1;
        }
      }

      if (cancelled) {
        return;
      }

      setActiveRecipeNutrition({
        fat: Number(totalFat.toFixed(1)),
        carbs: Number(totalCarbs.toFixed(1)),
        proteins: Number(totalProteins.toFixed(1)),
        resolvedCount,
        totalCount: activeIngredients.length,
      });
      setActiveRecipeNutritionLoading(false);
    }

    void computeActiveRecipeNutrition();

    return () => {
      cancelled = true;
      setActiveRecipeNutritionLoading(false);
    };
  }, [activeRecipe]);

  useEffect(() => {
    if (!createOpen) {
      return;
    }

    let cancelled = false;

    async function recalculateCalories() {
      const activeIngredients = form.ingredients.filter((ingredient) => ingredient.name.trim().length > 0);

      if (activeIngredients.length === 0) {
        setCalorieHint("");
        setForm((current) => (current.calories === "0" ? current : { ...current, calories: "0" }));
        return;
      }

      setAutoCalculatingCalories(true);

      let totalCalories = 0;
      let resolvedCount = 0;

      for (const ingredient of activeIngredients) {
        const caloriesPer100 = await resolveCaloriesPer100(ingredient.name);

        if (cancelled) {
          return;
        }

        if (caloriesPer100 === null) {
          continue;
        }

        const factor = computeServingFactor(ingredient.amount, ingredient.unit);
        totalCalories += caloriesPer100 * factor;
        resolvedCount += 1;
      }

      if (cancelled) {
        return;
      }

      const roundedCalories = String(Math.max(0, Math.round(totalCalories)));
      setForm((current) => (current.calories === roundedCalories ? current : { ...current, calories: roundedCalories }));

      if (resolvedCount === 0) {
        setCalorieHint("Calories auto: aucune valeur trouvée pour les aliments saisis.");
      } else if (resolvedCount < activeIngredients.length) {
        setCalorieHint(`Calories auto: ${resolvedCount}/${activeIngredients.length} aliments pris en compte.`);
      } else {
        setCalorieHint(`Calories auto: ${resolvedCount}/${activeIngredients.length} aliments pris en compte.`);
      }

      setAutoCalculatingCalories(false);
    }

    void recalculateCalories();

    return () => {
      cancelled = true;
      setAutoCalculatingCalories(false);
    };
  }, [createOpen, form.ingredients]);

  function removeIngredient(index: number) {
    setExpandedIngredients((current) => {
      if (current.length <= 1) {
        return [true];
      }

      const next = current.filter((_, ingredientIndex) => ingredientIndex !== index);
      return next.length > 0 ? next : [true];
    });

    setForm((current) => {
      if (current.ingredients.length === 1) {
        return { ...current, ingredients: [emptyIngredient()] };
      }

      return {
        ...current,
        ingredients: current.ingredients.filter((_, ingredientIndex) => ingredientIndex !== index),
      };
    });
  }

  function buildPayload() {
    return {
      title: form.title.trim(),
      description: form.description.trim() || null,
      calories: Number(form.calories),
      prep_time_minutes: form.prepTimeMinutes.trim() ? Number(form.prepTimeMinutes) : null,
      image_url: form.imageUrl.trim() || null,
      ingredients: form.ingredients
        .map((ingredient) => ({
          name: ingredient.name.trim(),
          ean: /^\d{8,14}$/.test(ingredient.name.trim()) ? ingredient.name.trim() : null,
          amount: ingredient.amount.trim() ? Number(ingredient.amount) : null,
          unit: ingredient.unit.trim() || null,
        }))
        .filter((ingredient) => ingredient.name.length > 0),
      is_public: form.isPublic,
    };
  }

  function validateClientSide() {
    if (!form.title.trim()) {
      return "Le titre est requis.";
    }

    if (form.calories.trim() === "" || Number.isNaN(Number(form.calories))) {
      return "Les calories sont requises.";
    }

    if (form.isPublic) {
      if (!form.imageUrl.trim()) {
        return "Une photo est requise pour publier une recette publique.";
      }

      if (!form.prepTimeMinutes.trim() || Number.isNaN(Number(form.prepTimeMinutes))) {
        return "Le temps de preparation est requis pour une recette publique.";
      }

      const hasIngredient = form.ingredients.some((ingredient) => ingredient.name.trim().length > 0);

      if (!hasIngredient) {
        return "Une recette publique doit contenir au moins un aliment.";
      }
    }

    return "";
  }

  async function submitRecipe(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const validationMessage = validateClientSide();
    if (validationMessage) {
      setError(validationMessage);
      setSuccess("");
      return;
    }

    const token = localStorage.getItem("token");
    if (!token) {
      router.replace("/login");
      return;
    }

    setSubmitting(true);
    setError("");
    setSuccess("");

    try {
      const payload = buildPayload();
      const response = await fetch(editingId !== null ? `/api/recipes/${editingId}` : "/api/recipes", {
        method: editingId !== null ? "PUT" : "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify(payload),
      });

      const result = (await response.json()) as ApiResult;

      if (!response.ok) {
        throw new Error(result.message || "Impossible d'enregistrer la recette.");
      }

      const savedRecipe = result.data as RecipeRecord;

      setRecipes((current) => {
        const nextRecipes = current.filter((recipe) => recipe.id !== savedRecipe.id);
        return [savedRecipe, ...nextRecipes].sort((left, right) => {
          const leftTime = left.updated_at ? Date.parse(left.updated_at) : 0;
          const rightTime = right.updated_at ? Date.parse(right.updated_at) : 0;
          return rightTime - leftTime;
        });
      });

      setEditingId(null);
      setForm(emptyForm());
      setCreateOpen(false);
      setSuccess(savedRecipe.is_public ? "Recette publiee." : "Recette enregistree en prive.");
    } catch (submitError) {
      setError(submitError instanceof Error ? submitError.message : "Impossible d'enregistrer la recette.");
    } finally {
      setSubmitting(false);
    }
  }

  async function deleteRecipe(recipeId: number) {
    const token = localStorage.getItem("token");

    if (!token) {
      router.replace("/login");
      return;
    }

    const confirmDelete = window.confirm("Supprimer cette recette ?");
    if (!confirmDelete) {
      return;
    }

    try {
      const response = await fetch(`/api/recipes/${recipeId}`, {
        method: "DELETE",
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
      });

      const result = (await response.json()) as ApiResult;

      if (!response.ok) {
        throw new Error(result.message || "Impossible de supprimer la recette.");
      }

      setRecipes((current) => current.filter((recipe) => recipe.id !== recipeId));
      if (editingId === recipeId) {
        resetForm();
      }

      setSuccess(result.message || "Recette supprimee.");
      setError("");
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : "Impossible de supprimer la recette.");
    }
  }

  if (loading) {
    return (
      <div className="grid min-h-[60vh] place-items-center rounded-[2rem] border border-slate-200 bg-white p-6 shadow-[0_18px_40px_rgba(15,23,42,0.08)]">
        <div className="text-sm font-medium text-slate-500">Chargement des recettes...</div>
      </div>
    );
  }

  return (
    <>
      <div className="rounded-[2rem] border border-emerald-100 bg-white p-5 shadow-[0_18px_40px_rgba(15,23,42,0.08)] sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p className="text-sm font-semibold uppercase tracking-[0.16em] text-emerald-700">Recettes</p>
            <h1 className="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Recettes publiées par la communauté</h1>
            <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
              Les calories restent en avant-plan, et tu peux créer une recette via le bouton dédié. Les recettes publiques demandent une photo déposée par glisser-déposer, la liste complète des aliments et le temps de préparation.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={openCreateModal}
              className="rounded-full bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
            >
              Créer une recette
            </button>
            <button
              type="button"
              onClick={() => setFilter("public")}
              className={`rounded-full border px-4 py-2 text-sm font-medium transition ${filter === "public" ? "border-emerald-700 bg-emerald-50 text-emerald-800" : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50"}`}
            >
              Publiques
            </button>
            <button
              type="button"
              onClick={() => setFilter("mine")}
              className={`rounded-full border px-4 py-2 text-sm font-medium transition ${filter === "mine" ? "border-emerald-700 bg-emerald-50 text-emerald-800" : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50"}`}
            >
              Mes recettes
            </button>
          </div>
        </div>

        <div className="mt-5 grid gap-3 sm:grid-cols-3">
          <article className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Mes recettes</p>
            <p className="mt-2 text-3xl font-semibold text-slate-950">{myRecipes.length}</p>
          </article>
          <article className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Publiques</p>
            <p className="mt-2 text-3xl font-semibold text-slate-950">{publicRecipes.length}</p>
          </article>
          <article className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Privées</p>
            <p className="mt-2 text-3xl font-semibold text-slate-950">{privateRecipes.length}</p>
          </article>
        </div>

      </div>

      <div className="mt-4 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-[0_18px_40px_rgba(15,23,42,0.08)]">
        <div className="flex items-center justify-between gap-3">
          <div>
            <p className="text-sm font-semibold text-slate-900">Fil d’actualités</p>
            <p className="text-xs text-slate-500">Recettes publiques triées par mise à jour récente.</p>
          </div>
          <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-500">
            {visibleRecipes.length} résultat{visibleRecipes.length > 1 ? "s" : ""}
          </span>
        </div>

        {activeRecipe ? (
          <div className="mt-4 grid gap-4 lg:grid-cols-[1.25fr_0.75fr]">
            <article className="overflow-hidden rounded-[1.75rem] border border-slate-200 bg-slate-50">
              {activeRecipe.image_url ? (
                <div className="relative aspect-[16/10] w-full overflow-hidden bg-slate-200">
                  <Image
                    src={activeRecipe.image_url}
                    alt={activeRecipe.title}
                    fill
                    unoptimized
                    sizes="(max-width: 1024px) 100vw, 60vw"
                    className="object-cover"
                  />
                  <div className="absolute right-4 top-4 rounded-full bg-slate-950/90 px-3 py-1 text-sm font-semibold text-white shadow-lg">
                    {formatCalories(activeRecipe.calories)}
                  </div>
                </div>
              ) : (
                <div className="relative grid aspect-[16/10] place-items-center bg-gradient-to-br from-emerald-100 via-lime-50 to-white">
                  <span className="text-sm font-medium text-slate-500">Photo manquante</span>
                  <div className="absolute right-4 top-4 rounded-full bg-slate-950/90 px-3 py-1 text-sm font-semibold text-white shadow-lg">
                    {formatCalories(activeRecipe.calories)}
                  </div>
                </div>
              )}

              <div className="p-4">
                <p className="text-xl font-semibold text-slate-950">{activeRecipe.title}</p>
                <p className="mt-2 text-sm leading-6 text-slate-600">{activeRecipe.description ?? "Aucune description pour le moment."}</p>

                <div className="mt-4 grid gap-2 sm:grid-cols-3">
                  <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2">
                    <p className="text-[11px] font-medium uppercase tracking-[0.12em] text-amber-700">Lipides</p>
                    <p className="mt-1 text-lg font-semibold text-amber-900">
                      {activeRecipeNutritionLoading ? "..." : `${activeRecipeNutrition?.fat ?? 0} g`}
                    </p>
                  </div>
                  <div className="rounded-xl border border-cyan-200 bg-cyan-50 px-3 py-2">
                    <p className="text-[11px] font-medium uppercase tracking-[0.12em] text-cyan-700">Glucides</p>
                    <p className="mt-1 text-lg font-semibold text-cyan-900">
                      {activeRecipeNutritionLoading ? "..." : `${activeRecipeNutrition?.carbs ?? 0} g`}
                    </p>
                  </div>
                  <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2">
                    <p className="text-[11px] font-medium uppercase tracking-[0.12em] text-emerald-700">Protéines</p>
                    <p className="mt-1 text-lg font-semibold text-emerald-900">
                      {activeRecipeNutritionLoading ? "..." : `${activeRecipeNutrition?.proteins ?? 0} g`}
                    </p>
                  </div>
                </div>

                {activeRecipeNutritionLoading ? (
                  <p className="mt-2 text-xs text-slate-500">Calcul nutritionnel en cours...</p>
                ) : activeRecipeNutrition ? (
                  <p className="mt-2 text-xs text-slate-500">
                    Valeurs calculées sur {activeRecipeNutrition.resolvedCount}/{activeRecipeNutrition.totalCount} aliments.
                  </p>
                ) : null}
              </div>
            </article>

            <div className="space-y-3">
              {visibleRecipes.slice(0, 6).map((recipe) => (
                <button
                  key={recipe.id}
                  type="button"
                  onClick={() => setFeaturedRecipeId(recipe.id)}
                  className="w-full overflow-hidden rounded-[1.5rem] border border-slate-200 bg-white text-left shadow-[0_10px_28px_rgba(15,23,42,0.05)] transition hover:-translate-y-0.5 hover:border-emerald-200"
                >
                  <div className="relative aspect-[16/9] w-full bg-slate-100">
                    {recipe.image_url ? (
                      <Image src={recipe.image_url} alt={recipe.title} fill unoptimized className="object-cover" sizes="(max-width: 1024px) 100vw, 30vw" />
                    ) : null}
                    <div className="absolute right-3 top-3 rounded-full bg-slate-950/90 px-2.5 py-1 text-xs font-semibold text-white">
                      {formatCalories(recipe.calories)}
                    </div>
                  </div>
                  <div className="p-4">
                    <div className="flex items-start justify-between gap-3">
                      <h3 className="text-base font-semibold text-slate-950">{recipe.title}</h3>
                      <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${recipe.is_public ? "border border-emerald-200 bg-emerald-50 text-emerald-700" : "border border-slate-200 bg-white text-slate-600"}`}>
                        {recipe.is_public ? "Publique" : "Privée"}
                      </span>
                    </div>
                    <p className="mt-2 text-sm text-slate-600">{recipe.description ?? "Aucune description"}</p>
                  </div>
                </button>
              ))}
            </div>
          </div>
        ) : (
          <div className="mt-4 rounded-[1.5rem] border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
            Aucune recette publique à afficher pour le moment.
          </div>
        )}

        <div className="mt-4 grid gap-4 md:grid-cols-3">
          {visibleRecipes.map((recipe) => (
            <article key={recipe.id} className="overflow-hidden rounded-[1.5rem] border border-slate-200 bg-slate-50 shadow-[0_10px_28px_rgba(15,23,42,0.05)]">
              <div className="relative aspect-[4/3] bg-slate-100">
                {recipe.image_url ? (
                  <Image src={recipe.image_url} alt={recipe.title} fill unoptimized className="object-cover" sizes="(max-width: 1024px) 100vw, 25vw" />
                ) : null}
                <div className="absolute right-3 top-3 rounded-full bg-slate-950/90 px-3 py-1 text-sm font-semibold text-white">
                  {formatCalories(recipe.calories)}
                </div>
              </div>
              <div className="p-4">
                <div className="flex items-start justify-between gap-3">
                  <h3 className="text-base font-semibold text-slate-950">{recipe.title}</h3>
                  <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${recipe.is_public ? "border border-emerald-200 bg-emerald-50 text-emerald-700" : "border border-slate-200 bg-white text-slate-600"}`}>
                    {recipe.is_public ? "Publique" : "Privée"}
                  </span>
                </div>
                <p className="mt-2 text-sm text-slate-600">{recipe.description ?? "Aucune description"}</p>

                {recipe.is_owner ? (
                  <div className="mt-4 flex flex-wrap items-center gap-2">
                    <button
                      type="button"
                      onClick={() => startEdit(recipe)}
                      className="rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                    >
                      Modifier
                    </button>
                    <button
                      type="button"
                      onClick={() => void deleteRecipe(recipe.id)}
                      className="rounded-full border border-rose-200 bg-rose-50 px-4 py-2 text-xs font-medium text-rose-700 transition hover:bg-rose-100"
                    >
                      Supprimer
                    </button>
                  </div>
                ) : null}
              </div>
            </article>
          ))}
        </div>
      </div>

      {createOpen ? (
        <div className="fixed inset-0 z-50 bg-slate-950/40 px-4 py-6 backdrop-blur-sm">
          <div className="mx-auto flex h-full max-w-4xl items-center justify-center">
            <form
              className="max-h-[92vh] w-full overflow-y-auto rounded-[2rem] border border-slate-200 bg-white p-5 shadow-[0_30px_90px_rgba(15,23,42,0.3)] sm:p-6"
              onSubmit={submitRecipe}
            >
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <p className="text-sm font-semibold uppercase tracking-[0.16em] text-emerald-700">Créer une recette</p>
                  <h2 className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">Nouvelle recette privée ou publique</h2>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    setCreateOpen(false);
                    setDragActive(false);
                  }}
                  className="rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                >
                  Fermer
                </button>
              </div>

              <div className="mt-5 grid gap-5 lg:grid-cols-[0.9fr_1.1fr]">
                <div className="space-y-4">
                  <label
                    className={`grid min-h-64 cursor-pointer place-items-center rounded-[1.5rem] border-2 border-dashed px-4 py-6 text-center transition ${dragActive ? "border-emerald-500 bg-emerald-50" : "border-slate-300 bg-slate-50 hover:border-emerald-300 hover:bg-emerald-50/60"}`}
                    onDragOver={handleDragOver}
                    onDragLeave={handleDragLeave}
                    onDrop={handleDrop}
                  >
                    <input ref={fileInputRef} type="file" accept="image/*" className="hidden" onChange={handleFileChange} />

                    {form.imageUrl ? (
                      <div className="relative h-56 w-full overflow-hidden rounded-[1.25rem] bg-slate-100">
                        <Image src={form.imageUrl} alt={form.title || "Aperçu de la recette"} fill unoptimized className="object-cover" sizes="(max-width: 1024px) 100vw, 50vw" />
                      </div>
                    ) : (
                      <div className="space-y-2">
                        <p className="text-base font-semibold text-slate-900">Dépose la photo de l’assiette ici</p>
                        <p className="text-sm text-slate-500">Glisse un fichier image ou clique pour le sélectionner.</p>
                      </div>
                    )}
                  </label>

                  <div className="flex flex-wrap gap-3">
                    <button
                      type="button"
                      onClick={() => fileInputRef.current?.click()}
                      className="rounded-full bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
                    >
                      Choisir une image
                    </button>
                    {form.imageUrl ? (
                      <button
                        type="button"
                        onClick={() => setForm((current) => ({ ...current, imageUrl: "" }))}
                        className="rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                      >
                        Retirer l’image
                      </button>
                    ) : null}
                  </div>

                  <label className="flex items-center gap-3 rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950">
                    <input
                      type="checkbox"
                      checked={form.isPublic}
                      onChange={(event) => setForm((current) => ({ ...current, isPublic: event.target.checked }))}
                      className="h-4 w-4 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500"
                    />
                    Publier cette recette au public
                  </label>
                </div>

                <div className="space-y-5">
                  <div className="grid gap-4 sm:grid-cols-2">
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Nom de la recette
                      <input
                        value={form.title}
                        onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))}
                        className="h-11 rounded-2xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition focus:border-emerald-400"
                        placeholder="Salade méditerranéenne"
                      />
                    </label>

                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Calories (calcul automatique)
                      <input
                        type="number"
                        min="0"
                        step="1"
                        value={form.calories}
                        readOnly
                        className="h-11 rounded-2xl border border-slate-200 bg-slate-100 px-4 text-slate-900 outline-none"
                        placeholder="Auto"
                      />
                    </label>
                  </div>

                  {autoCalculatingCalories ? (
                    <p className="text-xs text-slate-500">Calcul des calories en cours...</p>
                  ) : calorieHint ? (
                    <p className="text-xs text-slate-500">{calorieHint}</p>
                  ) : null}

                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Description
                    <textarea
                      value={form.description}
                      onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))}
                      className="min-h-24 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition focus:border-emerald-400"
                      placeholder="Décris le plat, son goût ou son usage."
                    />
                  </label>

                  <div className="grid gap-4 sm:grid-cols-2">
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Temps de préparation
                      <input
                        type="number"
                        min="1"
                        step="1"
                        value={form.prepTimeMinutes}
                        onChange={(event) => setForm((current) => ({ ...current, prepTimeMinutes: event.target.value }))}
                        className="h-11 rounded-2xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition focus:border-emerald-400"
                        placeholder="20"
                      />
                    </label>

                    <button
                      type="button"
                      onClick={addIngredient}
                      className="self-end rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                    >
                      Ajouter un aliment
                    </button>
                  </div>

                  <div className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="text-sm font-semibold text-slate-900">Aliments de la recette</p>
                      <div className="flex items-center gap-2">
                        <button
                          type="button"
                          onClick={() => setExpandedIngredients(form.ingredients.map(() => true))}
                          className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                        >
                          Tout dérouler
                        </button>
                        <button
                          type="button"
                          onClick={() =>
                            setExpandedIngredients(form.ingredients.map((_, index) => form.ingredients.length <= 4 || index < 1))
                          }
                          className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                        >
                          Réduire
                        </button>
                      </div>
                    </div>

                    <div className="space-y-3">
                      {form.ingredients.map((ingredient, index) => (
                        <div key={`${index}-${ingredient.name}`} className="rounded-2xl border border-slate-200 bg-white p-4">
                          <button
                            type="button"
                            onClick={() =>
                              setExpandedIngredients((current) => current.map((value, valueIndex) => (valueIndex === index ? !value : value)))
                            }
                            className="flex w-full items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-left"
                          >
                            <span>
                              <span className="block text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Aliment {index + 1}</span>
                              <span className="block text-sm font-medium text-slate-700">{ingredientPreviewLabel(ingredient)}</span>
                            </span>
                            <span className="text-xs font-semibold text-slate-500">{expandedIngredients[index] ? "Replier" : "Dérouler"}</span>
                          </button>

                          {expandedIngredients[index] ? <div className="mt-3 grid gap-3">
                            <label className="grid min-w-0 gap-1 text-xs font-medium text-slate-500">
                              Nom de l&apos;aliment
                              <input
                                value={ingredient.name}
                                onChange={(event) => updateIngredient(index, "name", event.target.value)}
                                className="h-11 w-full min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400"
                                placeholder="Saumon ou EAN"
                              />
                            </label>

                            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                              <label className="grid min-w-0 gap-1 text-xs font-medium text-slate-500">
                                Quantité
                                <input
                                  type="number"
                                  min="0"
                                  step="0.1"
                                  value={ingredient.amount}
                                  onChange={(event) => updateIngredient(index, "amount", event.target.value)}
                                  className="h-11 w-full min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400"
                                  placeholder="120"
                                />
                              </label>

                              <label className="grid min-w-0 gap-1 text-xs font-medium text-slate-500">
                                Unité
                                <input
                                  value={ingredient.unit}
                                  onChange={(event) => updateIngredient(index, "unit", event.target.value)}
                                  className="h-11 w-full min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400"
                                  placeholder="g"
                                />
                              </label>
                            </div>

                            <div className="flex flex-wrap items-center gap-2 pt-1">
                              <button
                                type="button"
                                onClick={() => void lookupIngredientByEan(index)}
                                className="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-800 transition hover:bg-emerald-100"
                              >
                                Charger depuis l&apos;EAN
                              </button>
                              <button
                                type="button"
                                onClick={() => removeIngredient(index)}
                                className="rounded-full border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-700 transition hover:bg-rose-100"
                              >
                                Supprimer
                              </button>
                              <span className="text-xs text-slate-500">
                                Colle l&apos;EAN dans le champ nom, puis clique sur le bouton pour remplir automatiquement le nom du produit.
                              </span>
                            </div>
                          </div> : null}
                        </div>
                      ))}
                    </div>
                  </div>

                  <div className="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                    Pour publier au public, il faut une photo déposée, un temps de préparation, tous les aliments et les calories mises au premier plan.
                  </div>

                  <div className="flex flex-wrap items-center gap-3">
                    <button
                      type="submit"
                      disabled={submitting}
                      className="rounded-full bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      {submitting ? "Enregistrement..." : editingId !== null ? "Mettre à jour" : "Créer la recette"}
                    </button>

                    <button
                      type="button"
                      onClick={() => {
                        setCreateOpen(false);
                        resetForm();
                      }}
                      className="rounded-full border border-slate-200 bg-white px-5 py-2.5 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                    >
                      Annuler
                    </button>
                  </div>

                  {error ? <p className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</p> : null}
                  {success ? <p className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{success}</p> : null}
                </div>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </>
  );
}