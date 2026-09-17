import '../core/api_client.dart';
import '../core/formatters.dart';
import '../models/shopping.dart';
import '../models/stock.dart';

/// Shopping list (§11).
class ShoppingService {
  ShoppingService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// `GET /shopping-list`.
  Future<ShoppingList> list() async {
    return ShoppingList.fromJson(await listRaw());
  }

  /// `GET /shopping-list` — raw envelope (for `Session.cache`).
  Future<Map<String, dynamic>> listRaw() => _api.getJson('/shopping-list');

  /// `POST /shopping-list/items {label, quantity?, unit?, food_id?}`.
  Future<ShoppingItem> add({required String label, double? quantity, String? unit, int? foodId}) async {
    final json = await _api.postJson('/shopping-list/items', body: {
      'label': label.trim(),
      'quantity': ?quantity,
      if (unit != null && unit.isNotEmpty) 'unit': unit,
      'food_id': ?foodId,
    });
    return ShoppingItem.fromJson(ApiClient.asMap(json['data']));
  }

  /// `PUT /shopping-list/items/{id} {checked?, quantity?, unit?, label?}`.
  Future<ShoppingItem> update(int id, {bool? checked, double? quantity, String? unit, String? label}) async {
    final json = await _api.putJson('/shopping-list/items/$id', body: {
      'checked': ?checked,
      'quantity': ?quantity,
      'unit': ?unit,
      if (label != null) 'label': label.trim(),
    });
    return ShoppingItem.fromJson(ApiClient.asMap(json['data']));
  }

  /// `DELETE /shopping-list/items/{id}`.
  Future<void> delete(int id) => _api.deleteJson('/shopping-list/items/$id');

  /// `DELETE /shopping-list/checked`.
  Future<void> clearChecked() => _api.deleteJson('/shopping-list/checked');

  /// `POST /shopping-list/generate {week_start?}`.
  Future<ShoppingGenerateResult> generate({DateTime? weekStart}) async {
    final json = await _api.postJson('/shopping-list/generate', body: {
      if (weekStart != null) 'week_start': isoDate(weekStart),
    });
    return ShoppingGenerateResult.fromJson(json);
  }

  /// `POST /shopping-list/items/{id}/to-stock {stock_id?, expires_at?, quantity?, unit?}`.
  Future<StockItem> toStock(int id, {int? stockId, DateTime? expiresAt, double? quantity, String? unit}) async {
    final json = await _api.postJson('/shopping-list/items/$id/to-stock', body: {
      'stock_id': ?stockId,
      if (expiresAt != null) 'expires_at': isoDate(expiresAt),
      'quantity': ?quantity,
      'unit': ?unit,
    });
    return StockItem.fromJson(ApiClient.asMap(json['data']));
  }
}
