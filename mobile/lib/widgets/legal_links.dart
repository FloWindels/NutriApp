import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/legal.dart';
import '../core/env.dart';

/// Ouvre une page légale dans le navigateur.
///
/// Ces documents sont servis par le site : les dupliquer dans l'application ferait vivre deux
/// versions d'un même texte, et c'est exactement ce qu'il ne faut pas pour un document dont on
/// enregistre la version acceptée.
Future<void> ouvrirPageLegale(BuildContext context, String chemin) async {
  final uri = Uri.parse(Env.legalUrl(chemin));
  final ouvert = await launchUrl(uri, mode: LaunchMode.externalApplication);

  if (!ouvert && context.mounted) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('Impossible d’ouvrir $uri')),
    );
  }
}

/// Liste des quatre documents, pour l'écran des paramètres.
class LegalLinks extends StatelessWidget {
  const LegalLinks({super.key});

  static const _documents = <({String titre, String chemin})>[
    (titre: 'Mentions légales', chemin: Legal.cheminMentions),
    (titre: 'Conditions générales d’utilisation', chemin: Legal.cheminCgu),
    (titre: 'Politique de confidentialité', chemin: Legal.cheminConfidentialite),
    (titre: 'Stockage local et cookies', chemin: Legal.cheminCookies),
  ];

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final doc in _documents)
          ListTile(
            contentPadding: EdgeInsets.zero,
            dense: true,
            title: Text(doc.titre, style: const TextStyle(fontSize: 14)),
            trailing: const Icon(Icons.open_in_new, size: 18),
            onTap: () => ouvrirPageLegale(context, doc.chemin),
          ),
      ],
    );
  }
}
