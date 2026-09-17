import '../core/api_client.dart';
import '../core/formatters.dart';
import '../models/stock.dart';

/// Stock (§6).
class StockService {
  StockService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// `GET /stocks?include_depleted=1`.
  Future<StockPayload> list({bool includeDepleted = false}) async {
    final json = await _api.getJson('/stocks', query: {if (includeDepleted) 'include_depleted': 1});
    return StockPayload.fromJson(json);
  }

  /// `GET /stocks?include_depleted=1` — raw payload (cacheable in `Session`).
  Future<Map<String, dynamic>> raw({bool includeDepleted = false}) =>
      _api.getJson('/stocks', query: {if (includeDepleted) 'include_depleted': 1});

  /// `GET /stocks/alerts`.
  Future<StockAlertLists> alerts() async {
    final json = await _api.getJson('/stocks/alerts');
    return StockAlertLists.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /stocks {name}`.
  Future<StockLocation> createLocation(String name) async {
    final json = await _api.postJson('/stocks', body: {'name': name.trim()});
    return StockLocation.fromJson(ApiClient.asMap(json['data']));
  }

  /// `PUT /stocks/{stock} {name}`.
  Future<StockLocation> renameLocation(int id, String name) async {
    final json = await _api.putJson('/stocks/$id', body: {'name': name.trim()});
    return StockLocation.fromJson(ApiClient.asMap(json['data']));
  }

  /// `DELETE /stocks/{stock}` (must be empty).
  Future<void> deleteLocation(int id) => _api.deleteJson('/stocks/$id');

  /// `POST /stocks/items`.
  Future<StockItem> createItem(Map<String, dynamic> body) async {
    final json = await _api.postJson('/stocks/items', body: body);
    return StockItem.fromJson(ApiClient.asMap(json['data']));
  }

  /// `PUT /stocks/items/{item}`.
  Future<StockItem> updateItem(int id, Map<String, dynamic> body) async {
    final json = await _api.putJson('/stocks/items/$id', body: body);
    return StockItem.fromJson(ApiClient.asMap(json['data']));
  }

  /// `DELETE /stocks/items/{item}`.
  Future<void> deleteItem(int id) => _api.deleteJson('/stocks/items/$id');

  /// `POST /stocks/items/{item}/consume`.
  Future<ConsumeResult> consume(
    int id, {
    required double quantity,
    required String unit,
    String? mealType,
    DateTime? date,
    bool addToMeal = true,
  }) async {
    final json = await _api.postJson('/stocks/items/$id/consume', body: {
      'quantity': quantity,
      'unit': unit,
      'meal_type': ?mealType,
      if (date != null) 'date': isoDate(date),
      'add_to_meal': addToMeal,
    });
    return ConsumeResult.fromJson(ApiClient.asMap(json['data']));
  }
}
