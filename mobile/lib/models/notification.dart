import '../core/api_client.dart';
import 'recommendation.dart';

/// `GET /notifications` entry `{key, type, title, message, date, read, action?}`.
class AppNotification {
  final String key;
  final String type;
  final String title;
  final String message;
  final DateTime? date;
  final bool read;
  final RecoAction? action;

  const AppNotification({
    required this.key,
    required this.type,
    required this.title,
    this.message = '',
    this.date,
    this.read = false,
    this.action,
  });

  factory AppNotification.fromJson(Map<String, dynamic> json) => AppNotification(
        key: parseString(json['key']) ?? '',
        type: parseString(json['type']) ?? '',
        title: parseString(json['title']) ?? '',
        message: parseString(json['message']) ?? '',
        date: parseDate(json['date']),
        read: parseBool(json['read']),
        action: json['action'] is Map ? RecoAction.fromJson(ApiClient.asMap(json['action'])) : null,
      );

  AppNotification copyWith({bool? read}) => AppNotification(
        key: key,
        type: type,
        title: title,
        message: message,
        date: date,
        read: read ?? this.read,
        action: action,
      );
}

/// `{data:[…], unread_count}`.
class NotificationsPayload {
  final List<AppNotification> items;
  final int unreadCount;

  const NotificationsPayload({this.items = const [], this.unreadCount = 0});

  factory NotificationsPayload.fromJson(Map<String, dynamic> json) {
    final items = ApiClient.asList(json['data']).map(AppNotification.fromJson).toList();
    return NotificationsPayload(
      items: items,
      unreadCount: parseIntOr(json['unread_count'], items.where((n) => !n.read).length),
    );
  }
}
