import '../core/api_client.dart';

/// `GET /diets` entry `{key, nom, description, principes, mineurs_autorise}`;
/// `GET /diets/{key}` adds `regles, aliments_conseilles, aliments_a_limiter, conseils`.
class Diet {
  final String key;
  final String nom;
  final String description;
  final List<String> principes;
  final bool mineursAutorise;
  final Map<String, dynamic> regles;
  final List<String> alimentsConseilles;
  final List<String> alimentsALimiter;
  final List<String> conseils;

  const Diet({
    required this.key,
    required this.nom,
    this.description = '',
    this.principes = const [],
    this.mineursAutorise = true,
    this.regles = const {},
    this.alimentsConseilles = const [],
    this.alimentsALimiter = const [],
    this.conseils = const [],
  });

  factory Diet.fromJson(Map<String, dynamic> json) => Diet(
        key: parseString(json['key']) ?? 'autre',
        nom: parseString(json['nom']) ?? parseString(json['key']) ?? 'Régime',
        description: parseString(json['description']) ?? '',
        principes: ApiClient.asStringList(json['principes']),
        mineursAutorise: parseBool(json['mineurs_autorise'], fallback: true),
        regles: ApiClient.asMap(json['regles']),
        alimentsConseilles: ApiClient.asStringList(json['aliments_conseilles']),
        alimentsALimiter: ApiClient.asStringList(json['aliments_a_limiter']),
        conseils: ApiClient.asStringList(json['conseils']),
      );

  bool get hasDetails => alimentsConseilles.isNotEmpty || conseils.isNotEmpty || regles.isNotEmpty;
}

/// `ecarts:[{date, meal_type, item_label, regle, explication}]`.
class DietEcart {
  final DateTime? date;
  final String? mealType;
  final String? itemLabel;
  final String regle;
  final String explication;

  const DietEcart({this.date, this.mealType, this.itemLabel, this.regle = '', this.explication = ''});

  factory DietEcart.fromJson(Map<String, dynamic> json) => DietEcart(
        date: parseDate(json['date']),
        mealType: parseString(json['meal_type']),
        itemLabel: parseString(json['item_label']),
        regle: parseString(json['regle']) ?? '',
        explication: parseString(json['explication']) ?? '',
      );
}

/// `regimes_proches:[{key, nom, score_pct, raisons}]`.
class RegimeProche {
  final String key;
  final String nom;
  final double? scorePct;
  final List<String> raisons;

  const RegimeProche({required this.key, required this.nom, this.scorePct, this.raisons = const []});

  factory RegimeProche.fromJson(Map<String, dynamic> json) => RegimeProche(
        key: parseString(json['key']) ?? '',
        nom: parseString(json['nom']) ?? parseString(json['key']) ?? '',
        scorePct: parseNum(json['score_pct']),
        raisons: ApiClient.asStringList(json['raisons']),
      );
}

/// `GET /diets/evaluate?days=` → `data`.
class DietEvaluation {
  final String? regime;
  final double? scorePct;
  final String statut; // conforme | partiel | non_conforme | donnees_insuffisantes
  final String? message;
  final List<DietEcart> ecarts;
  final List<String> conseils;
  final List<RegimeProche> regimesProches;
  final String mention;
  final bool isEstimate;

  const DietEvaluation({
    this.regime,
    this.scorePct,
    this.statut = 'donnees_insuffisantes',
    this.message,
    this.ecarts = const [],
    this.conseils = const [],
    this.regimesProches = const [],
    this.mention = '',
    this.isEstimate = true,
  });

  factory DietEvaluation.fromJson(Map<String, dynamic> json) => DietEvaluation(
        regime: parseString(json['regime']),
        scorePct: parseNum(json['score_pct']),
        statut: parseString(json['statut']) ?? 'donnees_insuffisantes',
        message: parseString(json['message']),
        ecarts: ApiClient.asList(json['ecarts']).map(DietEcart.fromJson).toList(),
        conseils: ApiClient.asStringList(json['conseils']),
        regimesProches: ApiClient.asList(json['regimes_proches']).map(RegimeProche.fromJson).toList(),
        mention: parseString(json['mention']) ?? '',
        isEstimate: parseBool(json['is_estimate'], fallback: true),
      );

  bool get insufficientData => statut == 'donnees_insuffisantes';

  String get statutLabel {
    switch (statut) {
      case 'conforme':
        return 'Conforme';
      case 'partiel':
        return 'Partiellement conforme';
      case 'non_conforme':
        return 'Non conforme';
      default:
        return 'Données insuffisantes';
    }
  }
}
