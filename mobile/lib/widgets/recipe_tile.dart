import 'dart:convert';

import 'package:flutter/material.dart';

import '../core/formatters.dart';
import '../core/strings.dart';
import '../models/recipe.dart';
import '../theme/app_theme.dart';
import 'macro_pill.dart';

/// List row for a [Recipe]: image, title, kcal per portion, tags.
class RecipeTile extends StatelessWidget {
  final Recipe recipe;
  final VoidCallback? onTap;
  final Widget? trailing;
  final bool dense;

  const RecipeTile({super.key, required this.recipe, this.onTap, this.trailing, this.dense = false});

  @override
  Widget build(BuildContext context) {
    final perServing = recipe.perServing.calories;
    final subtitleParts = <String>[
      perServing == null ? '— kcal / portion' : '${fmtInt(perServing)}${nbsp}kcal / portion',
      if (recipe.prepTimeMinutes != null) '${recipe.prepTimeMinutes}${nbsp}min',
      if (recipe.ingredientsCount > 0) '${recipe.ingredientsCount} ingrédient${recipe.ingredientsCount > 1 ? 's' : ''}',
    ];

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
              RecipeThumb(imageUrl: recipe.imageUrl, size: dense ? 40 : 52),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      recipe.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w700, color: MaviohColors.text),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      subtitleParts.join(' · '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                    ),
                    if (!dense && (recipe.tags.isNotEmpty || recipe.isEstimate)) ...[
                      const SizedBox(height: 6),
                      Wrap(
                        spacing: 6,
                        runSpacing: 4,
                        children: [
                          ...recipe.tags.take(3).map(
                                (t) => TonePill(label: AppStrings.recipeTagLabels[t] ?? t, tone: MaviohColors.teal),
                              ),
                          if (recipe.isEstimate) const EstimatePill(),
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

/// Recipe image (supports http URLs and data URIs) with a fallback.
class RecipeThumb extends StatelessWidget {
  final String? imageUrl;
  final double size;

  const RecipeThumb({super.key, this.imageUrl, this.size = 52});

  @override
  Widget build(BuildContext context) {
    final fallback = Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: MaviohColors.surface, borderRadius: BorderRadius.circular(14)),
      child: Icon(Icons.restaurant_menu_rounded, color: MaviohColors.muted, size: size * 0.5),
    );
    final url = imageUrl;
    if (url == null || url.isEmpty) return fallback;

    Widget image;
    if (url.startsWith('data:image')) {
      final comma = url.indexOf(',');
      if (comma < 0) return fallback;
      try {
        final bytes = base64Decode(url.substring(comma + 1));
        image = Image.memory(bytes, width: size, height: size, fit: BoxFit.cover, errorBuilder: (_, _, _) => fallback);
      } catch (_) {
        return fallback;
      }
    } else if (url.startsWith('http')) {
      image = Image.network(url, width: size, height: size, fit: BoxFit.cover, errorBuilder: (_, _, _) => fallback);
    } else {
      return fallback;
    }
    return ClipRRect(borderRadius: BorderRadius.circular(14), child: image);
  }
}
