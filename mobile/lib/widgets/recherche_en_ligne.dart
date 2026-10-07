import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/formatters.dart';
import '../core/session.dart';
import '../models/food_lookup.dart';
import '../theme/app_theme.dart';
import 'status_banner.dart';

/// Recherche des valeurs d'un produit sur Internet (`POST /foods/recherche-en-ligne`) :
/// éléments d'interface partagés par la feuille « Ajouter à un repas » et le formulaire
/// « Créer un aliment ».
class RechercheEnLigne {
  RechercheEnLigne._();

  static const String libelle = 'Chercher les valeurs sur Internet';

  static const String progression = 'Recherche sur Internet… lecture des valeurs nutritionnelles';

  /// Même offre que la photo d'assiette ou les recettes écrites par Mavi’oh.
  static const String offre = 'La recherche sur Internet fait partie de l’offre Complet. '
      'Tu peux toujours saisir les valeurs depuis l’emballage.';

  static const String aVerifier = 'Valeurs trouvées sur Internet — vérifie-les avant d’enregistrer';

  static const String indisponible =
      'Aucune valeur fiable trouvée sur Internet. Saisis-les depuis l’emballage ou une source de confiance.';

  /// Valeur numérique pour un champ de saisie (`12,5`, vide si inconnue).
  static String champ(double? value) => value == null ? '' : fmtDecimal(value, decimals: 2).replaceAll(nnbsp, '');

  /// Vrai quand on sait déjà que l'offre n'inclut pas l'IA : on affiche l'indication au lieu d'appeler.
  static bool get verrouillee => Session.instance.user?.peut('ia') == false;

  /// Ouvre une source dans le navigateur (liens http/https uniquement).
  static Future<void> ouvrirSource(BuildContext context, FoodLookupSource source) async {
    final uri = source.uri;
    final messenger = ScaffoldMessenger.maybeOf(context);
    if (uri == null) {
      messenger?.showSnackBar(const SnackBar(content: Text('Lien non pris en charge.')));
      return;
    }
    var ouvert = false;
    try {
      ouvert = await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      ouvert = false;
    }
    if (!ouvert) messenger?.showSnackBar(SnackBar(content: Text('Impossible d’ouvrir $uri')));
  }
}

/// Bouton « Chercher les valeurs sur Internet », ou l'indication d'offre quand l'IA n'est pas incluse.
class RechercheEnLigneBouton extends StatelessWidget {
  final VoidCallback? onPressed;

  /// Forcé à vrai après un 402 : on n'insiste pas.
  final bool verrouillee;

  const RechercheEnLigneBouton({super.key, required this.onPressed, this.verrouillee = false});

  @override
  Widget build(BuildContext context) {
    if (verrouillee || RechercheEnLigne.verrouillee) {
      return const StatusBanner.info(RechercheEnLigne.offre);
    }
    return OutlinedButton.icon(
      onPressed: onPressed,
      icon: const Icon(Icons.travel_explore_rounded),
      label: const Text(RechercheEnLigne.libelle),
      style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
    );
  }
}

/// Carte d'attente : l'agent peut mettre jusqu'à une minute.
class RechercheEnLigneProgression extends StatelessWidget {
  const RechercheEnLigneProgression({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: MaviohColors.infoBg,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: MaviohColors.info.withValues(alpha: 0.25)),
      ),
      child: const Row(
        children: [
          SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4)),
          SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  RechercheEnLigne.progression,
                  style: TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.info),
                ),
                SizedBox(height: 2),
                Text(
                  'Cela peut prendre jusqu’à une minute.',
                  style: TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Avertissement, confiance, avertissements et sources d'une proposition trouvée sur Internet.
class PropositionEnLigneCarte extends StatelessWidget {
  final FoodLookupResult resultat;

  const PropositionEnLigneCarte({super.key, required this.resultat});

  @override
  Widget build(BuildContext context) {
    final proposition = resultat.proposition;
    final confiance = proposition?.confiance;
    final details = [
      if (confiance != null) 'Confiance : ${fmtPct(confiance.clamp(0, 1), fraction: true)}',
      if (proposition?.estEstimation ?? false)
        proposition!.estPlat
            ? 'Estimation à partir d’une recette type : les valeurs réelles dépendent de la préparation.'
            : 'Estimation : aucune fiche n’indiquait toutes les valeurs.',
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        StatusBanner.warning(
          details.isEmpty ? 'Compare-les à l’emballage si tu l’as sous la main.' : details.join('\n'),
          title: RechercheEnLigne.aVerifier,
        ),
        for (final avertissement in resultat.avertissements)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Padding(
                  padding: EdgeInsets.only(top: 1),
                  child: Icon(Icons.info_outline_rounded, size: 16, color: MaviohColors.warning),
                ),
                const SizedBox(width: 6),
                Expanded(child: Text(avertissement, style: const TextStyle(fontSize: 12.5, color: MaviohColors.textSecondary))),
              ],
            ),
          ),
        if (resultat.sources.isNotEmpty) ...[
          const SizedBox(height: 10),
          const Text('Sources', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary)),
          for (final source in resultat.sources)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              visualDensity: VisualDensity.compact,
              leading: const Icon(Icons.link_rounded, size: 18),
              title: Text(source.titre, maxLines: 2, overflow: TextOverflow.ellipsis),
              subtitle: Text(
                source.uri?.host ?? source.url,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 12, color: MaviohColors.muted),
              ),
              trailing: source.uri == null ? null : const Icon(Icons.open_in_new_rounded, size: 16),
              onTap: source.uri == null ? null : () => RechercheEnLigne.ouvrirSource(context, source),
            ),
        ],
      ],
    );
  }
}
