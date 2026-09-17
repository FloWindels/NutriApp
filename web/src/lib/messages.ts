/** Shared French copy (typographic apostrophes, informal « tu »). */

export const BRAND = "Mavi’oh";
export const TAGLINE = "Ton coach nutrition et sport, au quotidien.";

export const messages = {
  brand: BRAND,
  tagline: TAGLINE,

  // Network / API
  network: "Pas de connexion. Vérifie ton réseau puis réessaie.",
  timeout: "Le serveur met trop de temps à répondre.",
  unauthorized: "Ta session a expiré, reconnecte-toi.",
  forbidden: "Action non autorisée.",
  notFound: "Introuvable.",
  server: "Le serveur est indisponible pour le moment.",
  tooManyRequests: "Trop de requêtes, réessaie dans une minute.",
  unknown: "Une erreur est survenue.",
  invalidResponse: "Réponse inattendue du serveur.",
  sessionCheckFailed:
    "Impossible de vérifier ta session pour le moment. Tes données peuvent être incomplètes.",

  // Common actions
  add: "Ajouter",
  cancel: "Annuler",
  close: "Fermer",
  confirm: "Confirmer",
  delete: "Supprimer",
  edit: "Modifier",
  save: "Enregistrer",
  retry: "Réessayer",
  back: "Retour",
  more: "Plus…",
  less: "Moins",
  search: "Rechercher",
  loading: "Chargement…",
  saving: "Enregistrement…",
  undo: "Annuler",
  logout: "Déconnexion",
  today: "Aujourd’hui",
  yesterday: "Hier",
  tomorrow: "Demain",

  // States
  empty: "Rien à afficher pour le moment.",
  comingSoon: "Bientôt disponible",
  comingSoonBody: "Cette section arrive très vite. Reviens un peu plus tard !",
  estimate: "estimation",
  noResult: "Aucun résultat.",

  // Auth
  loginTitle: "Connexion",
  registerTitle: "Créer un compte",
  forgotPasswordTitle: "Mot de passe oublié",
  resetPasswordTitle: "Nouveau mot de passe",
  loginFailed: "Connexion impossible. Vérifie tes identifiants.",
  registerFailed: "Inscription impossible.",
  loginExpired: "Ta session a expiré, reconnecte-toi.",
  forgotSent: "Si un compte existe, un lien de réinitialisation a été envoyé.",
  passwordReset: "Mot de passe réinitialisé.",

  // Meals
  addedToMeal: (mealLabel: string, kcal: string) => `Ajouté au ${mealLabel} · ${kcal}`,
  removedFromMeal: "Aliment retiré du repas.",
  nothingLogged: "Rien d’enregistré",
  stockNotLinked:
    "Article non relié à un aliment : le stock sera réduit, rien ne sera ajouté au repas.",
  removeFromStock: "Retirer du stock",
  createFood: "Créer l’aliment",
  createAndAdd: "Créer et ajouter",
  foodNotFound: "Produit introuvable, même sur Open Food Facts.",

  // Profile
  completeProfile: "Complète ton profil pour obtenir tes objectifs personnalisés.",

  // Safety
  sportDisclaimer:
    "Arrête l’exercice en cas de douleur ou de malaise. Programme indicatif, ne remplace pas un coach ni un avis médical.",
  nutritionDisclaimer:
    "Estimation calculée à partir de ton profil. Ce n’est pas une mesure clinique ni un avis médical.",
} as const;

export type Messages = typeof messages;
