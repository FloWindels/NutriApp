import '../core/api_client.dart';
import 'food.dart';

/// Page consultée par l'agent pour trouver les valeurs (`sources[]`).
class FoodLookupSource {
  final String titre;
  final String url;

  const FoodLookupSource({required this.titre, required this.url});

  factory FoodLookupSource.fromJson(Map<String, dynamic> json) {
    final url = parseString(json['url']) ?? '';
    return FoodLookupSource(titre: parseString(json['titre']) ?? url, url: url);
  }

  /// Seuls les liens web s'ouvrent : un autre schéma (intent:, javascript:, file:…) est ignoré.
  Uri? get uri {
    final parsed = Uri.tryParse(url.trim());
    if (parsed == null || !parsed.hasAuthority) return null;
    return parsed.scheme == 'http' || parsed.scheme == 'https' ? parsed : null;
  }
}

/// Valeurs proposées par l'agent (pour 100 g), à vérifier avant d'enregistrer l'aliment.
class FoodLookupProposal {
  final String nom;
  final String? marque;
  final String perUnit;
  final double? calories;
  final double? proteins;
  final double? carbs;
  final double? fat;
  final double? fiber;
  final double? sugar;
  final double? salt;
  final double? servingSizeG;

  /// `produit` (fiche d'un produit) ou `plat` (recette type).
  final String nature;

  /// Entre 0 et 1.
  final double? confiance;

  /// Vrai quand les valeurs sont calculées plutôt que lues sur une fiche.
  final bool estimation;

  const FoodLookupProposal({
    required this.nom,
    this.marque,
    this.perUnit = '100g',
    this.calories,
    this.proteins,
    this.carbs,
    this.fat,
    this.fiber,
    this.sugar,
    this.salt,
    this.servingSizeG,
    this.nature = 'produit',
    this.confiance,
    this.estimation = false,
  });

  factory FoodLookupProposal.fromJson(Map<String, dynamic> json) => FoodLookupProposal(
        nom: parseString(json['nom']) ?? '',
        marque: parseString(json['marque']),
        perUnit: parseString(json['per_unit']) ?? '100g',
        calories: parseNum(json['calories']),
        proteins: parseNum(json['proteins']),
        carbs: parseNum(json['carbs']),
        fat: parseNum(json['fat']),
        fiber: parseNum(json['fiber']),
        sugar: parseNum(json['sugar']),
        salt: parseNum(json['salt']),
        servingSizeG: parseNum(json['serving_size_g']),
        nature: parseString(json['nature']) ?? 'produit',
        confiance: parseNum(json['confiance']),
        estimation: parseBool(json['estimation']),
      );

  bool get estPlat => nature == 'plat';

  /// Valeurs à présenter comme une estimation (recette type ou calcul).
  bool get estEstimation => estimation || estPlat;
}

/// Réponse de `POST /foods/recherche-en-ligne`.
class FoodLookupResult {
  /// `catalogue` | `open_food_facts` | `internet` | `indisponible`.
  final String source;

  /// Aliment trouvé et enregistré : utilisable tel quel.
  final Food? food;

  /// Valeurs trouvées sur Internet, à faire vérifier dans le formulaire de création.
  final FoodLookupProposal? proposition;
  final List<FoodLookupSource> sources;
  final List<String> avertissements;
  final String? message;

  const FoodLookupResult({
    required this.source,
    this.food,
    this.proposition,
    this.sources = const [],
    this.avertissements = const [],
    this.message,
  });

  factory FoodLookupResult.fromJson(Map<String, dynamic> json) {
    final food = json['food'];
    final proposition = json['proposition'];
    return FoodLookupResult(
      source: parseString(json['source']) ?? 'indisponible',
      food: food is Map ? Food.fromJson(ApiClient.asMap(food)) : null,
      proposition: proposition is Map ? FoodLookupProposal.fromJson(ApiClient.asMap(proposition)) : null,
      sources: ApiClient.asList(json['sources']).map(FoodLookupSource.fromJson).toList(),
      avertissements: ApiClient.asStringList(json['avertissements']),
      message: parseString(json['message']),
    );
  }

  /// « Trouvé dans Open Food Facts » / « Trouvé dans le catalogue Mavi’oh ».
  String get trouveDans => source == 'open_food_facts' ? 'Trouvé dans Open Food Facts' : 'Trouvé dans le catalogue Mavi’oh';
}
