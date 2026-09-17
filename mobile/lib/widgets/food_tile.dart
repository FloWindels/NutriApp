import 'package:flutter/material.dart';

import '../core/formatters.dart';
import '../models/food.dart';
import '../theme/app_theme.dart';
import 'macro_pill.dart';

/// List row for a [Food]: thumbnail, name, brand, kcal/100 g, source pill.
class FoodTile extends StatelessWidget {
  final Food food;
  final VoidCallback? onTap;
  final Widget? trailing;
  final String? subtitleOverride;
  final bool showSource;
  final bool dense;

  const FoodTile({
    super.key,
    required this.food,
    this.onTap,
    this.trailing,
    this.subtitleOverride,
    this.showSource = true,
    this.dense = false,
  });

  Color get _sourceTone {
    if (food.isVerified) return MaviohColors.success;
    if (food.isFromOpenFoodFacts) return MaviohColors.sky;
    return MaviohColors.slate;
  }

  @override
  Widget build(BuildContext context) {
    final subtitle = subtitleOverride ??
        [
          if (food.brand != null) food.brand!,
          if (food.calories != null) '${fmtInt(food.calories)}${nbsp}kcal / 100$nbsp${food.isLiquid ? 'ml' : 'g'}',
        ].join(' · ');

    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Container(
          constraints: const BoxConstraints(minHeight: 56),
          padding: EdgeInsets.symmetric(horizontal: 12, vertical: dense ? 8 : 10),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: MaviohColors.border),
          ),
          child: Row(
            children: [
              FoodThumb(imageUrl: food.imageUrl, size: dense ? 40 : 46),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      food.name,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w700, color: MaviohColors.text),
                    ),
                    if (subtitle.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Text(
                        subtitle,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                      ),
                    ],
                    if (showSource && !dense) ...[
                      const SizedBox(height: 6),
                      Wrap(
                        spacing: 6,
                        runSpacing: 4,
                        children: [
                          TonePill(label: food.sourceLabel, tone: _sourceTone),
                          if (food.isEstimate) const EstimatePill(),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
              if (trailing != null) ...[const SizedBox(width: 8), trailing!],
              if (trailing == null && onTap != null) const Icon(Icons.chevron_right_rounded, color: MaviohColors.muted),
            ],
          ),
        ),
      ),
    );
  }
}

/// Square image with a fallback icon.
class FoodThumb extends StatelessWidget {
  final String? imageUrl;
  final double size;
  final IconData fallbackIcon;

  const FoodThumb({super.key, this.imageUrl, this.size = 46, this.fallbackIcon = Icons.lunch_dining_outlined});

  @override
  Widget build(BuildContext context) {
    final fallback = Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: MaviohColors.surface, borderRadius: BorderRadius.circular(12)),
      child: Icon(fallbackIcon, color: MaviohColors.muted, size: size * 0.5),
    );
    final url = imageUrl;
    if (url == null || url.isEmpty || !url.startsWith('http')) return fallback;
    return ClipRRect(
      borderRadius: BorderRadius.circular(12),
      child: Image.network(
        url,
        width: size,
        height: size,
        fit: BoxFit.cover,
        errorBuilder: (_, _, _) => fallback,
      ),
    );
  }
}
