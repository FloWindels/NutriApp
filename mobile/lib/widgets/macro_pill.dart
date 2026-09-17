import 'package:flutter/material.dart';

import '../core/formatters.dart';
import '../theme/app_theme.dart';

/// « P 72 / 150 g » pill with a tone colour; [target] optional.
class MacroPill extends StatelessWidget {
  final String label;
  final num? value;
  final num? target;
  final Color color;
  final String unit;
  final bool compact;

  const MacroPill({
    super.key,
    required this.label,
    required this.value,
    this.target,
    required this.color,
    this.unit = 'g',
    this.compact = false,
  });

  const MacroPill.proteins({super.key, required this.value, this.target, this.compact = false})
      : label = 'P',
        color = MaviohColors.proteins,
        unit = 'g';

  const MacroPill.carbs({super.key, required this.value, this.target, this.compact = false})
      : label = 'G',
        color = MaviohColors.carbs,
        unit = 'g';

  const MacroPill.fat({super.key, required this.value, this.target, this.compact = false})
      : label = 'L',
        color = MaviohColors.fat,
        unit = 'g';

  @override
  Widget build(BuildContext context) {
    final valueText = value == null ? '—' : fmtInt(value);
    final text = target != null && target! > 0
        ? '$valueText / ${fmtInt(target)}$nbsp$unit'
        : '$valueText$nbsp$unit';
    return Container(
      padding: EdgeInsets.symmetric(horizontal: compact ? 8 : 10, vertical: compact ? 4 : 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: compact ? 16 : 18,
            height: compact ? 16 : 18,
            alignment: Alignment.center,
            decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(6)),
            child: Text(
              label,
              style: TextStyle(color: Colors.white, fontSize: compact ? 9 : 10, fontWeight: FontWeight.w800),
            ),
          ),
          const SizedBox(width: 6),
          Text(
            text,
            style: TextStyle(
              fontSize: compact ? 11 : 12,
              fontWeight: FontWeight.w700,
              color: MaviohColors.textSecondary,
            ),
          ),
        ],
      ),
    );
  }
}

/// Small amber « estimation » pill.
class EstimatePill extends StatelessWidget {
  final String label;

  const EstimatePill({super.key, this.label = 'estimation'});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: MaviohColors.warningBg,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: const Color(0xFFFDE68A)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.info_outline_rounded, size: 12, color: MaviohColors.warning),
          const SizedBox(width: 4),
          Text(label, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: MaviohColors.warning)),
        ],
      ),
    );
  }
}

/// Generic tinted pill (source, status…).
class TonePill extends StatelessWidget {
  final String label;
  final Color tone;
  final IconData? icon;

  const TonePill({super.key, required this.label, required this.tone, this.icon});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(
        color: tone.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tone.withValues(alpha: 0.3)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 12, color: tone),
            const SizedBox(width: 4),
          ],
          Text(label, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: tone)),
        ],
      ),
    );
  }
}
