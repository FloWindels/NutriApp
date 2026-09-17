import '../core/api_client.dart';

/// `besoins` computed by `NutritionCalculator` (§2.3).
class Besoins {
  final double? bmr;
  final double? tdee;
  final double? caloriesRecommandees;
  final double? proteinesG;
  final double? glucidesG;
  final double? lipidesG;
  final double? ajustementKcal;
  final double? variationHebdoKg;
  final double? plancherKcal;
  final double? poidsReference;
  final int? joursRestants;
  final DateTime? ciblesCalculeesLe;
  final double? imc;
  final double? imcCible;
  final double? fibresG;
  final double? selMaxG;
  final bool profilMineur;
  final List<String> avertissements;
  final List<String> etapes;
  final String mention;
  final bool isEstimate;

  const Besoins({
    this.bmr,
    this.tdee,
    this.caloriesRecommandees,
    this.proteinesG,
    this.glucidesG,
    this.lipidesG,
    this.ajustementKcal,
    this.variationHebdoKg,
    this.plancherKcal,
    this.poidsReference,
    this.joursRestants,
    this.ciblesCalculeesLe,
    this.imc,
    this.imcCible,
    this.fibresG,
    this.selMaxG,
    this.profilMineur = false,
    this.avertissements = const [],
    this.etapes = const [],
    this.mention = '',
    this.isEstimate = true,
  });

  factory Besoins.fromJson(Map<String, dynamic> json) => Besoins(
        bmr: parseNum(json['bmr']),
        tdee: parseNum(json['tdee']),
        caloriesRecommandees: parseNum(json['calories_recommandees']),
        proteinesG: parseNum(json['proteines_g']),
        glucidesG: parseNum(json['glucides_g']),
        lipidesG: parseNum(json['lipides_g']),
        ajustementKcal: parseNum(json['ajustement_kcal']),
        variationHebdoKg: parseNum(json['variation_hebdo_kg']),
        plancherKcal: parseNum(json['plancher_kcal']),
        poidsReference: parseNum(json['poids_reference']),
        joursRestants: parseInt(json['jours_restants']),
        ciblesCalculeesLe: parseDate(json['cibles_calculees_le']),
        imc: parseNum(json['imc']),
        imcCible: parseNum(json['imc_cible']),
        fibresG: parseNum(json['fibres_g']),
        selMaxG: parseNum(json['sel_max_g']),
        profilMineur: parseBool(json['profil_mineur']),
        avertissements: ApiClient.asStringList(json['avertissements']),
        etapes: ApiClient.asStringList(json['etapes']),
        mention: parseString(json['mention']) ?? '',
        isEstimate: parseBool(json['is_estimate'], fallback: true),
      );
}

/// `cibles_effectives {calories, proteines, glucides, lipides, source}`.
class CiblesEffectives {
  final double calories;
  final double proteines;
  final double glucides;
  final double lipides;
  final String source; // calcul | utilisateur

  const CiblesEffectives({
    this.calories = 0,
    this.proteines = 0,
    this.glucides = 0,
    this.lipides = 0,
    this.source = 'calcul',
  });

  factory CiblesEffectives.fromJson(Map<String, dynamic> json) => CiblesEffectives(
        calories: parseNumOr(json['calories'], 0),
        proteines: parseNumOr(json['proteines'], 0),
        glucides: parseNumOr(json['glucides'], 0),
        lipides: parseNumOr(json['lipides'], 0),
        source: parseString(json['source']) ?? 'calcul',
      );

  bool get isManual => source == 'utilisateur';
}

/// `POST /profile/preview` → `{besoins, cibles_effectives, imc, imc_cible}`.
class ProfilePreview {
  final Besoins besoins;
  final CiblesEffectives ciblesEffectives;
  final double? imc;
  final double? imcCible;

  const ProfilePreview({
    required this.besoins,
    required this.ciblesEffectives,
    this.imc,
    this.imcCible,
  });

  factory ProfilePreview.fromJson(Map<String, dynamic> json) => ProfilePreview(
        besoins: Besoins.fromJson(ApiClient.asMap(json['besoins'])),
        ciblesEffectives: CiblesEffectives.fromJson(ApiClient.asMap(json['cibles_effectives'])),
        imc: parseNum(json['imc']),
        imcCible: parseNum(json['imc_cible']),
      );
}

/// `GET /profile` (legacy top-level fields + siblings, §2.2).
class Profile {
  final String? nom;
  final double? poids;
  final double? poidsSouhaiteKg;
  final int? delaiObjectifJours;
  final double? taille;
  final int? age;
  final String? sexe;
  final String? objectif;
  final String? objectifType;
  final String? niveauActivite;
  final double? caloriesCibles;
  final double? proteinesCibles;
  final double? glucidesCibles;
  final double? lipidesCibles;
  final String? regimeAlimentaire;

  final List<String> allergenes;
  final List<String> alimentsExclus;
  final List<String> preferencesAime;
  final List<String> preferencesEvite;
  final String? sportNiveau;
  final String? sportObjectif;
  final List<String> sportMateriel;
  final int? sportTempsDispoMin;
  final int? sportJoursSemaine;
  final String? sportLieu;
  final List<String> sportZonesAEviter;
  final List<String> sportFocus;
  final String? sportNotes;
  final int sportCoefCalories;
  final bool objectifCalculAuto;
  final DateTime? objectifDateDebut;
  final DateTime? objectifDateFin;
  final double? poidsReference;
  final DateTime? ciblesCalculeesLe;
  final String situationParticuliere;
  final bool consentementParental;

  /// `users.consentement_sante_at !== null` (additive sibling of `GET /profile`).
  final bool consentementSante;

  final Besoins? besoins;
  final CiblesEffectives? ciblesEffectives;
  final bool hasProfile;
  final double? imc;
  final double? imcCible;

  const Profile({
    this.nom,
    this.poids,
    this.poidsSouhaiteKg,
    this.delaiObjectifJours,
    this.taille,
    this.age,
    this.sexe,
    this.objectif,
    this.objectifType,
    this.niveauActivite,
    this.caloriesCibles,
    this.proteinesCibles,
    this.glucidesCibles,
    this.lipidesCibles,
    this.regimeAlimentaire,
    this.allergenes = const [],
    this.alimentsExclus = const [],
    this.preferencesAime = const [],
    this.preferencesEvite = const [],
    this.sportNiveau,
    this.sportObjectif,
    this.sportMateriel = const [],
    this.sportTempsDispoMin,
    this.sportJoursSemaine,
    this.sportLieu,
    this.sportZonesAEviter = const [],
    this.sportFocus = const [],
    this.sportNotes,
    this.sportCoefCalories = 100,
    this.objectifCalculAuto = true,
    this.objectifDateDebut,
    this.objectifDateFin,
    this.poidsReference,
    this.ciblesCalculeesLe,
    this.situationParticuliere = 'aucune',
    this.consentementParental = false,
    this.consentementSante = false,
    this.besoins,
    this.ciblesEffectives,
    this.hasProfile = false,
    this.imc,
    this.imcCible,
  });

  factory Profile.fromJson(Map<String, dynamic> json) {
    final prefs = ApiClient.asMap(json['preferences']);
    return Profile(
      nom: parseString(json['nom']),
      poids: parseNum(json['poids']),
      poidsSouhaiteKg: parseNum(json['poids_souhaite_kg']),
      delaiObjectifJours: parseInt(json['delai_objectif_jours']),
      taille: parseNum(json['taille']),
      age: parseInt(json['age']),
      sexe: parseString(json['sexe']),
      objectif: parseString(json['objectif']),
      objectifType: parseString(json['objectif_type']),
      niveauActivite: parseString(json['niveau_activite']),
      caloriesCibles: parseNum(json['calories_cibles']),
      proteinesCibles: parseNum(json['proteines_cibles']),
      glucidesCibles: parseNum(json['glucides_cibles']),
      lipidesCibles: parseNum(json['lipides_cibles']),
      regimeAlimentaire: parseString(json['regime_alimentaire']),
      allergenes: ApiClient.asStringList(json['allergenes']),
      alimentsExclus: ApiClient.asStringList(json['aliments_exclus']),
      preferencesAime: ApiClient.asStringList(prefs['aime']),
      preferencesEvite: ApiClient.asStringList(prefs['evite']),
      sportNiveau: parseString(json['sport_niveau']),
      sportObjectif: parseString(json['sport_objectif']),
      sportMateriel: ApiClient.asStringList(json['sport_materiel']),
      sportTempsDispoMin: parseInt(json['sport_temps_dispo_min']),
      sportJoursSemaine: parseInt(json['sport_jours_semaine']),
      sportLieu: parseString(json['sport_lieu']),
      sportZonesAEviter: ApiClient.asStringList(json['sport_zones_a_eviter']),
      sportFocus: ApiClient.asStringList(json['sport_focus']),
      sportNotes: parseString(json['sport_notes']),
      sportCoefCalories: parseIntOr(json['sport_coef_calories'], 100),
      objectifCalculAuto: parseBool(json['objectif_calcul_auto'], fallback: true),
      objectifDateDebut: parseDate(json['objectif_date_debut']),
      objectifDateFin: parseDate(json['objectif_date_fin']),
      poidsReference: parseNum(json['poids_reference']),
      ciblesCalculeesLe: parseDate(json['cibles_calculees_le']),
      situationParticuliere: parseString(json['situation_particuliere']) ?? 'aucune',
      consentementParental: parseBool(json['consentement_parental']),
      consentementSante: parseBool(json['consentement_sante']),
      besoins: json['besoins'] is Map ? Besoins.fromJson(ApiClient.asMap(json['besoins'])) : null,
      ciblesEffectives: json['cibles_effectives'] is Map
          ? CiblesEffectives.fromJson(ApiClient.asMap(json['cibles_effectives']))
          : null,
      hasProfile: parseBool(json['has_profile'], fallback: json['poids'] != null),
      imc: parseNum(json['imc']),
      imcCible: parseNum(json['imc_cible']),
    );
  }

  /// Body for `PUT /profile` / `POST /profile/preview` (nulls dropped).
  Map<String, dynamic> toJson({bool? consentementSante}) {
    final map = <String, dynamic>{
      'nom': nom,
      'poids': poids,
      'poids_souhaite_kg': poidsSouhaiteKg,
      'delai_objectif_jours': delaiObjectifJours,
      'taille': taille,
      'age': age,
      'sexe': sexe,
      'objectif': objectif,
      'objectif_type': objectifType,
      'niveau_activite': niveauActivite,
      'regime_alimentaire': regimeAlimentaire,
      'allergenes': allergenes,
      'aliments_exclus': alimentsExclus,
      'preferences': {'aime': preferencesAime, 'evite': preferencesEvite},
      'sport_niveau': sportNiveau,
      'sport_objectif': sportObjectif,
      'sport_materiel': sportMateriel,
      'sport_temps_dispo_min': sportTempsDispoMin,
      'sport_jours_semaine': sportJoursSemaine,
      'sport_lieu': sportLieu,
      'sport_zones_a_eviter': sportZonesAEviter,
      'sport_focus': sportFocus,
      'sport_notes': sportNotes,
      'sport_coef_calories': sportCoefCalories,
      'objectif_calcul_auto': objectifCalculAuto,
      'situation_particuliere': situationParticuliere,
      'consentement_parental': consentementParental,
      if (!objectifCalculAuto) ...{
        'calories_cibles': caloriesCibles,
        'proteines_cibles': proteinesCibles,
        'glucides_cibles': glucidesCibles,
        'lipides_cibles': lipidesCibles,
      },
      'consentement_sante': ?consentementSante,
    };
    map.removeWhere((key, value) => value == null);
    return map;
  }

  bool get isMinor => (age ?? 18) < 18;

  bool get hasSituation => situationParticuliere != 'aucune';
}
