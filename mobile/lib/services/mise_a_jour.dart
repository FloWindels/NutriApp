import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:ota_update/ota_update.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../core/api_client.dart';
import '../core/env.dart';
import '../theme/app_theme.dart';

/// Version publiée sur le serveur (`GET /app/android`).
class VersionPubliee {
  final int code;
  final String nom;
  final String? notes;
  final int taille;
  final String sha256;

  const VersionPubliee({
    required this.code,
    required this.nom,
    required this.notes,
    required this.taille,
    required this.sha256,
  });

  factory VersionPubliee.fromJson(Map<String, dynamic> json) => VersionPubliee(
        code: (json['version_code'] as num).toInt(),
        nom: '${json['version_name']}',
        notes: json['notes'] as String?,
        taille: (json['taille'] as num?)?.toInt() ?? 0,
        sha256: '${json['sha256']}',
      );
}

/// Mise à jour intégrée de l'application Android, distribuée hors du Play Store.
///
/// Au lancement, l'app compare son numéro de build à la version publiée sur le serveur ; si
/// une plus récente existe, elle la propose. Le téléchargement passe par l'API avec le jeton
/// du compte (l'APK n'est pas public), son empreinte SHA-256 est vérifiée, puis l'installeur
/// d'Android prend la main : Android exige toujours la confirmation de la personne.
class MiseAJour {
  MiseAJour._();

  static bool _verifieeCetteSession = false;

  /// Remplaçables par les tests.
  @visibleForTesting
  static Future<int> Function() versionInstallee = _versionInstallee;
  @visibleForTesting
  static bool Function() estAndroid = () => !kIsWeb && defaultTargetPlatform == TargetPlatform.android;

  static Future<int> _versionInstallee() async {
    final info = await PackageInfo.fromPlatform();
    return int.tryParse(info.buildNumber) ?? 0;
  }

  @visibleForTesting
  static void reinitialiser() => _verifieeCetteSession = false;

  /// À l'ouverture de l'app (`manuel: false`) : une fois par session, silencieux en cas de
  /// souci. Depuis les paramètres (`manuel: true`) : toujours, avec un retour à l'écran.
  static Future<void> verifier(BuildContext context, {bool manuel = false, ApiClient? api}) async {
    if (!estAndroid()) return;
    if (!manuel && _verifieeCetteSession) return;
    _verifieeCetteSession = true;

    final client = api ?? ApiClient.instance;
    final messenger = ScaffoldMessenger.maybeOf(context);

    VersionPubliee publiee;
    try {
      final json = await client.getJson('/app/android');
      publiee = VersionPubliee.fromJson(ApiClient.asMap(json['data']));
    } on ApiException catch (e) {
      if (manuel) {
        messenger?.showSnackBar(SnackBar(
          content: Text(e.statusCode == 404 ? 'Aucune version n’est publiée pour le moment.' : e.message),
        ));
      }
      return;
    }

    final installee = await versionInstallee();
    if (publiee.code <= installee) {
      if (manuel) {
        messenger?.showSnackBar(const SnackBar(content: Text('Tu as déjà la dernière version de Mavi’oh.')));
      }
      return;
    }

    if (!context.mounted) return;
    final accepte = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Mavi’oh ${publiee.nom} est disponible'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (publiee.notes != null) ...[
              Text(publiee.notes!),
              const SizedBox(height: 12),
            ],
            Text(
              'Téléchargement de ${(publiee.taille / 1048576).toStringAsFixed(0)} Mo. '
              'Android te demandera ensuite de confirmer l’installation ; tes données sont conservées.',
              style: const TextStyle(color: MaviohColors.muted, fontSize: 13),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Plus tard')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Mettre à jour')),
        ],
      ),
    );
    if (accepte != true || !context.mounted) return;

    final jeton = await client.readToken();
    if (!context.mounted) return;
    await _telecharger(context, publiee, jeton);
  }

  static Future<void> _telecharger(BuildContext context, VersionPubliee publiee, String? jeton) async {
    final progression = ValueNotifier<int?>(null);
    final messenger = ScaffoldMessenger.maybeOf(context);
    final navigateur = Navigator.of(context, rootNavigator: true);
    var dialogueOuvert = true;

    unawaited(showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (context) => PopScope(
        canPop: false,
        child: AlertDialog(
          title: const Text('Téléchargement de la mise à jour'),
          content: ValueListenableBuilder<int?>(
            valueListenable: progression,
            builder: (context, pourcent, _) => Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                LinearProgressIndicator(value: pourcent == null ? null : pourcent / 100),
                const SizedBox(height: 10),
                Text(pourcent == null ? 'Préparation…' : '$pourcent %'),
              ],
            ),
          ),
        ),
      ),
    ).whenComplete(() => dialogueOuvert = false));

    void fermer() {
      if (dialogueOuvert) navigateur.pop();
    }

    void erreur(String message) {
      fermer();
      messenger?.showSnackBar(SnackBar(content: Text(message), duration: const Duration(seconds: 6)));
    }

    try {
      final flux = OtaUpdate().execute(
        '${Env.apiBaseUrl}/app/android/apk',
        headers: {
          if (jeton != null) 'Authorization': 'Bearer $jeton',
          'Accept': 'application/vnd.android.package-archive',
        },
        destinationFilename: 'mavioh-${publiee.code}.apk',
        sha256checksum: publiee.sha256,
      );
      await for (final evenement in flux) {
        switch (evenement.status) {
          case OtaStatus.DOWNLOADING:
            progression.value = int.tryParse(evenement.value ?? '');
          case OtaStatus.INSTALLING:
          case OtaStatus.INSTALLATION_DONE:
            fermer();
            return;
          case OtaStatus.PERMISSION_NOT_GRANTED_ERROR:
            erreur('Autorise Mavi’oh à installer des applications (réglages Android), puis réessaie.');
            return;
          case OtaStatus.CHECKSUM_ERROR:
            erreur('Le fichier téléchargé est incomplet ou abîmé. Réessaie.');
            return;
          case OtaStatus.DOWNLOAD_ERROR:
            erreur('Téléchargement impossible. Vérifie ta connexion et réessaie.');
            return;
          case OtaStatus.CANCELED:
            fermer();
            return;
          case OtaStatus.ALREADY_RUNNING_ERROR:
          case OtaStatus.INSTALLATION_ERROR:
          case OtaStatus.INTERNAL_ERROR:
            erreur('La mise à jour n’a pas pu s’installer. Réessaie depuis les paramètres.');
            return;
        }
      }
    } catch (_) {
      erreur('La mise à jour n’a pas pu démarrer. Réessaie depuis les paramètres.');
    }
    // `progression` n'est pas libéré ici : la fenêtre qui l'écoute se ferme à l'image suivante.
  }
}
