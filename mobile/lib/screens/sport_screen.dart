import 'package:flutter/material.dart';

import '../core/strings.dart';
import '../widgets/empty_state.dart';

/// Sport — stub, remplacé par l’agent de l’écran (garder le nom de classe et les paramètres).
class SportScreen extends StatelessWidget {
  const SportScreen({super.key, this.onNavigate, this.openGenerate = false});

  /// Navigation rapide vers une autre section (slug).
  final ValueChanged<String>? onNavigate;

  /// Ouvre directement la feuille « Générer une séance ».
  final bool openGenerate;

  @override
  Widget build(BuildContext context) {
    return const EmptyState(
      icon: Icons.fitness_center_outlined,
      title: AppStrings.sectionSport,
      message: AppStrings.comingSoon,
    );
  }
}
