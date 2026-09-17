/**
 * TypeScript mirror of the Mavi’oh API contract (brief v2 §1–§15 + sport addendum §C).
 * Keys are snake_case exactly as the Laravel resources emit them.
 * Decimals arrive as numbers (cast server-side); dates are `YYYY-MM-DD`; timestamps ISO 8601.
 */

/* ------------------------------------------------------------------ */
/* Envelopes                                                           */
/* ------------------------------------------------------------------ */

export type DataEnvelope<T> = { data: T; message?: string };
export type ListEnvelope<T> = { data: T[]; message?: string };
export type MessageEnvelope = { message: string };
export type PaginationMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  off_queried?: boolean;
};
export type PaginatedEnvelope<T> = { data: T[]; meta: PaginationMeta };
export type ValidationErrors = Record<string, string[]>;
export type ApiErrorPayload = { message: string; errors?: ValidationErrors };

/* ------------------------------------------------------------------ */
/* Enumerations (French snake_case values, see brief §0.1)             */
/* ------------------------------------------------------------------ */

export type MealType = "petit_dejeuner" | "dejeuner" | "diner" | "collation";
export const MEAL_TYPES: MealType[] = ["petit_dejeuner", "dejeuner", "diner", "collation"];

export type Sexe = "homme" | "femme";
export type ObjectifType = "perdre" | "maintenir" | "prendre";
export type NiveauActivite = "sedentaire" | "leger" | "modere" | "eleve" | "tres_eleve";
export type Regime =
  | "omnivore"
  | "mediterraneen"
  | "dash"
  | "flexitarien"
  | "low_carb"
  | "keto"
  | "jeune_intermittent"
  | "vegetarien"
  | "vegan"
  | "sans_gluten"
  | "sans_lactose"
  | "montignac"
  | "halal"
  | "autre";
export type SportNiveau = "debutant" | "intermediaire" | "avance";
export type SportObjectif = "perte_de_gras" | "prise_de_muscle" | "endurance" | "forme" | "force";
export type Materiel =
  | "aucun"
  | "halteres"
  | "barre"
  | "kettlebell"
  | "elastiques"
  | "banc"
  | "barre_traction"
  | "machine"
  | "tapis"
  | "velo";
export type SituationParticuliere = "aucune" | "grossesse" | "allaitement" | "suivi_medical";
export type SportLieu = "maison" | "exterieur" | "salle_publique" | "salle_privee";
export type ZoneAEviter =
  | "genoux"
  | "dos"
  | "epaules"
  | "poignets"
  | "hanches"
  | "cou"
  | "chevilles"
  | "coudes";
export type SportFocus =
  | "perte_de_gras"
  | "prise_de_muscle"
  | "endurance"
  | "force"
  | "mobilite"
  | "gainage"
  | "haut_du_corps"
  | "bas_du_corps"
  | "fessiers"
  | "abdos"
  | "dos"
  | "bras"
  | "pectoraux"
  | "epaules"
  | "jambes"
  | "cardio";
export type SportCoefCalories = 50 | 75 | 100;

export type Unit =
  | "g"
  | "ml"
  | "piece"
  | "portion"
  | "cas"
  | "cac"
  | "verre"
  | "bol"
  | "assiette"
  | "poignee"
  | "tranche";
export type ExpiryKind = "dlc" | "ddm";
export type ExpiryStatus = "ok" | "bientot" | "aujourdhui" | "perime" | "ddm_depassee" | "inconnu";
export type FoodSourceType = "manual" | "open_food_facts" | "recipe";
export type PerUnit = "100g" | "100ml";
export type EstimateConfidence = "haute" | "moyenne" | "faible";

export type MealItemSourceType = "food" | "recipe" | "custom";
export type RefBasis = "per_100g" | "per_serving" | "absolute";

export type RecipeTag =
  | "vegetarien"
  | "vegan"
  | "sans_gluten"
  | "sans_lactose"
  | "low_carb"
  | "keto"
  | "mediterraneen"
  | "dash"
  | "flexitarien"
  | "montignac"
  | "halal"
  | "rapide"
  | "riche_en_proteines"
  | "economique";

export type RecommendationStatus = "new" | "acceptee" | "ignoree";
export type RecommendationType =
  | "sous_plancher"
  | "produit_perime"
  | "alerte_budget"
  | "budget_restant"
  | "manque_proteines"
  | "anti_gaspillage"
  | "suggestion_repas"
  | "ajustement_portions"
  | "sport_pre"
  | "sport_post"
  | "courses"
  | "hydratation"
  | "profil_incomplet";
export type RecommendationActionKind =
  | "ajouter_au_repas"
  | "ouvrir_recette"
  | "ouvrir_stock"
  | "generer_seance"
  | "ajouter_courses"
  | "ouvrir_planificateur"
  | "supprimer_stock";
export type RecommendationPriority = 1 | 2 | 3;

export type DietStatut = "conforme" | "partiel" | "non_conforme" | "donnees_insuffisantes";

export type HouseholdRole = "proprietaire" | "membre";
export type ShoppingSource = "manuel" | "auto_stock" | "planificateur" | "recommandation";
export type PlanStatus = "prevu" | "realise" | "annule";

export type ExerciseCategory = "force" | "cardio" | "mobilite" | "gainage";
export type MuscleGroup =
  | "jambes"
  | "fessiers"
  | "dos"
  | "pectoraux"
  | "epaules"
  | "bras"
  | "abdos"
  | "corps_entier"
  | "cardio"
  | "mobilite";
export type SessionKind = "seance" | "activite";
export type SessionStatus = "prevue" | "en_cours" | "terminee" | "annulee";
export type SessionSource = "generee" | "manuelle" | "catalogue" | "activite";
export type Intensity = "faible" | "moderee" | "elevee";
export type SportCategory =
  | "endurance"
  | "force"
  | "collectif"
  | "raquette"
  | "aquatique"
  | "combat"
  | "bien_etre"
  | "glisse"
  | "autre";
export type SportType =
  | "musculation"
  | "course_a_pied"
  | "velo"
  | "natation"
  | "hiit"
  | "yoga"
  | "marche"
  | "rameur"
  | "autre";
export type GeneratedBy = "regles" | "ia";
export type CaloriesSource = "auto" | "manuel";
export type WorkoutBlockKey = "echauffement" | "principal" | "retour_au_calme";
export type Theme = "systeme" | "clair" | "sombre";
export type Unites = "metrique" | "imperial";

/* ------------------------------------------------------------------ */
/* §1 Auth & account                                                   */
/* ------------------------------------------------------------------ */

export type AuthUser = {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
  created_at: string;
  updated_at: string;
};

export type AuthResponse = { token: string; user: AuthUser };

export type MeSettings = { timezone: string; theme: Theme };

/** `GET /me` — legacy user keys at top level + new siblings. */
export type Me = AuthUser & {
  has_profile: boolean;
  household_id: number | null;
  consentement_sante: boolean;
  settings: MeSettings;
};

export type LoginInput = { email: string; password: string };
export type RegisterInput = {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
};
export type ForgotPasswordInput = { email: string };
export type ResetPasswordInput = {
  email: string;
  token: string;
  password: string;
  password_confirmation: string;
};

export type AccountUpdateInput = { name: string; email: string };
export type AccountUpdateResponse = {
  message: string;
  data: { id: number; name: string; email: string };
};
export type AccountPasswordInput = {
  current_password: string;
  password: string;
  password_confirmation: string;
};
export type AccountDeleteInput = { password: string };
export type AccountExport = {
  user: AuthUser;
  profile: Profile | null;
  settings: UserSettings | null;
  meals: Meal[];
  weights: WeightLog[];
  stocks: StockItem[];
  recipes: Recipe[];
  shopping: ShoppingItem[];
  plans: MealPlan[];
  sessions: WorkoutSession[];
  recommendations: Recommendation[];
  exported_at: string;
};

/* ------------------------------------------------------------------ */
/* §2 Profile                                                          */
/* ------------------------------------------------------------------ */

export type Preferences = { aime: string[]; evite: string[] };

export type Besoins = {
  bmr: number;
  tdee: number;
  calories_recommandees: number;
  proteines_g: number;
  glucides_g: number;
  lipides_g: number;
  ajustement_kcal: number;
  variation_hebdo_kg: number;
  plancher_kcal: number;
  poids_reference: number;
  jours_restants: number | null;
  cibles_calculees_le: string | null;
  imc: number;
  imc_cible: number | null;
  fibres_g: number;
  sel_max_g: number;
  profil_mineur: boolean;
  avertissements: string[];
  etapes: string[];
  mention: string;
  is_estimate: true;
};

export type CiblesEffectives = {
  calories: number;
  proteines: number;
  glucides: number;
  lipides: number;
  source: "calcul" | "utilisateur";
};

/** `GET /profile` — legacy 15 keys at top level + siblings. */
export type Profile = {
  nom: string | null;
  poids: number | null;
  poids_souhaite_kg: number | null;
  delai_objectif_jours: number | null;
  taille: number | null;
  age: number | null;
  sexe: Sexe | null;
  objectif: string | null;
  objectif_type: ObjectifType | null;
  niveau_activite: NiveauActivite | null;
  calories_cibles: number | null;
  proteines_cibles: number | null;
  glucides_cibles: number | null;
  lipides_cibles: number | null;
  regime_alimentaire: Regime | null;
  // §2.1 additions
  allergenes: string[] | null;
  aliments_exclus: string[] | null;
  preferences: Preferences | null;
  sport_niveau: SportNiveau | null;
  sport_objectif: SportObjectif | null;
  sport_materiel: Materiel[] | null;
  sport_temps_dispo_min: number | null;
  sport_jours_semaine: number | null;
  objectif_calcul_auto: boolean;
  objectif_date_debut: string | null;
  objectif_date_fin: string | null;
  poids_reference: number | null;
  cibles_calculees_le: string | null;
  situation_particuliere: SituationParticuliere;
  consentement_parental: boolean;
  // Addendum §A.4
  sport_lieu: SportLieu | null;
  sport_zones_a_eviter: ZoneAEviter[] | null;
  sport_focus: SportFocus[] | null;
  sport_notes: string | null;
  sport_coef_calories: SportCoefCalories;
  // Computed siblings
  besoins: Besoins | null;
  cibles_effectives: CiblesEffectives | null;
  has_profile: boolean;
  imc: number | null;
  imc_cible: number | null;
};

export type ProfileResponse = Profile & { message?: string };

export type ProfileInput = {
  nom: string;
  poids: number;
  poids_souhaite_kg?: number | null;
  delai_objectif_jours?: number | null;
  taille: number;
  age: number;
  sexe: Sexe;
  objectif?: string | null;
  objectif_type: ObjectifType;
  niveau_activite: NiveauActivite;
  regime_alimentaire: Regime;
  calories_cibles?: number | null;
  proteines_cibles?: number | null;
  glucides_cibles?: number | null;
  lipides_cibles?: number | null;
  objectif_calcul_auto?: boolean;
  allergenes?: string[] | null;
  aliments_exclus?: string[] | null;
  preferences?: Preferences | null;
  sport_niveau?: SportNiveau | null;
  sport_objectif?: SportObjectif | null;
  sport_materiel?: Materiel[] | null;
  sport_temps_dispo_min?: number | null;
  sport_jours_semaine?: number | null;
  situation_particuliere?: SituationParticuliere;
  consentement_parental?: boolean;
  consentement_sante?: boolean;
  sport_lieu?: SportLieu | null;
  sport_zones_a_eviter?: ZoneAEviter[] | null;
  sport_focus?: SportFocus[] | null;
  sport_notes?: string | null;
  sport_coef_calories?: SportCoefCalories;
};

export type ProfilePreview = {
  besoins: Besoins;
  cibles_effectives: CiblesEffectives;
  imc: number;
  imc_cible: number | null;
};

/* §2.5 Weights */
export type WeightLog = { date: string; weight_kg: number };
export type WeightInput = { date?: string; weight_kg: number };

/* ------------------------------------------------------------------ */
/* §3 Foods & portions                                                 */
/* ------------------------------------------------------------------ */

export type Food = {
  id: number;
  barcode: string | null;
  name: string;
  brand: string | null;
  image_url: string | null;
  calories: number;
  fat: number;
  carbs: number;
  proteins: number;
  source_type: FoodSourceType;
  created_by_user_id: number | null;
  is_owner: boolean;
  created_at: string;
  updated_at: string;
  // §3.2 additions
  fiber: number | null;
  sugar: number | null;
  salt: number | null;
  serving_size_g: number | null;
  serving_label: string | null;
  category: string | null;
  allergens: string[] | null;
  per_unit: PerUnit;
  is_verified: boolean;
  source_fetched_at: string | null;
  is_estimate: boolean;
  is_favorite: boolean;
};

export type FoodInput = {
  barcode?: string | null;
  name: string;
  brand?: string | null;
  image_url?: string | null;
  calories?: number | null;
  fat?: number | null;
  carbs?: number | null;
  proteins?: number | null;
  fiber?: number | null;
  sugar?: number | null;
  salt?: number | null;
  serving_size_g?: number | null;
  serving_label?: string | null;
  category?: string | null;
  source_type?: FoodSourceType;
};

export type FoodSearchParams = {
  q?: string;
  barcode?: string;
  page?: number;
  per_page?: number;
  off?: 0 | 1;
};

export type Portion = {
  unit: Unit;
  label: string;
  label_short: string;
  grams: number | null;
  step: number;
  is_estimate: boolean;
};
export type PortionsResponse = { data: Portion[]; aliases: Record<string, string> };

/* ------------------------------------------------------------------ */
/* §5 Recipes                                                          */
/* ------------------------------------------------------------------ */

export type RecipeIngredient = {
  name: string;
  ean: string | null;
  amount: number | null;
  unit: string | null;
};

export type RecipePerServing = {
  calories: number;
  proteins: number | null;
  carbs: number | null;
  fat: number | null;
};

export type Recipe = {
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
  created_at: string;
  updated_at: string;
  // §5 additions
  servings: number;
  proteins: number | null;
  carbs: number | null;
  fat: number | null;
  tags: RecipeTag[] | null;
  meal_types: MealType[] | null;
  per_serving: RecipePerServing;
  has_macros: boolean;
  is_estimate: boolean;
};

export type RecipeInput = {
  title: string;
  description?: string | null;
  prep_time_minutes?: number | null;
  calories: number;
  image_url?: string | null;
  ingredients: RecipeIngredient[];
  is_public?: boolean;
  servings?: number;
  proteins?: number | null;
  carbs?: number | null;
  fat?: number | null;
  tags?: RecipeTag[];
  meal_types?: MealType[];
};

export type RecipeSearchParams = {
  q?: string;
  mine?: 0 | 1;
  tag?: RecipeTag;
  meal_type?: MealType;
  max_calories?: number;
  page?: number;
  per_page?: number;
};

export type RecipeEstimateInput = { ingredients: RecipeIngredient[] };
export type RecipeEstimate = {
  calories: number;
  proteins: number;
  carbs: number;
  fat: number;
  resolved_count: number;
  total_count: number;
  is_estimate: true;
  details: { name: string; resolved: boolean; grams: number | null }[];
};

/* ------------------------------------------------------------------ */
/* §6 Stock                                                            */
/* ------------------------------------------------------------------ */

export type StockLocation = { id: number; name: string; items_count?: number };

export type StockItemFood = {
  calories: number | null;
  proteins: number | null;
  carbs: number | null;
  fat: number | null;
  image_url: string | null;
  serving_size_g: number | null;
};

export type StockItem = {
  id: number;
  stock_id: number;
  stock_name: string;
  food_id: number | null;
  food_name: string;
  food_barcode: string | null;
  food_brand: string | null;
  quantity: number;
  unit: string;
  expires_at: string | null;
  days_left: number | null;
  created_at: string;
  updated_at: string;
  // §6.2 additions
  min_quantity: number | null;
  opened_at: string | null;
  depleted_at: string | null;
  is_depleted: boolean;
  expiry_kind: ExpiryKind;
  expiry_status: ExpiryStatus;
  food: StockItemFood | null;
  household_id: number | null;
};

export type StockAlertCounts = { expiring_count: number; expired_count: number; low_count: number };

export type StocksResponse = {
  data: StockItem[];
  locations: StockLocation[];
  alerts: StockAlertCounts;
  household_id: number | null;
};

export type StockAlerts = { expiring: StockItem[]; expired: StockItem[]; low: StockItem[] };

export type StockLocationInput = { name: string };

export type StockItemInput = {
  stock_id: number;
  food_id?: number | null;
  food_name?: string;
  food_barcode?: string | null;
  food_brand?: string | null;
  quantity: number;
  unit: string;
  expires_at?: string | null;
  expiry_kind?: ExpiryKind;
  min_quantity?: number | null;
  opened_at?: string | null;
  calories?: number | null;
  fat?: number | null;
  carbs?: number | null;
  proteins?: number | null;
  image_url?: string | null;
  source_type?: FoodSourceType;
};

export type StockItemUpdateInput = Partial<{
  quantity: number;
  unit: string;
  expires_at: string | null;
  expiry_kind: ExpiryKind;
  stock_id: number;
  food_name: string;
  min_quantity: number | null;
  opened_at: string | null;
}>;

export type StockConsumeInput = {
  quantity: number;
  unit: string;
  meal_type?: MealType;
  date?: string;
  add_to_meal?: boolean;
};

export type StockConsumeResponse = {
  message: string;
  data: {
    meal_item: { id: number; meal_id: number; calories: number } | null;
    stock_item: { id: number; quantity: number; previous_quantity: number; depleted: boolean };
  };
};

export type StockDecrement = {
  stock_item_id: number;
  previous_quantity: number;
  new_quantity: number;
  unit: string;
  depleted: boolean;
};

/* ------------------------------------------------------------------ */
/* §4 Meals & daily tracking                                           */
/* ------------------------------------------------------------------ */

export type Totals = {
  calories: number;
  proteins: number;
  carbs: number;
  fat: number;
  fiber: number | null;
  sugar: number | null;
  salt: number | null;
  is_partial: boolean;
};

export type MealTotals = Pick<Totals, "calories" | "proteins" | "carbs" | "fat"> &
  Partial<Pick<Totals, "fiber" | "sugar" | "salt" | "is_partial">>;

/** `SportNutrition::bonusForDay` (addendum §B). */
export type SportNutrition = {
  calories_burned: number;
  calories_bonus: number;
  coefficient: number;
  explication?: string;
  is_estimate: boolean;
};

export type MealItemFood = {
  id: number;
  barcode: string | null;
  brand: string | null;
  image_url: string | null;
};

export type MealItem = {
  id: number;
  meal_id: number;
  source_type: MealItemSourceType;
  food_id: number | null;
  recipe_id: number | null;
  stock_item_id: number | null;
  label: string;
  quantity: number;
  unit: string;
  grams_equivalent: number | null;
  calories: number;
  proteins: number;
  carbs: number;
  fat: number;
  fiber: number | null;
  sugar: number | null;
  salt: number | null;
  is_estimate: boolean;
  food?: MealItemFood | null;
  created_at: string;
};

export type Meal = {
  id: number;
  date: string;
  type: MealType;
  name: string | null;
  consumed_at: string | null;
  notes: string | null;
  items: MealItem[];
  totals: MealTotals;
};

export type DaySummary = {
  date: string;
  meals: Meal[];
  totals: Totals;
  targets: Totals;
  remaining: Totals;
  sport: SportNutrition;
  next_meal_type: MealType;
  plancher_kcal: number;
};

export type CustomItemInput = {
  label: string;
  per_100g?: boolean;
  calories: number;
  proteins: number;
  carbs: number;
  fat: number;
};

/** Exactly one of `food_id | recipe_id | custom`. */
export type MealItemInput = {
  food_id?: number;
  recipe_id?: number;
  custom?: CustomItemInput;
  quantity: number;
  unit: string;
  stock_item_id?: number;
  decrement_stock?: boolean;
};

export type MealCreateInput = {
  date: string;
  type: MealType;
  name?: string;
  items?: MealItemInput[];
};

export type MealCreateResponse = {
  message: string;
  data: Meal;
  day: DaySummary;
  stock_decrements: StockDecrement[];
};

export type MealItemCreateResponse = {
  message: string;
  data: MealItem;
  day: DaySummary;
  stock_decrement?: StockDecrement;
};

export type MealItemUpdateInput = { quantity: number; unit: string };
export type MealItemUpdateResponse = { message: string; data: MealItem; day: DaySummary };
export type MealItemDeleteResponse = { message: string; day: DaySummary };

export type MealUpdateInput = Partial<{
  name: string | null;
  notes: string | null;
  consumed_at: string | null;
}>;

export type MealCopyInput = { from_date: string; to_date: string; type?: MealType };
export type MealCopyResponse = { message: string; day: DaySummary };

export type HistoryDay = {
  date: string;
  calories: number;
  proteins: number;
  carbs: number;
  fat: number;
  target_calories: number;
  target_proteins: number;
  target_carbs: number;
  target_fat: number;
  meals_count: number;
  sport_minutes: number;
  calories_burned: number;
};

export type FrequentItem = {
  kind: "food" | "recipe";
  id: number;
  label: string;
  brand: string | null;
  last_quantity: number;
  last_unit: string;
  calories_per_100g?: number | null;
  calories_per_serving?: number | null;
  count: number;
};

/* ------------------------------------------------------------------ */
/* §7 Dashboard & history                                              */
/* ------------------------------------------------------------------ */

export type DashboardMeal = {
  id: number;
  type: MealType;
  name: string | null;
  calories: number;
  items_count: number;
};

export type DashboardStockItem = {
  id: number;
  label: string;
  expires_at: string | null;
  days_left: number | null;
  stock_name: string;
};

export type DashboardSessionLite = {
  id: number;
  title: string;
  status: SessionStatus;
  duration_min: number;
  calories_burned: number | null;
};

export type Dashboard = {
  date: string;
  user: { name: string; first_name: string };
  has_profile: boolean;
  targets: Totals;
  consumed: Totals;
  remaining: Totals;
  progress_pct: number;
  calories_bonus: number;
  plancher_kcal: number;
  is_estimate: boolean;
  meals: DashboardMeal[];
  next_meal_type: MealType;
  stock: StockAlertCounts & { expiring: DashboardStockItem[] };
  sport: {
    sessions_today: DashboardSessionLite[];
    calories_burned: number;
    planned: DashboardSessionLite[];
    week_minutes: number;
    week_sessions: number;
    streak_days: number;
  };
  recommendations: Recommendation[];
  weight: {
    current: number | null;
    target: number | null;
    history: WeightLog[];
    variation_hebdo_kg: number | null;
  };
  notifications_unread: number;
};

export type HistorySummary = {
  avg_calories: number | null;
  days_logged: number;
  adherence_pct: number | null;
};

export type History = {
  days: HistoryDay[];
  weights: WeightLog[];
  summary: HistorySummary;
};

/* ------------------------------------------------------------------ */
/* §8 Recommendations                                                  */
/* ------------------------------------------------------------------ */

export type RecommendationAction =
  | {
      kind: "ajouter_au_repas";
      food_id?: number;
      recipe_id?: number;
      quantity: number;
      unit: string;
      meal_type: MealType;
      label?: string;
    }
  | { kind: "ouvrir_recette"; recipe_id: number }
  | { kind: "ouvrir_stock"; stock_item_ids: number[] }
  | { kind: "generer_seance" }
  | { kind: "ajouter_courses"; label: string; quantity?: number; unit?: string }
  | { kind: "ouvrir_planificateur"; date: string }
  | { kind: "supprimer_stock"; stock_item_id: number };

export type Recommendation = {
  id: number;
  date: string;
  type: RecommendationType;
  title: string;
  message: string;
  factors: string[];
  actions: RecommendationAction[];
  priority: RecommendationPriority;
  status: RecommendationStatus;
  is_estimate: boolean;
};

export type RecommendationUpdateInput = { status: RecommendationStatus };

/* ------------------------------------------------------------------ */
/* §9 Diets                                                            */
/* ------------------------------------------------------------------ */

export type DietSummary = {
  key: Regime;
  nom: string;
  description: string;
  principes: string[];
  mineurs_autorise: boolean;
};

export type DietRules = Partial<{
  exclure_categories: string[];
  exclure_categories_tags: string[];
  exclure_allergenes_tags: string[];
  exclure_mots_cles: string[];
  frequence_max_par_semaine: Record<string, number>;
  glucides_max_g: number;
  glucides_min_g: number;
  glucides_pct: [number, number];
  lipides_pct: [number, number];
  proteines_pct: [number, number];
  sucres_pct_max: number;
  sel_max_g_jour: number;
  fibres_min_g_jour: number;
  fenetre_alimentaire: { debut: string; fin: string };
  portions_recommandees: Record<string, string | number>;
}>;

export type Diet = DietSummary & {
  regles: DietRules;
  aliments_conseilles: string[];
  aliments_a_limiter: string[];
  conseils: string[];
};

export type DietEcart = {
  date: string;
  meal_type: MealType | null;
  item_label: string | null;
  regle: string;
  explication: string;
};

export type DietProche = { key: Regime; nom: string; score_pct: number; raisons: string[] };

export type DietEvaluation =
  | {
      statut: "donnees_insuffisantes";
      score_pct: null;
      message: string;
      regime?: Regime;
    }
  | {
      regime: Regime;
      score_pct: number;
      statut: Exclude<DietStatut, "donnees_insuffisantes">;
      ecarts: DietEcart[];
      conseils: string[];
      regimes_proches: DietProche[];
      mention: string;
      is_estimate: boolean;
    };

/* ------------------------------------------------------------------ */
/* §10 Household                                                       */
/* ------------------------------------------------------------------ */

export type HouseholdMember = {
  user_id: number;
  name: string;
  role: HouseholdRole;
  share_profile: boolean;
  calories_cibles: number | null;
  regime: Regime | null;
  joined_at: string;
};

export type Household = {
  id: number;
  name: string;
  invite_code?: string;
  role: HouseholdRole;
  members: HouseholdMember[];
  stock_items_count: number;
};

export type HouseholdPreview = { name: string; members_count: number };
export type HouseholdCreateInput = { name: string };
export type HouseholdJoinInput = { invite_code: string };
export type HouseholdJoinResponse = {
  message: string;
  data: Household;
  merged_stock_items: number;
};
export type HouseholdMemberMeInput = { share_profile: boolean };

export type CommonMealPreviewInput = { recipe_id: number; meal_type: MealType; date?: string };
export type CommonMealMemberPreview = {
  user_id: number;
  name: string;
  target_kcal: number;
  portions: number;
  calories: number;
  is_estimate: boolean;
  label: string | null;
  factors?: string[];
};
export type CommonMealPreview = {
  recipe: { id: number; title: string; per_serving: RecipePerServing };
  members: CommonMealMemberPreview[];
};
export type CommonMealInput = {
  recipe_id: number;
  meal_type: MealType;
  date: string;
  portions: Record<string, number>;
};
export type CommonMealResponse = {
  message: string;
  data: { created: { user_id: number; meal_id: number }[] };
};

/* ------------------------------------------------------------------ */
/* §11 Shopping list                                                   */
/* ------------------------------------------------------------------ */

export type ShoppingItem = {
  id: number;
  user_id: number | null;
  household_id: number | null;
  food_id: number | null;
  label: string;
  quantity: number | null;
  unit: string | null;
  checked: boolean;
  source: ShoppingSource;
  created_at: string;
  updated_at: string;
};

export type ShoppingListResponse = {
  data: ShoppingItem[];
  counts: { total: number; checked: number };
};

export type ShoppingItemInput = {
  label: string;
  quantity?: number | null;
  unit?: string | null;
  food_id?: number | null;
};
export type ShoppingItemUpdateInput = Partial<{
  checked: boolean;
  quantity: number | null;
  unit: string | null;
  label: string;
}>;
export type ShoppingGenerateInput = { week_start?: string };
export type ShoppingGenerateResponse = {
  message: string;
  data: ShoppingItem[];
  added_count: number;
};
export type ShoppingToStockInput = {
  stock_id?: number;
  expires_at?: string | null;
  quantity?: number;
  unit?: string;
};

/* ------------------------------------------------------------------ */
/* §12 Planner                                                         */
/* ------------------------------------------------------------------ */

export type MealPlan = {
  id: number;
  user_id: number;
  household_id: number | null;
  date: string;
  meal_type: MealType;
  recipe_id: number | null;
  food_id: number | null;
  title: string;
  servings: number;
  notes: string | null;
  status: PlanStatus;
  meal_id: number | null;
  calories?: number | null;
  recipe?: Pick<Recipe, "id" | "title" | "image_url" | "per_serving"> | null;
  created_at: string;
  updated_at: string;
};

export type PlannerDay = {
  date: string;
  slots: Record<MealType, MealPlan[]>;
};

export type Planner = {
  week_start: string;
  days: PlannerDay[];
  totals_per_day: { date: string; calories: number }[];
};

export type MealPlanInput = {
  date: string;
  meal_type: MealType;
  recipe_id?: number | null;
  food_id?: number | null;
  title?: string;
  servings?: number;
  notes?: string | null;
};
export type MealPlanUpdateInput = Partial<MealPlanInput & { status: PlanStatus }>;
export type MealPlanLogInput = { decrement_stock?: boolean };
export type MealPlanLogResponse = { message: string; day: DaySummary };
export type PlannerGenerateInput = {
  week_start: string;
  meal_types?: MealType[];
  replace?: boolean;
};
export type PlannerGenerateResponse = {
  message: string;
  data: Planner;
  generated_count: number;
};

/* ------------------------------------------------------------------ */
/* §13 + addendum §C Sport                                             */
/* ------------------------------------------------------------------ */

export type VocabEntry<K extends string = string> = { key: K; label: string };

export type SportConfig = {
  ia_disponible: boolean;
  llm_model: string | null;
  coef_calories: SportCoefCalories;
  vocab: {
    lieux: VocabEntry<SportLieu>[];
    zones: VocabEntry<ZoneAEviter>[];
    focus: VocabEntry<SportFocus>[];
    objectifs: VocabEntry<SportObjectif>[];
    niveaux: VocabEntry<SportNiveau>[];
    materiel: VocabEntry<Materiel>[];
    intensites: VocabEntry<Intensity>[];
    categories_sport: VocabEntry<SportCategory>[];
  };
};

export type Sport = {
  id: number;
  name: string;
  slug: string;
  category: SportCategory;
  met_faible: number;
  met_moderee: number;
  met_elevee: number;
  icon: string | null;
  is_public: boolean;
  is_mine: boolean;
};

export type SportInput = { name: string; category: SportCategory; met_moderee?: number };

export type Exercise = {
  id: number;
  name: string;
  slug: string;
  category: ExerciseCategory;
  muscle_group: MuscleGroup;
  equipment: Materiel;
  level: SportNiveau;
  met: number;
  default_sets: number | null;
  default_reps: number | null;
  default_duration_sec: number | null;
  instructions: string;
  contraindications?: ZoneAEviter[] | null;
  is_public: boolean;
};

export type ExerciseSearchParams = {
  equipment?: Materiel;
  muscle?: MuscleGroup;
  level?: SportNiveau;
  category?: ExerciseCategory;
  q?: string;
  page?: number;
};

export type WorkoutExercise = {
  id: number;
  exercise_id: number | null;
  block: WorkoutBlockKey;
  position: number;
  name: string;
  sets: number | null;
  reps: number | null;
  duration_sec: number | null;
  weight_kg: number | null;
  rest_sec: number | null;
  met: number | null;
  completed: boolean;
  instructions?: string | null;
};

export type WorkoutSession = {
  id: number;
  date: string;
  planned_at: string | null;
  title: string;
  kind: SessionKind;
  goal: SportObjectif | null;
  level: SportNiveau | null;
  equipment: Materiel[] | null;
  focus: SportFocus[] | null;
  duration_min: number;
  calories_burned: number | null;
  status: SessionStatus;
  rpe: number | null;
  notes: string | null;
  source: SessionSource;
  sport_id: number | null;
  sport_name: string | null;
  lieu: SportLieu | null;
  calories_source: CaloriesSource;
  generated_by: GeneratedBy | null;
  llm_model: string | null;
  sport_plan_id: number | null;
  zones_a_eviter: ZoneAEviter[] | null;
  intensity: Intensity | null;
  distance_km: number | null;
  started_at: string | null;
  completed_at: string | null;
  is_estimate: boolean;
  exercises: WorkoutExercise[];
};

export type SessionLite = Pick<
  WorkoutSession,
  "id" | "title" | "sport_name" | "status" | "duration_min" | "calories_burned" | "kind"
>;

export type SportPlan = {
  id: number;
  date: string;
  sport_id: number | null;
  sport_name: string;
  planned_duration_min: number;
  planned_at: string | null;
  lieu: SportLieu | null;
  notes: string | null;
  status: PlanStatus;
  session_id: number | null;
  recurrence_id: string | null;
  sport?: Pick<Sport, "id" | "name" | "icon" | "category"> | null;
  created_at?: string;
  updated_at?: string;
};

export type SportCalendarDay = { date: string; plans: SportPlan[]; sessions: SessionLite[] };
export type SportCalendarSummary = {
  planned_count: number;
  done_count: number;
  minutes_done: number;
  calories_done: number;
};
export type SportCalendar = { days: SportCalendarDay[]; summary: SportCalendarSummary };

export type SportPlanInput = {
  date: string;
  sport_id?: number | null;
  sport_name?: string;
  planned_duration_min: number;
  planned_at?: string | null;
  lieu?: SportLieu | null;
  notes?: string | null;
};
export type SportPlanUpdateInput = Partial<SportPlanInput & { status: PlanStatus }>;
export type SportRecurringInput = {
  weekday: 1 | 2 | 3 | 4 | 5 | 6 | 7;
  sport_id?: number | null;
  sport_name?: string;
  planned_duration_min: number;
  planned_at?: string | null;
  lieu?: SportLieu | null;
  notes?: string | null;
  weeks: number;
  start_date?: string;
};
export type SportRecurringResponse = {
  message: string;
  data: SportPlan[];
  recurrence_id: string;
};
export type SportPlanLogInput = {
  duration_min: number;
  intensity?: Intensity;
  distance_km?: number | null;
  calories_burned?: number | null;
  rpe?: number | null;
  notes?: string | null;
};
export type SportPlanLogResponse = {
  message: string;
  data: { plan: SportPlan; session: WorkoutSession };
  nutrition: SportNutrition;
};
export type SportPlanWeekInput = {
  week_start: string;
  days?: number[];
  mode?: GeneratedBy;
  replace?: boolean;
};
export type SportPlanWeekResponse = {
  message: string;
  data: SportCalendar;
  generated_by: GeneratedBy;
};

export type ActivityInput = {
  date?: string;
  sport_id?: number | null;
  sport_name?: string;
  duration_min: number;
  intensity: Intensity;
  distance_km?: number | null;
  calories_burned?: number | null;
  notes?: string | null;
  sport_plan_id?: number | null;
};
export type ActivityResponse = { message: string; data: WorkoutSession; nutrition: SportNutrition };

export type CaloriesEstimateInput = {
  sport_id?: number | null;
  sport_name?: string;
  duration_min: number;
  intensity?: Intensity;
  met?: number;
};
export type CaloriesEstimate = {
  calories: number;
  met: number;
  poids_kg: number;
  is_estimate: true;
};

export type GenerateSessionInput = {
  mode?: GeneratedBy;
  goal?: SportObjectif;
  level?: SportNiveau;
  duration_min: number;
  sport_type?: SportType;
  sport_id?: number | null;
  lieu?: SportLieu;
  equipment?: Materiel[];
  focus?: SportFocus[];
  zones_a_eviter?: ZoneAEviter[];
  notes?: string;
  seed?: number;
};

export type ProposalExercise = {
  exercise_id: number | null;
  name: string;
  category: ExerciseCategory;
  muscle_group: MuscleGroup | null;
  equipment: Materiel | null;
  sets: number | null;
  reps: number | null;
  duration_sec: number | null;
  rest_sec: number | null;
  distance_km: number | null;
  intensity: Intensity | null;
  instructions: string;
  met: number | null;
};

export type ProposalBlock = { key: WorkoutBlockKey; name: string; exercises: ProposalExercise[] };

export type WorkoutProposal = {
  title: string;
  sport_type: SportType | null;
  lieu: SportLieu | null;
  goal: SportObjectif | null;
  level: SportNiveau | null;
  duration_min: number;
  equipment: Materiel[];
  focus: SportFocus[];
  zones_a_eviter: ZoneAEviter[];
  intensity: Intensity | null;
  calories_estimate: number;
  is_estimate: true;
  generated_by: GeneratedBy;
  llm_model: string | null;
  explication: string[];
  warnings: string[];
  blocks: ProposalBlock[];
};

export type SessionExerciseInput = {
  exercise_id?: number | null;
  name: string;
  block?: WorkoutBlockKey;
  sets?: number | null;
  reps?: number | null;
  duration_sec?: number | null;
  weight_kg?: number | null;
  rest_sec?: number | null;
};

/** `POST /sport/sessions` — a saved proposal or a manual session. */
export type SessionInput = Partial<Omit<WorkoutProposal, "blocks" | "is_estimate">> & {
  date: string;
  title: string;
  duration_min: number;
  kind?: SessionKind;
  planned_at?: string | null;
  status?: Extract<SessionStatus, "prevue" | "en_cours">;
  started_at?: string | null;
  calories_burned?: number | null;
  notes?: string | null;
  sport_plan_id?: number | null;
  exercises?: SessionExerciseInput[];
  blocks?: ProposalBlock[];
};

export type SessionUpdateInput = Partial<SessionInput>;

export type SessionCompleteInput = {
  duration_min?: number;
  rpe?: number;
  exercises?: {
    id: number;
    completed: boolean;
    sets?: number | null;
    reps?: number | null;
    weight_kg?: number | null;
  }[];
};
export type SessionCompleteResponse = {
  message: string;
  data: WorkoutSession;
  nutrition: SportNutrition;
  reco_post: string | null;
};

export type SportSummary = {
  today: { sessions: WorkoutSession[]; calories_burned: number; minutes: number };
  week: { sessions: number; minutes: number; calories: number };
  streak_days: number;
  nutrition: SportNutrition;
  recos: { pre: string | null; post: string | null };
  active_session_id: number | null;
  coef_calories: SportCoefCalories;
  next_plan: {
    date: string;
    sport_name: string;
    planned_duration_min: number;
    planned_at: string | null;
  } | null;
  week_plans: SportPlan[];
};

export type SessionSearchParams = { from?: string; to?: string; status?: SessionStatus };

/* ------------------------------------------------------------------ */
/* §14 Settings & notifications                                        */
/* ------------------------------------------------------------------ */

export type UserSettings = {
  notif_peremption: boolean;
  notif_rappel_repas: boolean;
  notif_rappel_sport: boolean;
  heure_rappel: string | null;
  jours_alerte_peremption: number;
  unites: Unites;
  theme: Theme;
  langue: string;
  timezone: string;
  ia_seances: boolean;
  partage_profil_foyer: boolean | null;
};

export type SettingsInput = Partial<UserSettings>;

export type NotificationType =
  | "peremption"
  | "perime"
  | "rappel_repas"
  | "rappel_sport"
  | "recommandation"
  | string;

export type AppNotification = {
  key: string;
  type: NotificationType;
  title: string;
  message: string;
  date: string;
  read: boolean;
  action?: RecommendationAction | { kind: string; [key: string]: unknown } | null;
};

export type NotificationsResponse = { data: AppNotification[]; unread_count: number };
