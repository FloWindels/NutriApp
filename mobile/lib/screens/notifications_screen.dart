import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/notification.dart';
import '../services/notification_service.dart';
import '../theme/app_theme.dart';
import '../widgets/app_card.dart';
import '../widgets/empty_state.dart';
import '../widgets/error_state.dart';
import '../widgets/loading_state.dart';
import '../widgets/notification_bell.dart';
import '../widgets/status_banner.dart';

/// Notifications (§16.4) — écran poussé : liste groupée par jour, état lu/non lu,
/// tap → marque comme lue puis navigue selon l’action, « Tout marquer comme lu ».
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, this.onNavigate});

  /// Navigation par action (slug) après fermeture de l’écran.
  final ValueChanged<String>? onNavigate;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  final _service = NotificationService();

  List<AppNotification> _items = const [];
  bool _loaded = false;
  bool _loading = true;
  String? _error;
  String? _actionError;
  bool _markingAll = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = !_loaded;
        _error = null;
      });
    }
    try {
      final payload = await _service.list();
      if (!mounted) return;
      setState(() {
        _items = payload.items;
        _loaded = true;
        _loading = false;
        _error = null;
      });
      NotificationBell.unread.value = payload.unreadCount;
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (!_loaded) {
          _error = e.message;
        } else {
          _actionError = e.message;
        }
      });
    }
  }

  int get _unreadCount => _items.where((item) => !item.read).length;

  void _syncBell() => NotificationBell.unread.value = _unreadCount;

  /// Marque la notification comme lue (optimiste, rétabli en cas d’échec).
  Future<void> _markRead(AppNotification item) async {
    if (item.read) return;
    final previous = _items;
    setState(() {
      _items = [
        for (final n in _items) n.key == item.key ? n.copyWith(read: true) : n,
      ];
      _actionError = null;
    });
    _syncBell();
    try {
      await _service.markRead(item.key);
      Session.instance.invalidate('notifications');
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _items = previous;
        _actionError = e.message;
      });
      _syncBell();
    }
  }

  Future<void> _markAllRead() async {
    if (_items.isEmpty || _unreadCount == 0) return;
    final previous = _items;
    setState(() {
      _items = [for (final n in _items) n.copyWith(read: true)];
      _markingAll = true;
      _actionError = null;
    });
    _syncBell();
    try {
      await _service.markAllRead();
      if (!mounted) return;
      Session.instance.invalidate('notifications');
      setState(() => _markingAll = false);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _items = previous;
        _markingAll = false;
        _actionError = e.message;
      });
      _syncBell();
    }
  }

  Future<void> _open(AppNotification item) async {
    await _markRead(item);
    if (!mounted) return;
    final slug = _slugFor(item);
    if (slug == null) return;
    final navigate = widget.onNavigate;
    if (navigate != null) {
      navigate(slug);
    } else {
      Navigator.of(context).pop();
    }
  }

  /// Slug de destination : l’action de la notification d’abord, son type ensuite.
  static String? _slugFor(AppNotification item) {
    switch (item.action?.kind) {
      case 'ajouter_au_repas':
        return 'historique-repas-journee';
      case 'ouvrir_recette':
        return 'recettes';
      case 'ouvrir_stock':
      case 'supprimer_stock':
        return 'stock';
      case 'generer_seance':
        return 'sport';
      case 'ajouter_courses':
        return 'liste-course';
      case 'ouvrir_planificateur':
        return 'planificateur-semaine';
    }
    switch (item.type) {
      case 'peremption':
      case 'perime':
        return 'stock';
      case 'rappel_repas':
        return 'historique-repas-journee';
      case 'rappel_sport':
        return 'sport';
      case 'recommandation':
        return 'recommandations-repas-journee';
    }
    return null;
  }

  static IconData _iconFor(AppNotification item) {
    switch (item.type) {
      case 'peremption':
      case 'perime':
        return Icons.kitchen_outlined;
      case 'rappel_repas':
        return Icons.restaurant_outlined;
      case 'rappel_sport':
        return Icons.fitness_center_rounded;
      case 'recommandation':
        return Icons.tips_and_updates_outlined;
      default:
        return Icons.notifications_none_rounded;
    }
  }

  static Color _toneFor(AppNotification item) {
    switch (item.type) {
      case 'peremption':
        return MaviohColors.amber;
      case 'perime':
        return MaviohColors.rose;
      case 'rappel_repas':
        return MaviohColors.indigo;
      case 'rappel_sport':
        return MaviohColors.lime;
      default:
        return MaviohColors.primary;
    }
  }

  /// Groupe les notifications par jour (Aujourd’hui, Hier, puis la date).
  List<MapEntry<String, List<AppNotification>>> _grouped() {
    final groups = <String, List<AppNotification>>{};
    for (final item in _items) {
      final label = item.date == null ? 'Sans date' : capitalize(fmtRelativeDay(item.date!));
      groups.putIfAbsent(label, () => <AppNotification>[]).add(item);
    }
    return groups.entries.toList();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text(AppStrings.sectionNotifications),
        actions: [
          IconButton(
            tooltip: 'Tout marquer comme lu',
            onPressed: _markingAll || _unreadCount == 0 ? null : _markAllRead,
            icon: const Icon(Icons.done_all_rounded),
          ),
        ],
      ),
      body: _body(),
    );
  }

  Widget _body() {
    if (_loading && !_loaded) return const LoadingState(skeleton: true, skeletonCount: 4);
    if (_error != null && !_loaded) {
      return ErrorState(message: _error!, onRetry: _load);
    }
    if (_items.isEmpty) {
      return RefreshIndicator(
        onRefresh: () => _load(silent: true),
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            SizedBox(height: MediaQuery.sizeOf(context).height * 0.12),
            EmptyState(
              icon: Icons.notifications_none_rounded,
              title: 'Aucune notification',
              message: 'Tes rappels de péremption, de repas et de séances apparaîtront ici.',
              ctaLabel: 'Actualiser',
              onCta: _load,
            ),
          ],
        ),
      );
    }

    final groups = _grouped();
    return RefreshIndicator(
      onRefresh: () => _load(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
        children: [
          if (_actionError != null)
            StatusBanner.error(
              _actionError!,
              margin: const EdgeInsets.only(bottom: 12),
              onClose: () => setState(() => _actionError = null),
            ),
          if (_unreadCount > 0)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      '$_unreadCount non lue${_unreadCount > 1 ? 's' : ''}',
                      style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textTertiary),
                    ),
                  ),
                  TextButton.icon(
                    onPressed: _markingAll ? null : _markAllRead,
                    icon: const Icon(Icons.done_all_rounded, size: 18),
                    label: const Text('Tout marquer comme lu'),
                  ),
                ],
              ),
            ),
          for (final group in groups) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(2, 6, 2, 8),
              child: Text(
                group.key,
                style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w800, color: MaviohColors.muted),
              ),
            ),
            for (final item in group.value)
              _NotificationTile(
                notification: item,
                icon: _iconFor(item),
                tone: _toneFor(item),
                hasAction: _slugFor(item) != null,
                onTap: () => _open(item),
              ),
          ],
        ],
      ),
    );
  }
}

class _NotificationTile extends StatelessWidget {
  const _NotificationTile({
    required this.notification,
    required this.icon,
    required this.tone,
    required this.hasAction,
    required this.onTap,
  });

  final AppNotification notification;
  final IconData icon;
  final Color tone;
  final bool hasAction;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final unread = !notification.read;
    return AppCard(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
      borderColor: unread ? MaviohColors.tint(tone, 0.45) : null,
      onTap: onTap,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              color: MaviohColors.tint(tone, 0.12),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, size: 20, color: tone),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  notification.title,
                  style: TextStyle(
                    fontSize: 14.5,
                    fontWeight: unread ? FontWeight.w800 : FontWeight.w600,
                    color: unread ? MaviohColors.text : MaviohColors.textTertiary,
                  ),
                ),
                if (notification.message.isNotEmpty) ...[
                  const SizedBox(height: 3),
                  Text(
                    notification.message,
                    style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.35),
                  ),
                ],
                if (notification.date != null) ...[
                  const SizedBox(height: 4),
                  Text(
                    fmtDay(notification.date!),
                    style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(width: 8),
          Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (unread)
                Container(
                  width: 10,
                  height: 10,
                  margin: const EdgeInsets.only(top: 6),
                  decoration: const BoxDecoration(color: MaviohColors.rose, shape: BoxShape.circle),
                )
              else
                const Icon(Icons.check_rounded, size: 16, color: MaviohColors.muted),
              if (hasAction) ...[
                const SizedBox(height: 8),
                const Icon(Icons.chevron_right_rounded, size: 20, color: MaviohColors.muted),
              ],
            ],
          ),
        ],
      ),
    );
  }
}
