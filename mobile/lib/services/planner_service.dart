import '../core/api_client.dart';
import '../core/formatters.dart';
import '../models/meal.dart';
import '../models/planner.dart';

/// Weekly planner (§12).
class PlannerService {
  PlannerService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// `GET /planner?week_start=`.
  Future<PlannerWeek> week({DateTime? weekStart}) async {
    return PlannerWeek.fromJson(ApiClient.asMap((await weekRaw(weekStart: weekStart))['data']));
  }

  /// `GET /planner?week_start=` — raw envelope (for `Session.cache`).
  Future<Map<String, dynamic>> weekRaw({DateTime? weekStart}) {
    return _api.getJson('/planner', query: {if (weekStart != null) 'week_start': isoDate(weekStart)});
  }

  /// `POST /planner {date, meal_type, recipe_id?|food_id?|title, servings?, notes?}`.
  Future<MealPlan> create({
    required DateTime date,
    required String mealType,
    int? recipeId,
    int? foodId,
    String? title,
    double? servings,
    String? notes,
  }) async {
    final json = await _api.postJson('/planner', body: {
      'date': isoDate(date),
      'meal_type': mealType,
      'recipe_id': ?recipeId,
      'food_id': ?foodId,
      if (title != null && title.trim().isNotEmpty) 'title': title.trim(),
      'servings': ?servings,
      if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
    });
    return MealPlan.fromJson(ApiClient.asMap(json['data']));
  }

  /// `PUT /planner/{id}`.
  Future<MealPlan> update(int id, Map<String, dynamic> body) async {
    final json = await _api.putJson('/planner/$id', body: body);
    return MealPlan.fromJson(ApiClient.asMap(json['data']));
  }

  /// `DELETE /planner/{id}`.
  Future<void> delete(int id) => _api.deleteJson('/planner/$id');

  /// `POST /planner/{id}/log {decrement_stock?}` → updated day.
  Future<DaySummary?> log(int id, {bool decrementStock = false}) async {
    final json = await _api.postJson('/planner/$id/log', body: {'decrement_stock': decrementStock});
    return json['day'] is Map ? DaySummary.fromJson(ApiClient.asMap(json['day'])) : null;
  }

  /// `POST /planner/generate {week_start, meal_types, replace}`.
  Future<PlannerGenerateResult> generate({
    required DateTime weekStart,
    List<String> mealTypes = const ['dejeuner', 'diner'],
    bool replace = false,
  }) async {
    final json = await _api.postJson('/planner/generate', body: {
      'week_start': isoDate(weekStart),
      'meal_types': mealTypes,
      'replace': replace,
    });
    return PlannerGenerateResult.fromJson(json);
  }
}
