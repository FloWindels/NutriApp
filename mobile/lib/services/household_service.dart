import '../core/api_client.dart';
import '../core/formatters.dart';
import '../models/household.dart';

/// Household / Famille (§10).
class HouseholdService {
  HouseholdService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// `GET /household` → null when the user has no household.
  Future<Household?> get() async {
    final json = await raw();
    final data = json['data'];
    if (data is! Map) return null;
    return Household.fromJson(ApiClient.asMap(data));
  }

  /// `GET /household` — raw envelope (for `Session.cache`; `data` may be null).
  Future<Map<String, dynamic>> raw() => _api.getJson('/household');

  /// Parses a cached `GET /household` envelope (null when no household).
  static Household? parse(Map<String, dynamic> json) {
    final data = json['data'];
    if (data is! Map) return null;
    return Household.fromJson(ApiClient.asMap(data));
  }

  /// `GET /household/preview?invite_code=`.
  Future<HouseholdPreview> preview(String inviteCode) async {
    final json = await _api.getJson('/household/preview', query: {'invite_code': inviteCode.trim().toUpperCase()});
    return HouseholdPreview.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /household {name}`.
  Future<Household> create(String name) async {
    final json = await _api.postJson('/household', body: {'name': name.trim()});
    return Household.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /household/join {invite_code}`.
  Future<HouseholdJoinResult> join(String inviteCode) async {
    final json = await _api.postJson('/household/join', body: {'invite_code': inviteCode.trim().toUpperCase()});
    return HouseholdJoinResult.fromJson(json);
  }

  /// `PUT /household {name}` (owner).
  Future<Household> rename(String name) async {
    final json = await _api.putJson('/household', body: {'name': name.trim()});
    return Household.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /household/regenerate-code` (owner).
  Future<Household> regenerateCode() async {
    final json = await _api.postJson('/household/regenerate-code');
    return Household.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /household/leave`.
  Future<String> leave() async {
    final json = await _api.postJson('/household/leave');
    return parseString(json['message']) ?? 'Tu as quitté le foyer.';
  }

  /// `DELETE /household/members/{user}` (owner).
  Future<void> removeMember(int userId) => _api.deleteJson('/household/members/$userId');

  /// `DELETE /household` (owner).
  Future<String> dissolve() async {
    final json = await _api.deleteJson('/household');
    return parseString(json['message']) ?? 'Foyer supprimé.';
  }

  /// `PUT /household/members/me {share_profile}`.
  Future<void> setShareProfile(bool share) => _api.putJson('/household/members/me', body: {'share_profile': share});

  /// `POST /household/common-meal/preview {recipe_id, meal_type, date?}`.
  Future<CommonMealPreview> commonMealPreview({required int recipeId, required String mealType, DateTime? date}) async {
    final json = await _api.postJson('/household/common-meal/preview', body: {
      'recipe_id': recipeId,
      'meal_type': mealType,
      if (date != null) 'date': isoDate(date),
    });
    return CommonMealPreview.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /household/common-meal {recipe_id, meal_type, date, portions:{user_id: factor}}`.
  Future<List<CommonMealCreated>> commonMeal({
    required int recipeId,
    required String mealType,
    required DateTime date,
    required Map<int, double> portions,
  }) async {
    final json = await _api.postJson('/household/common-meal', body: {
      'recipe_id': recipeId,
      'meal_type': mealType,
      'date': isoDate(date),
      'portions': {for (final e in portions.entries) e.key.toString(): e.value},
    });
    return ApiClient.asList(ApiClient.asMap(json['data'])['created']).map(CommonMealCreated.fromJson).toList();
  }
}
