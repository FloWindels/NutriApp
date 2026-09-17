import '../core/api_client.dart';
import '../core/formatters.dart';
import '../models/dashboard.dart';

/// `GET /dashboard?date=` (§7).
class DashboardService {
  DashboardService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// Typed payload.
  Future<Dashboard> get({DateTime? date}) async => Dashboard.fromJson(await raw(date: date));

  /// Raw `data` map (cached by the screen in `Session.cache['dashboard']`).
  Future<Map<String, dynamic>> raw({DateTime? date}) async {
    final json = await _api.getJson('/dashboard', query: {if (date != null) 'date': isoDate(date)});
    return ApiClient.asMap(json['data']);
  }
}
