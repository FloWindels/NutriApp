import '../core/api_client.dart';
import '../models/diet.dart';

/// Diets catalog + evaluation (§9).
class DietService {
  DietService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// `GET /diets` (public catalog).
  Future<List<Diet>> list() async {
    return parseList(await listRaw());
  }

  /// `GET /diets` — raw envelope (cacheable in `Session`).
  ///
  /// Additive helper used by `DietScreen`, which stores the untouched map in
  /// `Session.cache` and rebuilds the catalogue from it.
  Future<Map<String, dynamic>> listRaw() => _api.getJson('/diets');

  /// Rebuilds the catalogue from a raw `GET /diets` envelope.
  static List<Diet> parseList(Map<String, dynamic> json) =>
      ApiClient.asList(json['data']).map(Diet.fromJson).toList();

  /// `GET /diets/{key}` (full config).
  Future<Diet> get(String key) async {
    return parseDiet(await getRaw(key), key);
  }

  /// `GET /diets/{key}` — raw envelope (cacheable in `Session`).
  Future<Map<String, dynamic>> getRaw(String key) => _api.getJson('/diets/$key');

  /// Rebuilds one diet from a raw `GET /diets/{key}` envelope.
  static Diet parseDiet(Map<String, dynamic> json, String key) {
    final data = ApiClient.asMap(json['data']);
    if (!data.containsKey('key')) data['key'] = key;
    return Diet.fromJson(data);
  }

  /// `GET /diets/evaluate?days=7`.
  Future<DietEvaluation> evaluate({int days = 7}) async {
    return parseEvaluation(await evaluateRaw(days: days));
  }

  /// `GET /diets/evaluate?days=` — raw envelope (cacheable in `Session`).
  Future<Map<String, dynamic>> evaluateRaw({int days = 7}) =>
      _api.getJson('/diets/evaluate', query: {'days': days});

  /// Rebuilds the evaluation from a raw `GET /diets/evaluate` envelope.
  static DietEvaluation parseEvaluation(Map<String, dynamic> json) =>
      DietEvaluation.fromJson(ApiClient.asMap(json['data']));
}
