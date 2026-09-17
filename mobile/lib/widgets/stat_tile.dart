import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// Compact stat block: label, big value (FittedBox), optional caption/icon.
class StatTile extends StatelessWidget {
  final String label;
  final String value;
  final String? caption;
  final IconData? icon;
  final Color tone;
  final VoidCallback? onTap;

  const StatTile({
    super.key,
    required this.label,
    required this.value,
    this.caption,
    this.icon,
    this.tone = MaviohColors.primary,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final body = Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: MaviohColors.surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: MaviohColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              if (icon != null) ...[
                Icon(icon, size: 16, color: tone),
                const SizedBox(width: 6),
              ],
              Expanded(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, letterSpacing: 0.6, color: MaviohColors.muted),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              value,
              style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: MaviohColors.text),
            ),
          ),
          if (caption != null) ...[
            const SizedBox(height: 4),
            Text(
              caption!,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted, height: 1.3),
            ),
          ],
        ],
      ),
    );
    if (onTap == null) return body;
    return Material(
      color: Colors.transparent,
      child: InkWell(borderRadius: BorderRadius.circular(16), onTap: onTap, child: body),
    );
  }
}
