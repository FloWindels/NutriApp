import '../core/api_client.dart';
import '../models/notification.dart';

/// In-app notifications (§14).
class NotificationService {
  NotificationService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// `GET /notifications`.
  Future<NotificationsPayload> list() async {
    final json = await _api.getJson('/notifications');
    return NotificationsPayload.fromJson(json);
  }

  /// `PUT /notifications/{key}/read`.
  Future<void> markRead(String key) => _api.putJson('/notifications/${Uri.encodeComponent(key)}/read');

  /// `POST /notifications/read-all`.
  Future<void> markAllRead() => _api.postJson('/notifications/read-all');
}
