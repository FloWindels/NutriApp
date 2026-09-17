import 'package:flutter/material.dart';

import '../core/strings.dart';
import '../widgets/empty_state.dart';

/// Famille — stub, remplacé par l’agent de l’écran (garder le nom de classe et les paramètres).
class FamilyScreen extends StatelessWidget {
  const FamilyScreen({super.key, this.onNavigate});

  /// Navigation rapide vers une autre section (slug).
  final ValueChanged<String>? onNavigate;

  @override
  Widget build(BuildContext context) {
    return const EmptyState(
      icon: Icons.family_restroom_outlined,
      title: AppStrings.sectionFamily,
      message: AppStrings.comingSoon,
    );
  }
}
