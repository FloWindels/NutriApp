import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';

/// Liste à puces utilisée pour les principes, les conseils et les raisons.
class DietBulletList extends StatelessWidget {
  const DietBulletList({
    super.key,
    required this.items,
    this.color = MaviohColors.accent,
    this.textColor = MaviohColors.textSecondary,
    this.fontSize = 13.5,
  });

  final List<String> items;
  final Color color;
  final Color textColor;
  final double fontSize;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final item in items)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.only(top: 7, right: 8),
                  child: SizedBox(
                    width: 5,
                    height: 5,
                    child: DecoratedBox(
                      decoration: BoxDecoration(color: color, shape: BoxShape.circle),
                    ),
                  ),
                ),
                Expanded(
                  child: Text(
                    item,
                    style: TextStyle(fontSize: fontSize, color: textColor, height: 1.45),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}

/// Titre de sous-section dans une carte régime.
class DietSubtitle extends StatelessWidget {
  const DietSubtitle(this.label, {super.key, this.icon, this.color = MaviohColors.text});

  final String label;
  final IconData? icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        children: [
          if (icon != null) ...[
            Icon(icon, size: 16, color: color),
            const SizedBox(width: 6),
          ],
          Flexible(
            child: Text(
              label,
              style: TextStyle(fontSize: 14, fontWeight: FontWeight.w800, color: color),
            ),
          ),
        ],
      ),
    );
  }
}

/// Liste d’aliments sous forme de pastilles (conseillés / à limiter).
class DietFoodChips extends StatelessWidget {
  const DietFoodChips({super.key, required this.items, required this.tone});

  final List<String> items;
  final Color tone;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) return const SizedBox.shrink();
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final item in items)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: MaviohColors.tint(tone),
              borderRadius: BorderRadius.circular(999),
              border: Border.all(color: tone.withValues(alpha: 0.28)),
            ),
            child: Text(
              item,
              style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: tone),
            ),
          ),
      ],
    );
  }
}
