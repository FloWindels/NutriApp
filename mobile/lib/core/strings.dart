/// A key/label pair used for vocabularies (lieux, zones, focus…).
class VocabItem {
  final String key;
  final String label;

  const VocabItem(this.key, this.label);
}

/// Shared French copy. Informal « tu », typographic apostrophes.
class AppStrings {
  AppStrings._();

  static const String brand = 'Mavi’oh';
  static const String tagline = 'Ton coach nutrition et sport, au quotidien.';

  // ----- Navigation ---------------------------------------------------------
  static const String tabHome = 'Accueil';
  static const String tabMeals = 'Repas';
  static const String tabStock = 'Stock';
  static const String tabSport = 'Sport';
  static const String tabMore = 'Plus';

  // ----- Section titles (frozen, see contract table) -----------------------
  static const String sectionDashboard = 'Accueil';
  static const String sectionMeals = 'Repas du jour';
  static const String sectionFoodSearch = 'Recherche d’aliments';
  static const String sectionRecipes = 'Recettes';
  static const String sectionCoach = 'Coach du jour';
  static const String sectionDiet = 'Régime reconnu';
  static const String sectionStock = 'Stock';
  static const String sectionPlanner = 'Planificateur de la semaine';
  static const String sectionShopping = 'Liste de courses';
  static const String sectionSport = 'Sport';
  static const String sectionFamily = 'Famille';
  static const String sectionProfile = 'Profil';
  static const String sectionSettings = 'Paramètres';
  static const String sectionNotifications = 'Notifications';
  static const String sectionHistory = 'Historique';

  // ----- Categories ---------------------------------------------------------
  static const String categoryNutrition = 'Nutrition';
  static const String categoryPlanning = 'Planification';
  static const String categoryAccount = 'Compte';

  // ----- Common actions -----------------------------------------------------
  static const String retry = 'Réessayer';
  static const String cancel = 'Annuler';
  static const String confirm = 'Confirmer';
  static const String save = 'Enregistrer';
  static const String add = 'Ajouter';
  static const String edit = 'Modifier';
  static const String delete = 'Supprimer';
  static const String close = 'Fermer';
  static const String back = 'Retour';
  static const String seeAll = 'Voir tout';
  static const String more = 'Plus…';
  static const String logout = 'Déconnexion';
  static const String search = 'Rechercher';
  static const String scan = 'Scanner';
  static const String undo = 'Annuler';
  static const String comingSoon = 'Bientôt disponible';
  static const String loading = 'Chargement…';
  static const String today = 'Aujourd’hui';
  static const String yesterday = 'Hier';
  static const String tomorrow = 'Demain';

  // ----- Common messages ----------------------------------------------------
  static const String errorGeneric = 'Une erreur est survenue.';
  static const String errorNetwork = 'Pas de connexion. Vérifie ton réseau puis réessaie.';
  static const String errorServerUnreachable = 'Impossible de joindre le serveur.';
  static const String sessionExpired = 'Ta session a expiré, reconnecte-toi.';
  static const String profileIncomplete = 'Complète ton profil pour obtenir tes objectifs personnalisés.';
  static const String estimate = 'estimation';
  static const String nothingLogged = 'Rien d’enregistré';
  static const String emptyGeneric = 'Rien à afficher pour le moment.';
  static const String offAttribution = 'Données nutritionnelles : Open Food Facts (ODbL)';
  static const String disclaimer =
      'Mavi’oh fournit des estimations à partir de ton profil et de tes saisies. Ce n’est ni une mesure clinique ni un avis médical.';

  // ----- Macros -------------------------------------------------------------
  static const String proteins = 'Protéines';
  static const String carbs = 'Glucides';
  static const String fat = 'Lipides';
  static const String proteinsShort = 'P';
  static const String carbsShort = 'G';
  static const String fatShort = 'L';
  static const String calories = 'Calories';
  static const String fiber = 'Fibres';
  static const String sugar = 'Sucres';
  static const String salt = 'Sel';

  // ----- Meals --------------------------------------------------------------
  static const Map<String, String> mealTypeLabels = {
    'petit_dejeuner': 'Petit-déjeuner',
    'dejeuner': 'Déjeuner',
    'diner': 'Dîner',
    'collation': 'Collation',
  };

  static const List<String> mealTypeOrder = ['petit_dejeuner', 'dejeuner', 'diner', 'collation'];

  static String mealType(String? key) => mealTypeLabels[key] ?? (key ?? '—');

  /// « au déjeuner », « au petit-déjeuner », « au dîner », « à la collation ».
  static String mealTypeDative(String? key) {
    switch (key) {
      case 'petit_dejeuner':
        return 'au petit-déjeuner';
      case 'dejeuner':
        return 'au déjeuner';
      case 'diner':
        return 'au dîner';
      case 'collation':
        return 'à la collation';
      default:
        return 'au repas';
    }
  }

  // ----- Objectives / activity / diets --------------------------------------
  static const Map<String, String> objectifTypeLabels = {
    'perdre': 'Perdre du poids',
    'maintenir': 'Maintenir mon poids',
    'prendre': 'Prendre du poids',
  };

  static const Map<String, String> niveauActiviteLabels = {
    'sedentaire': 'Sédentaire',
    'leger': 'Léger',
    'modere': 'Modéré',
    'eleve': 'Élevé',
    'tres_eleve': 'Très élevé',
  };

  static const Map<String, String> regimeLabels = {
    'omnivore': 'Omnivore',
    'mediterraneen': 'Méditerranéen',
    'dash': 'DASH',
    'flexitarien': 'Flexitarien',
    'low_carb': 'Low carb',
    'keto': 'Kéto',
    'jeune_intermittent': 'Jeûne intermittent',
    'vegetarien': 'Végétarien',
    'vegan': 'Végan',
    'sans_gluten': 'Sans gluten',
    'sans_lactose': 'Sans lactose',
    'montignac': 'Montignac',
    'halal': 'Halal',
    'autre': 'Autre',
  };

  static const Map<String, String> situationLabels = {
    'aucune': 'Aucune',
    'grossesse': 'Grossesse',
    'allaitement': 'Allaitement',
    'suivi_medical': 'Suivi médical',
  };

  static const Map<String, String> recipeTagLabels = {
    'vegetarien': 'Végétarien',
    'vegan': 'Végan',
    'sans_gluten': 'Sans gluten',
    'sans_lactose': 'Sans lactose',
    'low_carb': 'Low carb',
    'keto': 'Kéto',
    'mediterraneen': 'Méditerranéen',
    'dash': 'DASH',
    'flexitarien': 'Flexitarien',
    'montignac': 'Montignac',
    'halal': 'Halal',
    'rapide': 'Rapide',
    'riche_en_proteines': 'Riche en protéines',
    'economique': 'Économique',
  };

  // ----- Expiry -------------------------------------------------------------
  static const Map<String, String> expiryStatusLabels = {
    'ok': 'OK',
    'bientot': 'Bientôt',
    'aujourdhui': 'Aujourd’hui',
    'perime': 'Périmé',
    'ddm_depassee': 'DDM dépassée',
    'inconnu': 'Sans date',
  };

  static const Map<String, String> expiryKindLabels = {
    'dlc': 'DLC',
    'ddm': 'DDM',
  };

  // ----- Sport vocab (addendum §D; server `/sport/config` labels win) ------
  static const List<VocabItem> sportLieux = [
    VocabItem('maison', 'Maison'),
    VocabItem('exterieur', 'Extérieur'),
    VocabItem('salle_publique', 'Salle publique'),
    VocabItem('salle_privee', 'Salle privée'),
  ];

  static const List<VocabItem> sportZones = [
    VocabItem('genoux', 'Genoux'),
    VocabItem('dos', 'Dos'),
    VocabItem('epaules', 'Épaules'),
    VocabItem('poignets', 'Poignets'),
    VocabItem('hanches', 'Hanches'),
    VocabItem('cou', 'Cou'),
    VocabItem('chevilles', 'Chevilles'),
    VocabItem('coudes', 'Coudes'),
  ];

  static const List<VocabItem> sportFocus = [
    VocabItem('perte_de_gras', 'Perte de gras'),
    VocabItem('prise_de_muscle', 'Prise de muscle'),
    VocabItem('endurance', 'Endurance'),
    VocabItem('force', 'Force'),
    VocabItem('mobilite', 'Mobilité'),
    VocabItem('gainage', 'Gainage'),
    VocabItem('haut_du_corps', 'Haut du corps'),
    VocabItem('bas_du_corps', 'Bas du corps'),
    VocabItem('fessiers', 'Fessiers'),
    VocabItem('abdos', 'Abdos'),
    VocabItem('dos', 'Dos'),
    VocabItem('bras', 'Bras'),
    VocabItem('pectoraux', 'Pectoraux'),
    VocabItem('epaules', 'Épaules'),
    VocabItem('jambes', 'Jambes'),
    VocabItem('cardio', 'Cardio'),
  ];

  static const List<VocabItem> sportObjectifs = [
    VocabItem('perte_de_gras', 'Perte de gras'),
    VocabItem('prise_de_muscle', 'Prise de muscle'),
    VocabItem('endurance', 'Endurance'),
    VocabItem('forme', 'Forme'),
    VocabItem('force', 'Force'),
  ];

  static const List<VocabItem> sportNiveaux = [
    VocabItem('debutant', 'Débutant'),
    VocabItem('intermediaire', 'Intermédiaire'),
    VocabItem('avance', 'Avancé'),
  ];

  static const List<VocabItem> sportMateriel = [
    VocabItem('aucun', 'Aucun'),
    VocabItem('halteres', 'Haltères'),
    VocabItem('barre', 'Barre'),
    VocabItem('kettlebell', 'Kettlebell'),
    VocabItem('elastiques', 'Élastiques'),
    VocabItem('banc', 'Banc'),
    VocabItem('barre_traction', 'Barre de traction'),
    VocabItem('machine', 'Machines'),
    VocabItem('tapis', 'Tapis de course'),
    VocabItem('velo', 'Vélo'),
  ];

  static const List<VocabItem> sportIntensites = [
    VocabItem('faible', 'Faible'),
    VocabItem('moderee', 'Modérée'),
    VocabItem('elevee', 'Élevée'),
  ];

  static const List<VocabItem> sportCategories = [
    VocabItem('endurance', 'Endurance'),
    VocabItem('force', 'Force'),
    VocabItem('collectif', 'Sports collectifs'),
    VocabItem('raquette', 'Raquette'),
    VocabItem('aquatique', 'Aquatique'),
    VocabItem('combat', 'Combat'),
    VocabItem('bien_etre', 'Bien-être'),
    VocabItem('glisse', 'Glisse'),
    VocabItem('autre', 'Autre'),
  ];

  static const List<VocabItem> sportTypes = [
    VocabItem('musculation', 'Musculation'),
    VocabItem('course_a_pied', 'Course à pied'),
    VocabItem('velo', 'Vélo'),
    VocabItem('natation', 'Natation'),
    VocabItem('hiit', 'HIIT'),
    VocabItem('yoga', 'Yoga / Mobilité'),
    VocabItem('marche', 'Marche'),
    VocabItem('rameur', 'Rameur'),
    VocabItem('autre', 'Autre'),
  ];

  static const Map<String, String> sessionStatusLabels = {
    'prevue': 'Prévue',
    'en_cours': 'En cours',
    'terminee': 'Terminée',
    'annulee': 'Annulée',
  };

  static const Map<String, String> planStatusLabels = {
    'prevu': 'Prévu',
    'realise': 'Réalisé',
    'annule': 'Annulé',
  };

  static const Map<String, String> blockLabels = {
    'echauffement': 'Échauffement',
    'principal': 'Circuit principal',
    'retour_au_calme': 'Retour au calme',
  };

  static const Map<String, String> muscleGroupLabels = {
    'jambes': 'Jambes',
    'fessiers': 'Fessiers',
    'dos': 'Dos',
    'pectoraux': 'Pectoraux',
    'epaules': 'Épaules',
    'bras': 'Bras',
    'abdos': 'Abdos',
    'corps_entier': 'Corps entier',
    'cardio': 'Cardio',
    'mobilite': 'Mobilité',
  };

  static const Map<String, String> exerciseCategoryLabels = {
    'force': 'Force',
    'cardio': 'Cardio',
    'mobilite': 'Mobilité',
    'gainage': 'Gainage',
  };

  // ----- Shopping / planner -------------------------------------------------
  static const Map<String, String> shoppingSourceLabels = {
    'manuel': 'Manuel',
    'auto_stock': 'Stock épuisé',
    'planificateur': 'Planificateur',
    'recommandation': 'Coach',
  };

  // ----- Recommendations ----------------------------------------------------
  static const Map<String, String> recoStatusLabels = {
    'new': 'Nouvelle',
    'acceptee': 'Acceptée',
    'ignoree': 'Ignorée',
  };

  /// Looks up a label in a vocab list, falling back to the key.
  static String vocabLabel(List<VocabItem> vocab, String? key) {
    if (key == null) return '—';
    for (final item in vocab) {
      if (item.key == key) return item.label;
    }
    return key;
  }
}
