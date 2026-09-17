import '../core/api_client.dart';
import '../core/strings.dart';

/// `meal_plans` row (§12).
class MealPlan {
  final int id;
  final DateTime date;
  final String mealType;
  final int? recipeId;
  final int? foodId;
  final String title;
  final double servings;
  final String? notes;
  final String status; // prevu | realise | annule
  final int? mealId;
  final double? calories;
  final String? recipeImageUrl;

  const MealPlan({
    required this.id,
    required this.date,
    required this.mealType,
    this.recipeId,
    this.foodId,
    required this.title,
    this.servings = 1,
    this.notes,
    this.status = 'prevu',
    this.mealId,
    this.calories,
    this.recipeImageUrl,
  });

  factory MealPlan.fromJson(Map<String, dynamic> json) {
    final recipe = json['recipe'] is Map ? ApiClient.asMap(json['recipe']) : const <String, dynamic>{};
    return MealPlan(
      id: parseIntOr(json['id'], 0),
      date: parseDate(json['date']) ?? DateTime.now(),
      mealType: parseString(json['meal_type']) ?? 'dejeuner',
      recipeId: parseInt(json['recipe_id'] ?? recipe['id']),
      foodId: parseInt(json['food_id']),
      title: parseString(json['title']) ?? parseString(recipe['title']) ?? 'Repas',
      servings: parseNumOr(json['servings'], 1),
      notes: parseString(json['notes']),
      status: parseString(json['status']) ?? 'prevu',
      mealId: parseInt(json['meal_id']),
      calories: parseNum(json['calories']) ?? parseNum(ApiClient.asMap(recipe['per_serving'])['calories']),
      recipeImageUrl: parseString(recipe['image_url']),
    );
  }

  bool get isDone => status == 'realise';

  bool get isCancelled => status == 'annule';
}

/// One day of the planner: `{date, slots:{petit_dejeuner:[…], …}}`.
class PlannerDay {
  final DateTime date;
  final Map<String, List<MealPlan>> slots;
  final double? totalCalories;

  const PlannerDay({required this.date, this.slots = const {}, this.totalCalories});

  factory PlannerDay.fromJson(Map<String, dynamic> json, {double? totalCalories}) {
    final slotsJson = ApiClient.asMap(json['slots']);
    final slots = <String, List<MealPlan>>{};
    for (final type in AppStrings.mealTypeOrder) {
      slots[type] = ApiClient.asList(slotsJson[type]).map(MealPlan.fromJson).toList();
    }
    return PlannerDay(
      date: parseDate(json['date']) ?? DateTime.now(),
      slots: slots,
      totalCalories: totalCalories,
    );
  }

  List<MealPlan> plansFor(String type) => slots[type] ?? const [];

  List<MealPlan> get allPlans => slots.values.expand((e) => e).toList();

  bool get isEmpty => allPlans.isEmpty;
}

/// `GET /planner?week_start=` → `data`.
class PlannerWeek {
  final DateTime weekStart;
  final List<PlannerDay> days;

  const PlannerWeek({required this.weekStart, this.days = const []});

  factory PlannerWeek.fromJson(Map<String, dynamic> json) {
    final totals = <String, double>{};
    for (final t in ApiClient.asList(json['totals_per_day'])) {
      final date = parseString(t['date']);
      if (date != null) totals[date] = parseNumOr(t['calories'], 0);
    }
    final days = ApiClient.asList(json['days']).map((d) {
      final key = parseString(d['date']);
      return PlannerDay.fromJson(d, totalCalories: key == null ? null : totals[key]);
    }).toList();
    return PlannerWeek(
      weekStart: parseDate(json['week_start']) ?? DateTime.now(),
      days: days,
    );
  }

  PlannerDay? day(DateTime date) {
    for (final d in days) {
      if (d.date.year == date.year && d.date.month == date.month && d.date.day == date.day) return d;
    }
    return null;
  }

  int get plansCount => days.fold(0, (sum, d) => sum + d.allPlans.length);
}

/// `POST /planner/generate` response.
class PlannerGenerateResult {
  final String? message;
  final PlannerWeek week;
  final int generatedCount;

  const PlannerGenerateResult({this.message, required this.week, this.generatedCount = 0});

  factory PlannerGenerateResult.fromJson(Map<String, dynamic> json) => PlannerGenerateResult(
        message: parseString(json['message']),
        week: PlannerWeek.fromJson(ApiClient.asMap(json['data'])),
        generatedCount: parseIntOr(json['generated_count'], 0),
      );
}
