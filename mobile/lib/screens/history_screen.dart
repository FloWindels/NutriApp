import 'package:flutter/material.dart';

import '../core/strings.dart';
import '../widgets/empty_state.dart';

/// Historique (pushed depuis Repas) — stub, remplacé par l’agent de l’écran (garder le nom de classe et les paramètres).
class HistoryScreen extends StatelessWidget {
  const HistoryScreen({super.key, this.initialDate});

  /// Jour à mettre en avant.
  final DateTime? initialDate;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text(AppStrings.sectionHistory)),
      body: const EmptyState(
      icon: Icons.history_rounded,
      title: AppStrings.sectionHistory,
      message: AppStrings.comingSoon,
      ),
    );
  }
}
