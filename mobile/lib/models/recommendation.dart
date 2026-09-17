import '../core/api_client.dart';

/// `actions[]` entry of a recommendation.
///
/// `kind ∈ ajouter_au_repas | ouvrir_recette | ouvrir_stock | generer_seance |
/// ajouter_courses | ouvrir_planificateur | supprimer_stock`.
class RecoAction {
  final String kind;
  final int? foodId;
  final int? recipeId;
  final double? quantity;
  final String? unit;
  final String? mealType;
  final List<int> stockItemIds;
  final int? stockItemId;
  final String? label;
  final DateTime? date;
  final Map<String, dynamic> raw;

  const RecoAction({
    required this.kind,
    this.foodId,
    this.recipeId,
    this.quantity,
    this.unit,
    this.mealType,
    this.stockItemIds = const [],
    this.stockItemId,
    this.label,
    this.date,
    this.raw = const {},
  });

  factory RecoAction.fromJson(Map<String, dynamic> json) {
    final ids = <int>[];
    if (json['stock_item_ids'] is List) {
      for (final v in json['stock_item_ids'] as List) {
        final id = parseInt(v);
        if (id != null) ids.add(id);
      }
    }
    return RecoAction(
      kind: parseString(json['kind']) ?? 'inconnu',
      foodId: parseInt(json['food_id']),
      recipeId: parseInt(json['recipe_id']),
      quantity: parseNum(json['quantity']),
      unit: parseString(json['unit']),
      mealType: parseString(json['meal_type']),
      stockItemIds: ids,
      stockItemId: parseInt(json['stock_item_id']),
      label: parseString(json['label']),
      date: parseDate(json['date']),
      raw: json,
    );
  }

  /// French button label for the action chip.
  String get buttonLabel {
    switch (kind) {
      case 'ajouter_au_repas':
        return 'Ajouter au repas';
      case 'ouvrir_recette':
        return 'Voir la recette';
      case 'ouvrir_stock':
        return 'Voir le stock';
      case 'generer_seance':
        return 'Générer une séance';
      case 'ajouter_courses':
        return 'Ajouter aux courses';
      case 'ouvrir_planificateur':
        return 'Ouvrir le planificateur';
      case 'supprimer_stock':
        return 'Retirer du stock';
      default:
        return 'Ouvrir';
    }
  }
}

/// `RecommendationResource = {id, date, type, title, message, factors, actions, priority, status, is_estimate}`.
class Recommendation {
  final int id;
  final DateTime? date;
  final String type;
  final String title;
  final String message;
  final List<String> factors;
  final List<RecoAction> actions;
  final int priority; // 1 sécurité · 2 objectif · 3 confort
  final String status; // new | acceptee | ignoree
  final bool isEstimate;

  const Recommendation({
    required this.id,
    this.date,
    required this.type,
    required this.title,
    required this.message,
    this.factors = const [],
    this.actions = const [],
    this.priority = 3,
    this.status = 'new',
    this.isEstimate = false,
  });

  factory Recommendation.fromJson(Map<String, dynamic> json) => Recommendation(
        id: parseIntOr(json['id'], 0),
        date: parseDate(json['date']),
        type: parseString(json['type']) ?? '',
        title: parseString(json['title']) ?? '',
        message: parseString(json['message']) ?? '',
        factors: _factors(json['factors']),
        actions: ApiClient.asList(json['actions']).map(RecoAction.fromJson).toList(),
        priority: parseIntOr(json['priority'], 3),
        status: parseString(json['status']) ?? 'new',
        isEstimate: parseBool(json['is_estimate']),
      );

  static List<String> _factors(dynamic value) {
    if (value is List) {
      return value.map((e) {
        if (e is Map) {
          final label = parseString(e['label']) ?? parseString(e['name']);
          final val = e['value'];
          if (label != null && val != null) return '$label : $val';
          return e.values.map((v) => v.toString()).join(' · ');
        }
        return e.toString();
      }).toList();
    }
    if (value is Map) {
      return value.entries.map((e) => '${e.key} : ${e.value}').toList();
    }
    return const [];
  }

  bool get isIgnored => status == 'ignoree';

  bool get isAccepted => status == 'acceptee';

  Recommendation copyWith({String? status}) => Recommendation(
        id: id,
        date: date,
        type: type,
        title: title,
        message: message,
        factors: factors,
        actions: actions,
        priority: priority,
        status: status ?? this.status,
        isEstimate: isEstimate,
      );
}
