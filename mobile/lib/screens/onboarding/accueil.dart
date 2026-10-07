import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../models/settings.dart';
import '../../services/settings_service.dart';
import 'onboarding_screen.dart';
import 'visite_guidee.dart';

/// L'accueil d'un nouveau compte : le questionnaire de première connexion, puis la visite.
///
/// Ce qui est fait se lit dans les paramètres du serveur (`onboarding_termine_le`,
/// `visite_vue_le`), partagés avec le site : la visite faite sur l'ordinateur ne revient pas
/// sur le téléphone. « Plus tard » ne vaut que jusqu'au prochain lancement de l'app.
class Accueil {
  Accueil._();

  /// Compte qui a répondu « Plus tard » au questionnaire, pour la durée de cette session de
  /// l'app. Lié au compte : se déconnecter puis entrer avec un autre ne l'en dispense pas.
  static int? _reportePour;

  /// Une seule proposition par lancement et par compte, même si l'accueil est reconstruit.
  static int? _lancePour;

  /// À appeler une fois l'écran d'accueil affiché. Silencieux en cas d'erreur réseau :
  /// on reproposera au prochain lancement plutôt que de bloquer l'app.
  ///
  /// [naviguer] est la navigation de l'écran principal, que la visite emprunte pour
  /// montrer chaque écran (voir [VisiteGuidee]).
  static Future<void> lancer(
    BuildContext context, {
    SettingsService? service,
    VoidCallback? onProfilComplete,
    ValueChanged<String>? naviguer,
  }) async {
    final user = Session.instance.user;
    if (user == null || _lancePour == user.id) return;
    _lancePour = user.id;

    final settingsService = service ?? SettingsService();
    UserSettings settings;
    try {
      settings = await settingsService.get();
    } on ApiException {
      _lancePour = null;
      return;
    }
    if (!context.mounted) return;

    if (!user.hasProfile && settings.onboardingTermineLe == null && _reportePour != user.id) {
      final termine = await ouvrirQuestionnaire(context, settingsService: settingsService);
      if (!context.mounted) return;
      if (termine) {
        onProfilComplete?.call();
      } else {
        _reportePour = user.id;
      }
    }

    if (settings.visiteVueLe == null) {
      await montrerVisite(context, service: settingsService, naviguer: naviguer);
    }
  }

  /// Ouvre le questionnaire (aussi depuis le Profil ou les Paramètres). Vrai s'il est terminé.
  static Future<bool> ouvrirQuestionnaire(BuildContext context, {SettingsService? settingsService}) async {
    final termine = await Navigator.of(context).push<bool>(
      MaterialPageRoute<bool>(
        fullscreenDialog: true,
        builder: (_) => OnboardingScreen(settingsService: settingsService),
      ),
    );
    return termine ?? false;
  }

  /// Montre la visite puis la marque vue, qu'elle ait été terminée ou passée.
  static Future<void> montrerVisite(
    BuildContext context, {
    SettingsService? service,
    ValueChanged<String>? naviguer,
  }) async {
    await VisiteGuidee.show(context, naviguer: naviguer);
    try {
      await (service ?? SettingsService()).update({'visite_vue': true});
    } on ApiException {
      // Sans gravité : au pire, la visite sera reproposée au prochain lancement.
    }
  }

  /// Oublie ce qui a été proposé pendant cette session (tests).
  @visibleForTesting
  static void reinitialiser() {
    _reportePour = null;
    _lancePour = null;
  }
}
