import '../core/api_client.dart';
import '../core/formatters.dart';
import '../models/recommendation.dart';

/// Coach recommendations (§8).
class RecommendationService {
  RecommendationService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// Raw `GET /recommendations?date=&all=1` payload (for `Session.cache`).
  Future<Map<String, dynamic>> raw({DateTime? date, bool all = false}) {
    return _api.getJson('/recommendations', query: {
      if (date != null) 'date': isoDate(date),
      if (all) 'all': 1,
    });
  }

  /// `GET /recommendations?date=&all=1`.
  Future<List<Recommendation>> list({DateTime? date, bool all = false}) async {
    final json = await _api.getJson('/recommendations', query: {
      if (date != null) 'date': isoDate(date),
      if (all) 'all': 1,
    });
    final list = ApiClient.asList(json['data']).map(Recommendation.fromJson).toList();
    list.sort((a, b) => a.priority.compareTo(b.priority));
    return list;
  }

  /// `PUT /recommendations/{id} {status}` (`acceptee` | `ignoree` | `new`).
  Future<Recommendation> setStatus(int id, String status) async {
    final json = await _api.putJson('/recommendations/$id', body: {'status': status});
    return Recommendation.fromJson(ApiClient.asMap(json['data']));
  }

  Future<Recommendation> accept(int id) => setStatus(id, 'acceptee');

  Future<Recommendation> ignore(int id) => setStatus(id, 'ignoree');
}
