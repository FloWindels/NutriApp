import 'package:flutter/material.dart';

import '../../models/sport.dart';
import '../../theme/app_theme.dart';

/// Couleur d'une pastille de cible.
Color cibleTone(CibleKind kind) {
  switch (kind) {
    case CibleKind.cadence:
    case CibleKind.vitesse:
      return MaviohColors.sky;
    case CibleKind.resistance:
      return MaviohColors.violet;
    case CibleKind.puissance:
      return MaviohColors.amber;
    case CibleKind.allure:
    case CibleKind.allure500:
      return MaviohColors.indigo;
    case CibleKind.inclinaison:
      return MaviohColors.teal;
    case CibleKind.coups:
      return MaviohColors.cyan;
    case CibleKind.zone:
    case CibleKind.fc:
      return MaviohColors.rose;
    case CibleKind.rpe:
      return MaviohColors.orange;
    case CibleKind.rir:
      return MaviohColors.emerald;
    case CibleKind.tempo:
      return MaviohColors.slate;
  }
}

/// Icône d'une pastille de cible.
IconData cibleIcon(CibleKind kind) {
  switch (kind) {
    case CibleKind.cadence:
      return Icons.autorenew_rounded;
    case CibleKind.vitesse:
      return Icons.speed_rounded;
    case CibleKind.resistance:
      return Icons.tune_rounded;
    case CibleKind.puissance:
      return Icons.bolt_rounded;
    case CibleKind.allure:
    case CibleKind.allure500:
      return Icons.timer_outlined;
    case CibleKind.inclinaison:
      return Icons.trending_up_rounded;
    case CibleKind.coups:
      return Icons.rowing_rounded;
    case CibleKind.zone:
    case CibleKind.fc:
      return Icons.favorite_border_rounded;
    case CibleKind.rpe:
      return Icons.local_fire_department_outlined;
    case CibleKind.rir:
      return Icons.battery_5_bar_rounded;
    case CibleKind.tempo:
      return Icons.av_timer_rounded;
  }
}

/// Pastilles de cibles (« 100–110 tr/min », « zone 4 · 150–167 bpm », « RIR 2 »…).
class CiblePastilles extends StatelessWidget {
  const CiblePastilles({super.key, required this.pastilles});

  final List<CiblePastille> pastilles;

  @override
  Widget build(BuildContext context) {
    if (pastilles.isEmpty) return const SizedBox.shrink();
    return Wrap(
      spacing: 6,
      runSpacing: 6,
      children: [
        for (final p in pastilles) _Pastille(label: p.label, tone: cibleTone(p.kind), icon: cibleIcon(p.kind)),
      ],
    );
  }
}

/// Comme [TonePill], mais le libellé passe à la ligne au lieu de déborder sur un écran étroit.
class _Pastille extends StatelessWidget {
  const _Pastille({required this.label, required this.tone, required this.icon});

  final String label;
  final Color tone;
  final IconData icon;

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
          Icon(icon, size: 12, color: tone),
          const SizedBox(width: 4),
          Flexible(
            child: Text(label, style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: tone)),
          ),
        ],
      ),
    );
  }
}

/// Ce que demande un exercice : la phrase `resume` en clair, les cibles en pastilles, la consigne.
///
/// Sans prescription (séance d'avant la v2), seule la phrase lisible reconstruite s'affiche.
class PrescriptionView extends StatelessWidget {
  const PrescriptionView({super.key, required this.resume, this.prescription, this.showConseils = true});

  final String resume;
  final Prescription? prescription;

  /// Consigne et conseil de charge (masqués dans les listes très compactes).
  final bool showConseils;

  @override
  Widget build(BuildContext context) {
    final p = prescription;
    final pastilles = p?.pastilles ?? const <CiblePastille>[];
    final recup = p?.recupCible?.texte;
    final children = <Widget>[
      if (resume.isNotEmpty)
        Text(
          resume,
          style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary, height: 1.35),
        ),
      if (pastilles.isNotEmpty) ...[
        const SizedBox(height: 6),
        CiblePastilles(pastilles: pastilles),
      ],
      if (recup != null && recup.isNotEmpty) ...[
        const SizedBox(height: 4),
        Text(
          'Récup : $recup',
          style: const TextStyle(fontSize: 12, color: MaviohColors.muted),
        ),
      ],
      if (showConseils && p?.consigne != null) ...[
        const SizedBox(height: 6),
        _Conseil(icon: Icons.lightbulb_outline_rounded, text: p!.consigne!),
      ],
      if (showConseils && p?.chargeConseil != null) ...[
        const SizedBox(height: 4),
        _Conseil(icon: Icons.fitness_center_rounded, text: p!.chargeConseil!),
      ],
    ];
    if (children.isEmpty) return const SizedBox.shrink();
    return Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: children);
  }
}

class _Conseil extends StatelessWidget {
  const _Conseil({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 1, right: 6),
          child: Icon(icon, size: 14, color: MaviohColors.muted),
        ),
        Expanded(
          child: Text(
            text,
            style: const TextStyle(fontSize: 12.5, color: MaviohColors.textTertiary, height: 1.4, fontStyle: FontStyle.italic),
          ),
        ),
      ],
    );
  }
}
