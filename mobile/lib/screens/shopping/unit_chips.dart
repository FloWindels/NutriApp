import 'package:flutter/material.dart';

import '../../core/session.dart';
import '../../models/portion.dart';

/// Ligne de puces d’unités (catalogue `GET /portions` via [Session], repli sur
/// [Portion.defaults]). Partagée par l’ajout rapide de la liste de courses et
/// par la feuille « Mettre au stock ».
///
/// `null` correspond à « sans unité » (la quantité reste alors libre).
class UnitChips extends StatelessWidget {
  const UnitChips({super.key, required this.selected, required this.onChanged, this.allowNone = true});

  /// Unité sélectionnée (`g`, `ml`, `piece`…) ou `null`.
  final String? selected;

  final ValueChanged<String?> onChanged;

  /// Affiche la puce « Sans unité ».
  final bool allowNone;

  /// Unités connues, dans l’ordre du serveur.
  static List<Portion> units() {
    final portions = Session.instance.portions;
    return portions.isEmpty ? Portion.defaults : portions;
  }

  @override
  Widget build(BuildContext context) {
    final portions = units();
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        if (allowNone)
          ChoiceChip(
            label: const Text('Sans unité'),
            selected: selected == null,
            onSelected: (_) => onChanged(null),
          ),
        for (final portion in portions)
          ChoiceChip(
            label: Text(portion.labelShort),
            selected: selected == portion.unit,
            onSelected: (_) => onChanged(portion.unit),
          ),
      ],
    );
  }
}
