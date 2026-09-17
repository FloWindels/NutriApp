import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

enum BannerKind { error, success, warning, info }

/// Inline feedback box (error | success | warning | info).
class StatusBanner extends StatelessWidget {
  final BannerKind kind;
  final String message;
  final String? title;
  final VoidCallback? onClose;
  final Widget? action;
  final EdgeInsetsGeometry margin;

  const StatusBanner({
    super.key,
    required this.kind,
    required this.message,
    this.title,
    this.onClose,
    this.action,
    this.margin = EdgeInsets.zero,
  });

  const StatusBanner.error(this.message, {super.key, this.title, this.onClose, this.action, this.margin = EdgeInsets.zero})
      : kind = BannerKind.error;

  const StatusBanner.success(this.message, {super.key, this.title, this.onClose, this.action, this.margin = EdgeInsets.zero})
      : kind = BannerKind.success;

  const StatusBanner.warning(this.message, {super.key, this.title, this.onClose, this.action, this.margin = EdgeInsets.zero})
      : kind = BannerKind.warning;

  const StatusBanner.info(this.message, {super.key, this.title, this.onClose, this.action, this.margin = EdgeInsets.zero})
      : kind = BannerKind.info;

  Color get _fg {
    switch (kind) {
      case BannerKind.error:
        return MaviohColors.error;
      case BannerKind.success:
        return MaviohColors.success;
      case BannerKind.warning:
        return MaviohColors.warning;
      case BannerKind.info:
        return MaviohColors.info;
    }
  }

  Color get _bg {
    switch (kind) {
      case BannerKind.error:
        return MaviohColors.errorBg;
      case BannerKind.success:
        return MaviohColors.successBg;
      case BannerKind.warning:
        return MaviohColors.warningBg;
      case BannerKind.info:
        return MaviohColors.infoBg;
    }
  }

  IconData get _icon {
    switch (kind) {
      case BannerKind.error:
        return Icons.error_outline_rounded;
      case BannerKind.success:
        return Icons.check_circle_outline_rounded;
      case BannerKind.warning:
        return Icons.warning_amber_rounded;
      case BannerKind.info:
        return Icons.info_outline_rounded;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: margin,
      padding: const EdgeInsets.fromLTRB(14, 12, 10, 12),
      decoration: BoxDecoration(
        color: _bg,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: _fg.withValues(alpha: 0.25)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(_icon, color: _fg, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (title != null) ...[
                  Text(title!, style: TextStyle(color: _fg, fontWeight: FontWeight.w800, fontSize: 14)),
                  const SizedBox(height: 2),
                ],
                Text(message, style: TextStyle(color: _fg, fontWeight: FontWeight.w600, height: 1.4, fontSize: 13.5)),
                if (action != null) ...[
                  const SizedBox(height: 8),
                  action!,
                ],
              ],
            ),
          ),
          if (onClose != null)
            IconButton(
              tooltip: 'Fermer',
              onPressed: onClose,
              icon: Icon(Icons.close_rounded, color: _fg, size: 18),
              constraints: const BoxConstraints(minWidth: 40, minHeight: 40),
              padding: EdgeInsets.zero,
            ),
        ],
      ),
    );
  }
}
