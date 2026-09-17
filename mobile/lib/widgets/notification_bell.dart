import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../services/notification_service.dart';
import '../theme/app_theme.dart';

/// AppBar bell with an unread badge.
///
/// The count lives in the static [unread] notifier so any screen can update it
/// (dashboard payload, notifications screen). [refresh] refetches `/notifications`.
class NotificationBell extends StatelessWidget {
  final VoidCallback onPressed;

  const NotificationBell({super.key, required this.onPressed});

  /// Shared unread counter.
  static final ValueNotifier<int> unread = ValueNotifier<int>(0);

  static DateTime? _lastFetch;

  /// Refetches the unread count (throttled to once every 30 s unless [force]).
  static Future<void> refresh({bool force = false}) async {
    final now = DateTime.now();
    if (!force && _lastFetch != null && now.difference(_lastFetch!) < const Duration(seconds: 30)) return;
    _lastFetch = now;
    try {
      final payload = await NotificationService().list();
      unread.value = payload.unreadCount;
    } on ApiException {
      // Keep the previous value.
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<int>(
      valueListenable: unread,
      builder: (context, count, _) {
        return IconButton(
          tooltip: count > 0 ? 'Notifications ($count non lues)' : 'Notifications',
          onPressed: onPressed,
          icon: Stack(
            clipBehavior: Clip.none,
            children: [
              const Icon(Icons.notifications_none_rounded),
              if (count > 0)
                Positioned(
                  right: -4,
                  top: -4,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                    constraints: const BoxConstraints(minWidth: 18, minHeight: 18),
                    decoration: BoxDecoration(
                      color: MaviohColors.rose,
                      borderRadius: BorderRadius.circular(999),
                      border: Border.all(color: MaviohColors.background, width: 1.5),
                    ),
                    child: Text(
                      count > 99 ? '99+' : '$count',
                      textAlign: TextAlign.center,
                      style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w800, height: 1.2),
                    ),
                  ),
                ),
            ],
          ),
        );
      },
    );
  }
}
