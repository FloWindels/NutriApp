import '../core/api_client.dart';
import '../models/settings.dart';

/// `GET/PUT /settings` (§14).
class SettingsService {
  SettingsService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  Future<UserSettings> get() async {
    final json = await _api.getJson('/settings');
    return UserSettings.fromJson(ApiClient.asMap(json['data']));
  }

  /// Partial update (`sometimes`).
  Future<UserSettings> update(Map<String, dynamic> patch) async {
    final json = await _api.putJson('/settings', body: patch);
    return UserSettings.fromJson(ApiClient.asMap(json['data']));
  }
}
