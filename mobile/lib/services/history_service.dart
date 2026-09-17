import '../core/api_client.dart';
import '../core/formatters.dart';
import '../models/history.dart';

/// `GET /history?from=&to=` (§7).
class HistoryService {
  HistoryService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  Future<HistoryData> get({required DateTime from, required DateTime to}) async {
    final json = await _api.getJson('/history', query: {'from': isoDate(from), 'to': isoDate(to)});
    return HistoryData.fromJson(ApiClient.asMap(json['data']));
  }

  /// Last [days] days ending today.
  Future<HistoryData> lastDays(int days) {
    final end = today();
    return get(from: end.subtract(Duration(days: days - 1)), to: end);
  }
}
