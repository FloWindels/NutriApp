import type {
  DietStatut,
  ExerciseCategory,
  ExpiryStatus,
  Intensity,
  Materiel,
  MealType,
  MuscleGroup,
  NiveauActivite,
  ObjectifType,
  PlanStatus,
  RecipeTag,
  RecommendationStatus,
  Regime,
  SessionStatus,
  Sexe,
  ShoppingSource,
  SituationParticuliere,
  SportCategory,
  SportFocus,
  SportLieu,
  SportNiveau,
  SportObjectif,
  SportType,
  Unit,
  WorkoutBlockKey,
  ZoneAEviter,
} from "@/lib/types/api";

/**
 * French labels for every enum value. `GET /sport/config` ships the sport vocab
 * from the server; these tables are the offline fallback + everything else.
 */

export const MEAL_TYPE_LABELS: Record<MealType, string> = {
  petit_dejeuner: "Petit-déjeuner",
  dejeuner: "Déjeuner",
  diner: "Dîner",
  collation: "Collation",
};

/** Lower-case form for sentences (« Ajouté au déjeuner »). */
export const MEAL_TYPE_IN_SENTENCE: Record<MealType, string> = {
  petit_dejeuner: "petit-déjeuner",
  dejeuner: "déjeuner",
  diner: "dîner",
  collation: "collation",
};

/** Default hours used by the server for fasting windows / sport_pre. */
export const MEAL_TYPE_DEFAULT_HOUR: Record<MealType, string> = {
  petit_dejeuner: "08:00",
  dejeuner: "12:30",
  collation: "16:30",
  diner: "19:30",
};

export const UNIT_LABELS: Record<Unit, { label: string; short: string }> = {
  g: { label: "gramme", short: "g" },
  ml: { label: "millilitre", short: "ml" },
  piece: { label: "pièce", short: "pièce" },
  portion: { label: "portion", short: "portion" },
  cas: { label: "cuillère à soupe", short: "c. à s." },
  cac: { label: "cuillère à café", short: "c. à c." },
  verre: { label: "verre", short: "verre" },
  bol: { label: "bol", short: "bol" },
  assiette: { label: "assiette", short: "assiette" },
  poignee: { label: "poignée", short: "poignée" },
  tranche: { label: "tranche", short: "tranche" },
};

export const SEXE_LABELS: Record<Sexe, string> = { homme: "Homme", femme: "Femme" };

export const OBJECTIF_TYPE_LABELS: Record<ObjectifType, string> = {
  perdre: "Perdre du poids",
  maintenir: "Maintenir mon poids",
  prendre: "Prendre du poids",
};

export const NIVEAU_ACTIVITE_LABELS: Record<NiveauActivite, string> = {
  sedentaire: "Sédentaire",
  leger: "Léger",
  modere: "Modéré",
  eleve: "Élevé",
  tres_eleve: "Très élevé",
};

export const NIVEAU_ACTIVITE_HELP: Record<NiveauActivite, string> = {
  sedentaire: "Travail assis, peu de marche",
  leger: "Debout ou en marche une partie de la journée",
  modere: "Actif au quotidien (hors séances enregistrées)",
  eleve: "Travail physique ou très actif",
  tres_eleve: "Travail très physique",
};

export const REGIME_LABELS: Record<Regime, string> = {
  omnivore: "Omnivore",
  mediterraneen: "Méditerranéen",
  dash: "DASH",
  flexitarien: "Flexitarien",
  low_carb: "Low carb",
  keto: "Kéto",
  jeune_intermittent: "Jeûne intermittent",
  vegetarien: "Végétarien",
  vegan: "Végan",
  sans_gluten: "Sans gluten",
  sans_lactose: "Sans lactose",
  montignac: "Montignac",
  halal: "Halal",
  autre: "Autre",
};

/** Diets not offered to minors (brief §2.3 rule 3). */
export const REGIMES_ADULTES_SEULEMENT: Regime[] = ["keto", "low_carb", "jeune_intermittent", "montignac"];

export const SITUATION_LABELS: Record<SituationParticuliere, string> = {
  aucune: "Aucune",
  grossesse: "Grossesse",
  allaitement: "Allaitement",
  suivi_medical: "Suivi médical",
};

export const SPORT_NIVEAU_LABELS: Record<SportNiveau, string> = {
  debutant: "Débutant",
  intermediaire: "Intermédiaire",
  avance: "Avancé",
};

export const SPORT_OBJECTIF_LABELS: Record<SportObjectif, string> = {
  perte_de_gras: "Perte de gras",
  prise_de_muscle: "Prise de muscle",
  endurance: "Endurance",
  forme: "Forme",
  force: "Force",
};

export const MATERIEL_LABELS: Record<Materiel, string> = {
  aucun: "Aucun",
  halteres: "Haltères",
  barre: "Barre",
  kettlebell: "Kettlebell",
  elastiques: "Élastiques",
  banc: "Banc",
  barre_traction: "Barre de traction",
  machine: "Machines",
  tapis: "Tapis",
  velo: "Vélo",
};

export const SPORT_LIEU_LABELS: Record<SportLieu, string> = {
  maison: "Maison",
  exterieur: "Extérieur",
  salle_publique: "Salle publique",
  salle_privee: "Salle privée",
};

export const ZONE_LABELS: Record<ZoneAEviter, string> = {
  genoux: "Genoux",
  dos: "Dos",
  epaules: "Épaules",
  poignets: "Poignets",
  hanches: "Hanches",
  cou: "Cou",
  chevilles: "Chevilles",
  coudes: "Coudes",
};

export const FOCUS_LABELS: Record<SportFocus, string> = {
  perte_de_gras: "Perte de gras",
  prise_de_muscle: "Prise de muscle",
  endurance: "Endurance",
  force: "Force",
  mobilite: "Mobilité",
  gainage: "Gainage",
  haut_du_corps: "Haut du corps",
  bas_du_corps: "Bas du corps",
  fessiers: "Fessiers",
  abdos: "Abdos",
  dos: "Dos",
  bras: "Bras",
  pectoraux: "Pectoraux",
  epaules: "Épaules",
  jambes: "Jambes",
  cardio: "Cardio",
};

export const INTENSITY_LABELS: Record<Intensity, string> = {
  faible: "Faible",
  moderee: "Modérée",
  elevee: "Élevée",
};

export const SPORT_CATEGORY_LABELS: Record<SportCategory, string> = {
  endurance: "Endurance",
  force: "Force",
  collectif: "Sports collectifs",
  raquette: "Raquette",
  aquatique: "Aquatique",
  combat: "Combat",
  bien_etre: "Bien-être",
  glisse: "Glisse",
  autre: "Autre",
};

export const SPORT_TYPE_LABELS: Record<SportType, string> = {
  musculation: "Musculation",
  course_a_pied: "Course à pied",
  velo: "Vélo",
  natation: "Natation",
  hiit: "HIIT",
  yoga: "Yoga / Mobilité",
  marche: "Marche",
  rameur: "Rameur",
  autre: "Autre",
};

export const EXERCISE_CATEGORY_LABELS: Record<ExerciseCategory, string> = {
  force: "Force",
  cardio: "Cardio",
  mobilite: "Mobilité",
  gainage: "Gainage",
};

export const MUSCLE_GROUP_LABELS: Record<MuscleGroup, string> = {
  jambes: "Jambes",
  fessiers: "Fessiers",
  dos: "Dos",
  pectoraux: "Pectoraux",
  epaules: "Épaules",
  bras: "Bras",
  abdos: "Abdos",
  corps_entier: "Corps entier",
  cardio: "Cardio",
  mobilite: "Mobilité",
};

export const BLOCK_LABELS: Record<WorkoutBlockKey, string> = {
  echauffement: "Échauffement",
  principal: "Circuit principal",
  retour_au_calme: "Retour au calme",
};

export const SESSION_STATUS_LABELS: Record<SessionStatus, string> = {
  prevue: "Prévue",
  en_cours: "En cours",
  terminee: "Terminée",
  annulee: "Annulée",
};

export const PLAN_STATUS_LABELS: Record<PlanStatus, string> = {
  prevu: "Prévu",
  realise: "Réalisé",
  annule: "Annulé",
};

export const RECOMMENDATION_STATUS_LABELS: Record<RecommendationStatus, string> = {
  new: "Nouvelle",
  acceptee: "Acceptée",
  ignoree: "Ignorée",
};

export const EXPIRY_STATUS_LABELS: Record<ExpiryStatus, string> = {
  ok: "OK",
  bientot: "Bientôt",
  aujourdhui: "Aujourd’hui",
  perime: "Périmé",
  ddm_depassee: "DDM dépassée",
  inconnu: "Sans date",
};

export const DIET_STATUT_LABELS: Record<DietStatut, string> = {
  conforme: "Conforme",
  partiel: "Partiellement conforme",
  non_conforme: "Non conforme",
  donnees_insuffisantes: "Données insuffisantes",
};

export const SHOPPING_SOURCE_LABELS: Record<ShoppingSource, string> = {
  manuel: "Ajouté à la main",
  auto_stock: "Stock épuisé",
  planificateur: "Planificateur",
  recommandation: "Coach",
};

export const RECIPE_TAG_LABELS: Record<RecipeTag, string> = {
  vegetarien: "Végétarien",
  vegan: "Végan",
  sans_gluten: "Sans gluten",
  sans_lactose: "Sans lactose",
  low_carb: "Low carb",
  keto: "Kéto",
  mediterraneen: "Méditerranéen",
  dash: "DASH",
  flexitarien: "Flexitarien",
  montignac: "Montignac",
  halal: "Halal",
  rapide: "Rapide",
  riche_en_proteines: "Riche en protéines",
  economique: "Économique",
};

export const WEEKDAY_SHORT = ["L", "M", "M", "J", "V", "S", "D"] as const;
export const WEEKDAY_LABELS = [
  "lundi",
  "mardi",
  "mercredi",
  "jeudi",
  "vendredi",
  "samedi",
  "dimanche",
] as const;

/** Generic lookup with a safe fallback (raw key, underscores → spaces). */
export function labelFor<K extends string>(
  table: Record<K, string>,
  key: K | string | null | undefined,
  fallback?: string,
): string {
  if (!key) return fallback ?? "";
  const value = (table as Record<string, string>)[key];
  return value ?? fallback ?? key.replace(/_/g, " ");
}

/** Turns a label table into `{ key, label }[]` for selects and chip groups. */
export function optionsFrom<K extends string>(table: Record<K, string>): { key: K; label: string }[] {
  return (Object.keys(table) as K[]).map((key) => ({ key, label: table[key] }));
}
