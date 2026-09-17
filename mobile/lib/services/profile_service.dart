import '../core/api_client.dart';
import '../models/profile.dart';

/// Profile endpoints (§2.2).
class ProfileService {
  ProfileService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// `GET /profile` (top-level payload).
  Future<Profile> get() async {
    final json = await _api.getJson('/profile');
    return Profile.fromJson(json);
  }

  /// `GET /profile` — raw top-level payload (cacheable in `Session`).
  ///
  /// Additive helper used by `ProfileScreen`, which stores the untouched map in
  /// `Session.cache` and rebuilds a [Profile] from it.
  Future<Map<String, dynamic>> raw() => _api.getJson('/profile');

  /// `PUT /profile` → `{message}` + GET payload.
  Future<Profile> update(Map<String, dynamic> body) async {
    final json = await _api.putJson('/profile', body: body);
    return Profile.fromJson(json);
  }

  /// `PUT /profile` — raw response map (`message` + the GET payload).
  ///
  /// Additive helper used by `ProfileScreen` to reuse the response as the new
  /// cache entry and to read `message` without a second round-trip.
  Future<Map<String, dynamic>> updateRaw(Map<String, dynamic> body) => _api.putJson('/profile', body: body);

  /// `POST /profile/preview` → live computation (no persistence).
  Future<ProfilePreview> preview(Map<String, dynamic> body) async {
    final json = await _api.postJson('/profile/preview', body: body);
    return ProfilePreview.fromJson(ApiClient.asMap(json['data']));
  }
}
