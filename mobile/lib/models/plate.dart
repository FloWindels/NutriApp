import '../core/api_client.dart';

/// Reconnaissance d'une photo d'assiette (`POST /meals/analyze-photo`).
///
/// Miroir exact du contrat serveur : une ligne porte soit une fiche du catalogue — et ses macros
/// font foi —, soit les valeurs proposées par le modèle, à vérifier.

class PlateFood {
  final int id;
  final String name;
  final String? brand;
  final double? calories;

  const PlateFood({required this.id, required this.name, this.brand, this.calories});

  factory PlateFood.fromJson(Map<String, dynamic> json) => PlateFood(
        id: parseIntOr(json['id'], 0),
        name: parseString(json['name']) ?? '',
        brand: parseString(json['brand']),
        calories: parseNum(json['calories']),
      );
}

class PlateProposedValues {
  final double? calories;
  final double? proteines;
  final double? glucides;
  final double? lipides;

  const PlateProposedValues({this.calories, this.proteines, this.glucides, this.lipides});

  factory PlateProposedValues.fromJson(Map<String, dynamic> json) => PlateProposedValues(
        calories: parseNum(json['calories']),
        proteines: parseNum(json['proteines']),
        glucides: parseNum(json['glucides']),
        lipides: parseNum(json['lipides']),
      );
}

class PlateLine {
  final String nom;
  final String? marque;
  final double quantite;
  final String unite;
  final double confiance;
  final PlateFood? food;
  final PlateProposedValues? valeursProposees;

  const PlateLine({
    required this.nom,
    this.marque,
    required this.quantite,
    required this.unite,
    this.confiance = 0.5,
    this.food,
    this.valeursProposees,
  });

  factory PlateLine.fromJson(Map<String, dynamic> json) => PlateLine(
        nom: parseString(json['nom']) ?? '',
        marque: parseString(json['marque']),
        quantite: parseNum(json['quantite']) ?? 100,
        unite: parseString(json['unite']) ?? 'g',
        confiance: parseNum(json['confiance']) ?? 0.5,
        food: json['food'] is Map<String, dynamic>
            ? PlateFood.fromJson(json['food'] as Map<String, dynamic>)
            : null,
        valeursProposees: json['valeurs_proposees'] is Map<String, dynamic>
            ? PlateProposedValues.fromJson(json['valeurs_proposees'] as Map<String, dynamic>)
            : null,
      );
}

class PlateAnalysis {
  final List<PlateLine> aliments;
  final String description;
  final List<String> avertissements;

  /// `ia` ou `indisponible` : dans le second cas, la saisie reste manuelle.
  final String source;
  final String? llmModel;

  const PlateAnalysis({
    this.aliments = const [],
    this.description = '',
    this.avertissements = const [],
    this.source = 'indisponible',
    this.llmModel,
  });

  bool get disponible => source == 'ia' && aliments.isNotEmpty;

  factory PlateAnalysis.fromJson(Map<String, dynamic> json) => PlateAnalysis(
        aliments: ApiClient.asList(json['aliments']).map(PlateLine.fromJson).toList(),
        description: parseString(json['description']) ?? '',
        avertissements: ApiClient.asStringList(json['avertissements']),
        source: parseString(json['source']) ?? 'indisponible',
        llmModel: parseString(json['llm_model']),
      );
}
