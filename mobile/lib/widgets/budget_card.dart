import 'package:flutter/material.dart';

import '../core/formatters.dart';
import '../models/meal.dart';
import '../theme/app_theme.dart';
import 'app_card.dart';
import 'macro_pill.dart';

/// Daily budget card: « 1 240 kcal restantes » (or « Dépassé de 120 kcal »),
/// progress bar consumed/target, caption, three macro pills.
class BudgetCard extends StatelessWidget {
  final Totals consumed;
  final Totals targets;
  final Totals remaining;
  final double caloriesBonus;
  final bool isEstimate;
  final VoidCallback? onTap;
  final String? title;

  const BudgetCard({
    super.key,
    required this.consumed,
    required this.targets,
    required this.remaining,
    this.caloriesBonus = 0,
    this.isEstimate = true,
    this.onTap,
    this.title,
  });

  /// Builds from a [DaySummary].
  factory BudgetCard.fromDay(DaySummary day, {Key? key, VoidCallback? onTap, String? title}) => BudgetCard(
        key: key,
        consumed: day.totals,
        targets: day.targets,
        remaining: day.remaining,
        caloriesBonus: day.sport.caloriesBonus,
        isEstimate: day.sport.isEstimate,
        onTap: onTap,
        title: title,
      );

  @override
  Widget build(BuildContext context) {
    final hasTarget = targets.calories > 0;
    final budget = targets.calories + caloriesBonus;
    final over = hasTarget && remaining.calories < 0;
    final progress = budget > 0 ? (consumed.calories / budget).clamp(0.0, 1.0) : 0.0;
    final headline = !hasTarget
        ? fmtKcal(consumed.calories)
        : over
            ? 'Dépassé de ${fmtKcal(-remaining.calories)}'
            : '${fmtKcal(remaining.calories)} restantes';
    final headlineColor = over ? MaviohColors.error : MaviohColors.text;

    final captionParts = <String>[
      '${fmtInt(consumed.calories)} consommées',
      if (hasTarget) 'objectif ${fmtInt(targets.calories)}',
      if (caloriesBonus > 0) '+${fmtInt(caloriesBonus)} sport',
    ];

    return AppCard.hero(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  title ?? 'Budget du jour',
                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.muted, letterSpacing: 0.4),
                ),
              ),
              if (isEstimate) const EstimatePill(),
            ],
          ),
          const SizedBox(height: 8),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              headline,
              style: TextStyle(fontSize: 28, fontWeight: FontWeight.w800, color: headlineColor, height: 1.1),
            ),
          ),
          const SizedBox(height: 12),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              minHeight: 10,
              value: hasTarget ? progress : null,
              backgroundColor: MaviohColors.border,
              valueColor: AlwaysStoppedAnimation<Color>(over ? MaviohColors.error : MaviohColors.primary),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            captionParts.join(' · '),
            style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, fontWeight: FontWeight.w600),
          ),
          if (!hasTarget) ...[
            const SizedBox(height: 4),
            const Text(
              'Complète ton profil pour obtenir un objectif calorique.',
              style: TextStyle(fontSize: 12, color: MaviohColors.warning, fontWeight: FontWeight.w600),
            ),
          ],
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              MacroPill.proteins(value: consumed.proteins, target: hasTarget ? targets.proteins : null),
              MacroPill.carbs(value: consumed.carbs, target: hasTarget ? targets.carbs : null),
              MacroPill.fat(value: consumed.fat, target: hasTarget ? targets.fat : null),
            ],
          ),
        ],
      ),
    );
  }
}
