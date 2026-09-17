import '../core/api_client.dart';
import '../core/formatters.dart';
import '../models/weight.dart';

/// Weight logs (§2.5).
class WeightService {
  WeightService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// `GET /weights?from&to`.
  Future<List<WeightLog>> list({DateTime? from, DateTime? to}) async {
    final json = await _api.getJson('/weights', query: {
      if (from != null) 'from': isoDate(from),
      if (to != null) 'to': isoDate(to),
    });
    return ApiClient.asList(json['data']).map(WeightLog.fromJson).toList();
  }

  /// `POST /weights {date?, weight_kg}` (upsert).
  Future<WeightLog> log(double weightKg, {DateTime? date}) async {
    final json = await _api.postJson('/weights', body: {
      if (date != null) 'date': isoDate(date),
      'weight_kg': weightKg,
    });
    return WeightLog.fromJson(ApiClient.asMap(json['data']));
  }

  /// `DELETE /weights/{date}`.
  Future<void> delete(DateTime date) => _api.deleteJson('/weights/${isoDate(date)}');
}
