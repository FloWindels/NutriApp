import '../core/api_client.dart';
import 'stock.dart';

/// `{calories, proteins, carbs, fat, fiber, sugar, salt, is_partial}`.
class Totals {
  final double calories;
  final double proteins;
  final double carbs;
  final double fat;
  final double? fiber;
  final double? sugar;
  final double? salt;
  final bool isPartial;

  const Totals({
    this.calories = 0,
    this.proteins = 0,
    this.carbs = 0,
    this.fat = 0,
    this.fiber,
    this.sugar,
    this.salt,
    this.isPartial = false,
  });

  factory Totals.fromJson(Map<String, dynamic> json) => Totals(
        calories: parseNumOr(json['calories'], 0),
        proteins: parseNumOr(json['proteins'] ?? json['proteines'], 0),
        carbs: parseNumOr(json['carbs'] ?? json['glucides'], 0),
        fat: parseNumOr(json['fat'] ?? json['lipides'], 0),
        fiber: parseNum(json['fiber']),
        sugar: parseNum(json['sugar']),
        salt: parseNum(json['salt']),
        isPartial: parseBool(json['is_partial']),
      );

  bool get isZero => calories == 0 && proteins == 0 && carbs == 0 && fat == 0;
}

/// Embedded `food?: {id, barcode, brand, image_url}` on a meal item.
class MealItemFood {
  final int id;
  final String? barcode;
  final String? brand;
  final String? imageUrl;

  const MealItemFood({required this.id, this.barcode, this.brand, this.imageUrl});

  factory MealItemFood.fromJson(Map<String, dynamic> json) => MealItemFood(
        id: parseIntOr(json['id'], 0),
        barcode: parseString(json['barcode']),
        brand: parseString(json['brand']),
        imageUrl: parseString(json['image_url']),
      );
}

/// `MealItemResource` (§4.2).
class MealItem {
  final int id;
  final int mealId;
  final String sourceType; // food | recipe | custom
  final int? foodId;
  final int? recipeId;
  final int? stockItemId;
  final String label;
  final double quantity;
  final String unit;
  final double? gramsEquivalent;
  final double calories;
  final double proteins;
  final double carbs;
  final double fat;
  final double? fiber;
  final double? sugar;
  final double? salt;
  final bool isEstimate;
  final MealItemFood? food;
  final DateTime? createdAt;

  const MealItem({
    required this.id,
    required this.mealId,
    this.sourceType = 'food',
    this.foodId,
    this.recipeId,
    this.stockItemId,
    required this.label,
    this.quantity = 0,
    this.unit = 'g',
    this.gramsEquivalent,
    this.calories = 0,
    this.proteins = 0,
    this.carbs = 0,
    this.fat = 0,
    this.fiber,
    this.sugar,
    this.salt,
    this.isEstimate = false,
    this.food,
    this.createdAt,
  });

  factory MealItem.fromJson(Map<String, dynamic> json) => MealItem(
        id: parseIntOr(json['id'], 0),
        mealId: parseIntOr(json['meal_id'], 0),
        sourceType: parseString(json['source_type']) ?? 'food',
        foodId: parseInt(json['food_id']),
        recipeId: parseInt(json['recipe_id']),
        stockItemId: parseInt(json['stock_item_id']),
        label: parseString(json['label']) ?? 'Aliment',
        quantity: parseNumOr(json['quantity'], 0),
        unit: parseString(json['unit']) ?? 'g',
        gramsEquivalent: parseNum(json['grams_equivalent']),
        calories: parseNumOr(json['calories'], 0),
        proteins: parseNumOr(json['proteins'], 0),
        carbs: parseNumOr(json['carbs'], 0),
        fat: parseNumOr(json['fat'], 0),
        fiber: parseNum(json['fiber']),
        sugar: parseNum(json['sugar']),
        salt: parseNum(json['salt']),
        isEstimate: parseBool(json['is_estimate']),
        food: json['food'] is Map ? MealItemFood.fromJson(ApiClient.asMap(json['food'])) : null,
        createdAt: parseDate(json['created_at']),
      );
}

/// `MealResource = {id, date, type, name, consumed_at, notes, items, totals}`.
class Meal {
  final int id;
  final DateTime? date;
  final String type;
  final String? name;
  final DateTime? consumedAt;
  final String? notes;
  final List<MealItem> items;
  final Totals totals;

  const Meal({
    required this.id,
    this.date,
    required this.type,
    this.name,
    this.consumedAt,
    this.notes,
    this.items = const [],
    this.totals = const Totals(),
  });

  factory Meal.fromJson(Map<String, dynamic> json) => Meal(
        id: parseIntOr(json['id'], 0),
        date: parseDate(json['date']),
        type: parseString(json['type']) ?? 'dejeuner',
        name: parseString(json['name']),
        consumedAt: parseDate(json['consumed_at']),
        notes: parseString(json['notes']),
        items: ApiClient.asList(json['items']).map(MealItem.fromJson).toList(),
        totals: Totals.fromJson(ApiClient.asMap(json['totals'])),
      );

  bool get isEmpty => items.isEmpty;
}

/// `sport:{calories_burned, calories_bonus, coefficient, coef_calories?, explication?, is_estimate}`.
class SportBonus {
  final double caloriesBurned;
  final double caloriesBonus;
  final double? coefficient;
  final String? explication;
  final bool isEstimate;

  const SportBonus({
    this.caloriesBurned = 0,
    this.caloriesBonus = 0,
    this.coefficient,
    this.explication,
    this.isEstimate = true,
  });

  factory SportBonus.fromJson(Map<String, dynamic> json) {
    final coef = parseNum(json['coefficient']) ?? (parseNum(json['coef_calories']) == null ? null : parseNum(json['coef_calories'])! / 100);
    return SportBonus(
      caloriesBurned: parseNumOr(json['calories_burned'], 0),
      caloriesBonus: parseNumOr(json['calories_bonus'], 0),
      coefficient: coef,
      explication: parseString(json['explication']),
      isEstimate: parseBool(json['is_estimate'], fallback: true),
    );
  }

  /// Coefficient in percent (0–100).
  int get coefPct => ((coefficient ?? 1) * 100).round();
}

/// `GET /meals?date=` → `data`.
class DaySummary {
  final DateTime date;
  final List<Meal> meals;
  final Totals totals;
  final Totals targets;
  final Totals remaining;
  final SportBonus sport;
  final String? nextMealType;
  final double? plancherKcal;

  const DaySummary({
    required this.date,
    this.meals = const [],
    this.totals = const Totals(),
    this.targets = const Totals(),
    this.remaining = const Totals(),
    this.sport = const SportBonus(),
    this.nextMealType,
    this.plancherKcal,
  });

  factory DaySummary.fromJson(Map<String, dynamic> json) => DaySummary(
        date: parseDate(json['date']) ?? DateTime.now(),
        meals: ApiClient.asList(json['meals']).map(Meal.fromJson).toList(),
        totals: Totals.fromJson(ApiClient.asMap(json['totals'])),
        targets: Totals.fromJson(ApiClient.asMap(json['targets'])),
        remaining: Totals.fromJson(ApiClient.asMap(json['remaining'])),
        sport: SportBonus.fromJson(ApiClient.asMap(json['sport'])),
        nextMealType: parseString(json['next_meal_type']),
        plancherKcal: parseNum(json['plancher_kcal']),
      );

  /// Meal of a given type (null when not logged yet).
  Meal? mealOfType(String type) {
    for (final m in meals) {
      if (m.type == type) return m;
    }
    return null;
  }

  bool get hasTargets => targets.calories > 0;
}

/// `POST /meals` response (`{message, data, day, stock_decrements}`).
class MealMutationResult {
  final String? message;
  final Meal meal;
  final DaySummary? day;
  final List<StockDecrement> stockDecrements;

  const MealMutationResult({this.message, required this.meal, this.day, this.stockDecrements = const []});

  factory MealMutationResult.fromJson(Map<String, dynamic> json) => MealMutationResult(
        message: parseString(json['message']),
        meal: Meal.fromJson(ApiClient.asMap(json['data'])),
        day: json['day'] is Map ? DaySummary.fromJson(ApiClient.asMap(json['day'])) : null,
        stockDecrements: ApiClient.asList(json['stock_decrements']).map(StockDecrement.fromJson).toList(),
      );
}

/// `POST /meals/{meal}/items` and `PUT …/items/{item}` response.
class MealItemMutationResult {
  final String? message;
  final MealItem item;
  final DaySummary? day;
  final StockDecrement? stockDecrement;

  const MealItemMutationResult({this.message, required this.item, this.day, this.stockDecrement});

  factory MealItemMutationResult.fromJson(Map<String, dynamic> json) => MealItemMutationResult(
        message: parseString(json['message']),
        item: MealItem.fromJson(ApiClient.asMap(json['data'])),
        day: json['day'] is Map ? DaySummary.fromJson(ApiClient.asMap(json['day'])) : null,
        stockDecrement:
            json['stock_decrement'] is Map ? StockDecrement.fromJson(ApiClient.asMap(json['stock_decrement'])) : null,
      );
}

/// `GET /meals/frequent` row.
class FrequentItem {
  final String kind; // food | recipe
  final int id;
  final String label;
  final String? brand;
  final double lastQuantity;
  final String lastUnit;
  final double? caloriesPer100g;
  final double? caloriesPerServing;
  final int count;

  const FrequentItem({
    required this.kind,
    required this.id,
    required this.label,
    this.brand,
    this.lastQuantity = 1,
    this.lastUnit = 'portion',
    this.caloriesPer100g,
    this.caloriesPerServing,
    this.count = 0,
  });

  factory FrequentItem.fromJson(Map<String, dynamic> json) => FrequentItem(
        kind: parseString(json['kind']) ?? 'food',
        id: parseIntOr(json['id'], 0),
        label: parseString(json['label']) ?? 'Aliment',
        brand: parseString(json['brand']),
        lastQuantity: parseNumOr(json['last_quantity'], 1),
        lastUnit: parseString(json['last_unit']) ?? (parseString(json['kind']) == 'recipe' ? 'portion' : 'g'),
        caloriesPer100g: parseNum(json['calories_per_100g']),
        caloriesPerServing: parseNum(json['calories_per_serving']),
        count: parseIntOr(json['count'], 0),
      );

  bool get isRecipe => kind == 'recipe';
}

/// Custom item body `{label, per_100g, calories, proteins, carbs, fat}`.
class CustomItemInput {
  final String label;
  final bool per100g;
  final double calories;
  final double proteins;
  final double carbs;
  final double fat;

  const CustomItemInput({
    required this.label,
    this.per100g = false,
    required this.calories,
    this.proteins = 0,
    this.carbs = 0,
    this.fat = 0,
  });

  Map<String, dynamic> toJson() => {
        'label': label,
        'per_100g': per100g,
        'calories': calories,
        'proteins': proteins,
        'carbs': carbs,
        'fat': fat,
      };
}

/// `ItemInput` (§4.2) — exactly one of food/recipe/custom.
class MealItemInput {
  final int? foodId;
  final int? recipeId;
  final CustomItemInput? custom;
  final double quantity;
  final String unit;
  final int? stockItemId;
  final bool? decrementStock;

  const MealItemInput({
    this.foodId,
    this.recipeId,
    this.custom,
    required this.quantity,
    required this.unit,
    this.stockItemId,
    this.decrementStock,
  }) : assert((foodId != null ? 1 : 0) + (recipeId != null ? 1 : 0) + (custom != null ? 1 : 0) == 1,
            'Exactly one of foodId, recipeId, custom is required');

  Map<String, dynamic> toJson() {
    final map = <String, dynamic>{
      'food_id': ?foodId,
      'recipe_id': ?recipeId,
      if (custom != null) 'custom': custom!.toJson(),
      'quantity': quantity,
      'unit': unit,
      'stock_item_id': ?stockItemId,
      'decrement_stock': ?decrementStock,
    };
    return map;
  }
}

/// `GET /meals/history` row (also used by `/history.days`).
class MealHistoryRow {
  final DateTime date;
  final double calories;
  final double proteins;
  final double carbs;
  final double fat;
  final double? targetCalories;
  final double? targetProteins;
  final double? targetCarbs;
  final double? targetFat;
  final int mealsCount;
  final int sportMinutes;
  final double caloriesBurned;

  const MealHistoryRow({
    required this.date,
    this.calories = 0,
    this.proteins = 0,
    this.carbs = 0,
    this.fat = 0,
    this.targetCalories,
    this.targetProteins,
    this.targetCarbs,
    this.targetFat,
    this.mealsCount = 0,
    this.sportMinutes = 0,
    this.caloriesBurned = 0,
  });

  factory MealHistoryRow.fromJson(Map<String, dynamic> json) => MealHistoryRow(
        date: parseDate(json['date']) ?? DateTime.now(),
        calories: parseNumOr(json['calories'], 0),
        proteins: parseNumOr(json['proteins'], 0),
        carbs: parseNumOr(json['carbs'], 0),
        fat: parseNumOr(json['fat'], 0),
        targetCalories: parseNum(json['target_calories']),
        targetProteins: parseNum(json['target_proteins']),
        targetCarbs: parseNum(json['target_carbs']),
        targetFat: parseNum(json['target_fat']),
        mealsCount: parseIntOr(json['meals_count'], 0),
        sportMinutes: parseIntOr(json['sport_minutes'], 0),
        caloriesBurned: parseNumOr(json['calories_burned'], 0),
      );
}
