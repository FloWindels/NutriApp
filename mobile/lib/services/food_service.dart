import 'package:dio/dio.dart';

import '../core/api_client.dart';
import '../models/food.dart';
import '../models/portion.dart';

/// Result of `POST /foods` (may be an existing food, HTTP 200).
class FoodCreateResult {
  final Food food;
  final bool alreadyExisted;
  final String? message;

  const FoodCreateResult({required this.food, this.alreadyExisted = false, this.message});
}

/// Foods, Open Food Facts (via the API) and portions (§3).
class FoodService {
  FoodService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// `GET /foods/search?q=&barcode=&page=&per_page=&off=1`.
  Future<Paged<Food>> search(
    String query, {
    bool off = true,
    int page = 1,
    int perPage = 20,
    CancelToken? cancelToken,
  }) async {
    final trimmed = query.trim();
    final isBarcode = RegExp(r'^\d{8,14}$').hasMatch(trimmed);
    final json = await _api.getJson(
      '/foods/search',
      query: {
        if (!isBarcode) 'q': trimmed,
        if (isBarcode) 'barcode': trimmed,
        'page': page,
        'per_page': perPage,
        if (off) 'off': 1,
      },
      cancelToken: cancelToken,
    );
    return Paged(
      items: ApiClient.asList(json['data']).map(Food.fromJson).toList(),
      meta: PageMeta.fromJson(ApiClient.asMap(json['meta'])),
    );
  }

  /// `GET /foods/barcode/{ean}` → food or throws `ApiException(notFound)`.
  Future<Food> byBarcode(String ean) async {
    final json = await _api.getJson('/foods/barcode/${Uri.encodeComponent(ean.trim())}');
    return Food.fromJson(ApiClient.asMap(json['data']));
  }

  /// Same as [byBarcode] but returns null on 404.
  Future<Food?> tryByBarcode(String ean) async {
    try {
      return await byBarcode(ean);
    } on ApiException catch (e) {
      if (e.kind == ApiErrorKind.notFound) return null;
      rethrow;
    }
  }

  /// `GET /foods/{food}`.
  Future<Food> get(int id) async {
    final json = await _api.getJson('/foods/$id');
    return Food.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /foods` (firstOrCreate semantics on barcode).
  Future<FoodCreateResult> create(Map<String, dynamic> body) async {
    final json = await _api.postJson('/foods', body: body);
    final message = parseString(json['message']);
    return FoodCreateResult(
      food: Food.fromJson(ApiClient.asMap(json['data'])),
      alreadyExisted: message != null && message.contains('déjà présent'),
      message: message,
    );
  }

  /// `PUT /foods/{food}`.
  Future<Food> update(int id, Map<String, dynamic> body) async {
    final json = await _api.putJson('/foods/$id', body: body);
    return Food.fromJson(ApiClient.asMap(json['data']));
  }

  /// `GET /foods/favorites`.
  Future<List<Food>> favorites() async {
    final json = await _api.getJson('/foods/favorites');
    return ApiClient.asList(json['data']).map(Food.fromJson).toList();
  }

  /// `POST /foods/{food}/favorite`.
  Future<void> addFavorite(int id) => _api.postJson('/foods/$id/favorite');

  /// `DELETE /foods/{food}/favorite`.
  Future<void> removeFavorite(int id) => _api.deleteJson('/foods/$id/favorite');

  /// `GET /portions` (public).
  Future<List<Portion>> portions() async {
    final json = await _api.getJson('/portions');
    final list = ApiClient.asList(json['data']).map(Portion.fromJson).toList();
    return list.isEmpty ? Portion.defaults : list;
  }
}
