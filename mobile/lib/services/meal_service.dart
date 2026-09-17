import '../core/api_client.dart';
import '../core/formatters.dart';
import '../models/meal.dart';

/// Meals & daily tracking (§4).
class MealService {
  MealService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// `GET /meals?date=`.
  Future<DaySummary> day(DateTime date) async => DaySummary.fromJson(await dayRaw(date));

  /// Raw `data` map of `GET /meals?date=` (cached by the screen in `Session.cache['meals:<date>']`).
  Future<Map<String, dynamic>> dayRaw(DateTime date) async {
    final json = await _api.getJson('/meals', query: {'date': isoDate(date)});
    return ApiClient.asMap(json['data']);
  }

  /// `POST /meals {date, type, name?, items?}` — the single add flow uses this.
  Future<MealMutationResult> createOrAppend({
    required DateTime date,
    required String type,
    String? name,
    List<MealItemInput> items = const [],
  }) async {
    final json = await _api.postJson('/meals', body: {
      'date': isoDate(date),
      'type': type,
      if (name != null && name.trim().isNotEmpty) 'name': name.trim(),
      if (items.isNotEmpty) 'items': items.map((i) => i.toJson()).toList(),
    });
    return MealMutationResult.fromJson(json);
  }

  /// `POST /meals/{meal}/items`.
  Future<MealItemMutationResult> addItem(int mealId, MealItemInput item) async {
    final json = await _api.postJson('/meals/$mealId/items', body: item.toJson());
    return MealItemMutationResult.fromJson(json);
  }

  /// `PUT /meals/{meal}/items/{item} {quantity, unit}`.
  Future<MealItemMutationResult> updateItem(int mealId, int itemId, {required double quantity, required String unit}) async {
    final json = await _api.putJson('/meals/$mealId/items/$itemId', body: {'quantity': quantity, 'unit': unit});
    return MealItemMutationResult.fromJson(json);
  }

  /// `DELETE /meals/{meal}/items/{item}` → updated day (may be null).
  Future<DaySummary?> deleteItem(int mealId, int itemId) async {
    final json = await _api.deleteJson('/meals/$mealId/items/$itemId');
    return json['day'] is Map ? DaySummary.fromJson(ApiClient.asMap(json['day'])) : null;
  }

  /// `PUT /meals/{meal} {name?, notes?, consumed_at?}`.
  Future<Meal> updateMeal(int mealId, {String? name, String? notes, DateTime? consumedAt}) async {
    final json = await _api.putJson('/meals/$mealId', body: {
      'name': ?name,
      'notes': ?notes,
      if (consumedAt != null) 'consumed_at': consumedAt.toUtc().toIso8601String(),
    });
    return Meal.fromJson(ApiClient.asMap(json['data']));
  }

  /// `DELETE /meals/{meal}`.
  Future<void> deleteMeal(int mealId) => _api.deleteJson('/meals/$mealId');

  /// `POST /meals/copy {from_date, to_date, type?}`.
  Future<DaySummary?> copy({required DateTime from, required DateTime to, String? type}) async {
    final json = await _api.postJson('/meals/copy', body: {
      'from_date': isoDate(from),
      'to_date': isoDate(to),
      'type': ?type,
    });
    return json['day'] is Map ? DaySummary.fromJson(ApiClient.asMap(json['day'])) : null;
  }

  /// `GET /meals/history?from=&to=` (max 92 days).
  Future<List<MealHistoryRow>> history({required DateTime from, required DateTime to}) async {
    final json = await _api.getJson('/meals/history', query: {'from': isoDate(from), 'to': isoDate(to)});
    return ApiClient.asList(json['data']).map(MealHistoryRow.fromJson).toList();
  }

  /// `GET /meals/frequent`.
  Future<List<FrequentItem>> frequent() async {
    final json = await _api.getJson('/meals/frequent');
    return ApiClient.asList(json['data']).map(FrequentItem.fromJson).toList();
  }
}
