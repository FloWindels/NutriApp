import '../core/api_client.dart';

/// Recipe ingredient `{name, ean, amount, unit}`.
class RecipeIngredient {
  final String name;
  final String? ean;
  final double? amount;
  final String? unit;

  const RecipeIngredient({required this.name, this.ean, this.amount, this.unit});

  factory RecipeIngredient.fromJson(Map<String, dynamic> json) => RecipeIngredient(
        name: parseString(json['name']) ?? '',
        ean: parseString(json['ean']),
        amount: parseNum(json['amount']),
        unit: parseString(json['unit']),
      );

  Map<String, dynamic> toJson() => {
        'name': name,
        'ean': ean,
        'amount': amount,
        'unit': unit,
      };
}

/// `per_serving {calories, proteins|null, carbs|null, fat|null}`.
class PerServing {
  final double? calories;
  final double? proteins;
  final double? carbs;
  final double? fat;

  const PerServing({this.calories, this.proteins, this.carbs, this.fat});

  factory PerServing.fromJson(Map<String, dynamic> json) => PerServing(
        calories: parseNum(json['calories']),
        proteins: parseNum(json['proteins']),
        carbs: parseNum(json['carbs']),
        fat: parseNum(json['fat']),
      );

  bool get hasMacros => proteins != null && carbs != null && fat != null;
}

/// Recipe payload (§0.3 + §5).
class Recipe {
  final int id;
  final String title;
  final String? description;
  final int? prepTimeMinutes;
  final double? calories;
  final String? imageUrl;
  final List<RecipeIngredient> ingredients;
  final int ingredientsCount;
  final bool isPublic;
  final bool isOwner;
  final int? createdByUserId;
  final double servings;
  final double? proteins;
  final double? carbs;
  final double? fat;
  final List<String> tags;
  final List<String> mealTypes;
  final PerServing perServing;
  final bool hasMacros;
  final bool isEstimate;
  final DateTime? createdAt;
  final DateTime? updatedAt;

  const Recipe({
    required this.id,
    required this.title,
    this.description,
    this.prepTimeMinutes,
    this.calories,
    this.imageUrl,
    this.ingredients = const [],
    this.ingredientsCount = 0,
    this.isPublic = false,
    this.isOwner = false,
    this.createdByUserId,
    this.servings = 1,
    this.proteins,
    this.carbs,
    this.fat,
    this.tags = const [],
    this.mealTypes = const [],
    this.perServing = const PerServing(),
    this.hasMacros = false,
    this.isEstimate = false,
    this.createdAt,
    this.updatedAt,
  });

  factory Recipe.fromJson(Map<String, dynamic> json) {
    final servings = parseNumOr(json['servings'], 1);
    final calories = parseNum(json['calories']);
    final perServingJson = json['per_serving'];
    final perServing = perServingJson is Map
        ? PerServing.fromJson(ApiClient.asMap(perServingJson))
        : PerServing(calories: calories == null || servings <= 0 ? calories : calories / servings);
    return Recipe(
      id: parseIntOr(json['id'], 0),
      title: parseString(json['title']) ?? 'Recette',
      description: parseString(json['description']),
      prepTimeMinutes: parseInt(json['prep_time_minutes']),
      calories: calories,
      imageUrl: parseString(json['image_url']),
      ingredients: ApiClient.asList(json['ingredients']).map(RecipeIngredient.fromJson).toList(),
      ingredientsCount: parseIntOr(json['ingredients_count'], ApiClient.asList(json['ingredients']).length),
      isPublic: parseBool(json['is_public']),
      isOwner: parseBool(json['is_owner']),
      createdByUserId: parseInt(json['created_by_user_id']),
      servings: servings <= 0 ? 1 : servings,
      proteins: parseNum(json['proteins']),
      carbs: parseNum(json['carbs']),
      fat: parseNum(json['fat']),
      tags: ApiClient.asStringList(json['tags']),
      mealTypes: ApiClient.asStringList(json['meal_types']),
      perServing: perServing,
      hasMacros: parseBool(json['has_macros'], fallback: perServing.hasMacros),
      isEstimate: parseBool(json['is_estimate']),
      createdAt: parseDate(json['created_at']),
      updatedAt: parseDate(json['updated_at']),
    );
  }

  /// Body for `POST /recipes` / `PUT /recipes/{id}`.
  Map<String, dynamic> toJson() {
    final map = <String, dynamic>{
      'title': title,
      'description': description,
      'prep_time_minutes': prepTimeMinutes,
      'calories': calories,
      'image_url': imageUrl,
      'ingredients': ingredients.map((i) => i.toJson()).toList(),
      'is_public': isPublic,
      'servings': servings,
      'proteins': proteins,
      'carbs': carbs,
      'fat': fat,
      'tags': tags,
      'meal_types': mealTypes,
    };
    map.removeWhere((key, value) => value == null);
    return map;
  }
}

/// `POST /recipes/estimate` response.
class RecipeEstimate {
  final double? calories;
  final double? proteins;
  final double? carbs;
  final double? fat;
  final int resolvedCount;
  final int totalCount;
  final bool isEstimate;
  final List<RecipeEstimateDetail> details;

  const RecipeEstimate({
    this.calories,
    this.proteins,
    this.carbs,
    this.fat,
    this.resolvedCount = 0,
    this.totalCount = 0,
    this.isEstimate = true,
    this.details = const [],
  });

  factory RecipeEstimate.fromJson(Map<String, dynamic> json) => RecipeEstimate(
        calories: parseNum(json['calories']),
        proteins: parseNum(json['proteins']),
        carbs: parseNum(json['carbs']),
        fat: parseNum(json['fat']),
        resolvedCount: parseIntOr(json['resolved_count'], 0),
        totalCount: parseIntOr(json['total_count'], 0),
        isEstimate: parseBool(json['is_estimate'], fallback: true),
        details: ApiClient.asList(json['details']).map(RecipeEstimateDetail.fromJson).toList(),
      );
}

class RecipeEstimateDetail {
  final String name;
  final bool resolved;
  final double? grams;

  const RecipeEstimateDetail({required this.name, this.resolved = false, this.grams});

  factory RecipeEstimateDetail.fromJson(Map<String, dynamic> json) => RecipeEstimateDetail(
        name: parseString(json['name']) ?? '',
        resolved: parseBool(json['resolved']),
        grams: parseNum(json['grams']),
      );
}
