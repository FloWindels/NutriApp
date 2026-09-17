import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// Eyebrow + title + subtitle, with an optional trailing action.
class SectionHeader extends StatelessWidget {
  final String? eyebrow;
  final String title;
  final String? subtitle;
  final Widget? action;
  final EdgeInsetsGeometry padding;

  const SectionHeader({
    super.key,
    this.eyebrow,
    required this.title,
    this.subtitle,
    this.action,
    this.padding = const EdgeInsets.fromLTRB(2, 4, 2, 10),
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: padding,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (eyebrow != null) ...[
                  Text(
                    eyebrow!.toUpperCase(),
                    style: const TextStyle(fontSize: 11, letterSpacing: 1.2, fontWeight: FontWeight.w700, color: MaviohColors.muted),
                  ),
                  const SizedBox(height: 4),
                ],
                Text(title, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: MaviohColors.text)),
                if (subtitle != null) ...[
                  const SizedBox(height: 3),
                  Text(subtitle!, style: const TextStyle(fontSize: 13, color: MaviohColors.muted, height: 1.35)),
                ],
              ],
            ),
          ),
          ?action,
        ],
      ),
    );
  }
}
