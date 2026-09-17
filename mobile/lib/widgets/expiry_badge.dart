import 'package:flutter/material.dart';

import '../core/formatters.dart';
import '../theme/app_theme.dart';

/// Expiry chip: perime rose / aujourd’hui-demain amber / ≤ n jours amber light / ok slate / ddm.
class ExpiryBadge extends StatelessWidget {
  final String status; // ok|bientot|aujourdhui|perime|ddm_depassee|inconnu
  final int? daysLeft;
  final String expiryKind; // dlc | ddm
  final bool compact;

  const ExpiryBadge({
    super.key,
    required this.status,
    this.daysLeft,
    this.expiryKind = 'dlc',
    this.compact = false,
  });

  @override
  Widget build(BuildContext context) {
    final Color fg;
    final Color bg;
    final String text;
    IconData icon;

    switch (status) {
      case 'perime':
        fg = MaviohColors.error;
        bg = MaviohColors.errorBg;
        text = 'Périmé';
        icon = Icons.dangerous_outlined;
        break;
      case 'ddm_depassee':
        fg = MaviohColors.warning;
        bg = MaviohColors.warningBg;
        text = 'DDM dépassée';
        icon = Icons.visibility_outlined;
        break;
      case 'aujourdhui':
        fg = MaviohColors.warning;
        bg = const Color(0xFFFEF3C7);
        text = 'Aujourd’hui';
        icon = Icons.schedule_rounded;
        break;
      case 'bientot':
        fg = MaviohColors.warning;
        bg = MaviohColors.warningBg;
        text = daysLeft == 1 ? 'Demain' : fmtDaysLeft(daysLeft);
        icon = Icons.timelapse_rounded;
        break;
      case 'ok':
        fg = MaviohColors.slate;
        bg = MaviohColors.surface;
        text = daysLeft == null ? 'OK' : fmtDaysLeft(daysLeft);
        icon = Icons.check_rounded;
        break;
      default:
        fg = MaviohColors.slate;
        bg = MaviohColors.surface;
        text = 'Sans date';
        icon = Icons.event_busy_outlined;
    }

    final label = expiryKind == 'ddm' && status != 'ddm_depassee' && status != 'inconnu' ? '$text · DDM' : text;

    return Semantics(
      label: 'Péremption : $label',
      child: Container(
        padding: EdgeInsets.symmetric(horizontal: compact ? 7 : 9, vertical: compact ? 3 : 4),
        decoration: BoxDecoration(
          color: bg,
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: fg.withValues(alpha: 0.3)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: compact ? 11 : 13, color: fg),
            const SizedBox(width: 4),
            Text(label, style: TextStyle(fontSize: compact ? 10.5 : 11.5, fontWeight: FontWeight.w700, color: fg)),
          ],
        ),
      ),
    );
  }
}
