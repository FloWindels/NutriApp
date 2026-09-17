import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// Centered empty placeholder with icon, title, message and optional CTA.
class EmptyState extends StatelessWidget {
  final IconData icon;
  final String title;
  final String? message;
  final String? ctaLabel;
  final VoidCallback? onCta;
  final Color tone;
  final bool compact;

  const EmptyState({
    super.key,
    this.icon = Icons.inbox_outlined,
    required this.title,
    this.message,
    this.ctaLabel,
    this.onCta,
    this.tone = MaviohColors.primary,
    this.compact = false,
  });

  @override
  Widget build(BuildContext context) {
    final content = Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: compact ? 48 : 64,
          height: compact ? 48 : 64,
          decoration: BoxDecoration(color: tone.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(compact ? 16 : 20)),
          child: Icon(icon, color: tone, size: compact ? 24 : 30),
        ),
        SizedBox(height: compact ? 10 : 16),
        Text(
          title,
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: compact ? 15 : 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
        ),
        if (message != null) ...[
          const SizedBox(height: 6),
          Text(
            message!,
            textAlign: TextAlign.center,
            style: const TextStyle(color: MaviohColors.muted, height: 1.45),
          ),
        ],
        if (ctaLabel != null && onCta != null) ...[
          const SizedBox(height: 16),
          FilledButton(onPressed: onCta, child: Text(ctaLabel!)),
        ],
      ],
    );
    return Center(
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: 24, vertical: compact ? 16 : 32),
        child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 360), child: content),
      ),
    );
  }
}
