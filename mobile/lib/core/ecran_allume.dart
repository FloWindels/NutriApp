import 'package:flutter/services.dart';

/// Garde l'écran allumé pendant la séance guidée, sans dépendance supplémentaire.
///
/// Un canal natif minimal (Android : `FLAG_KEEP_SCREEN_ON` dans `MainActivity` ; iOS :
/// `isIdleTimerDisabled` dans `AppDelegate`). Si le canal manque (tests, web, ancienne
/// version native), l'appel échoue en silence : l'écran se met simplement en veille comme avant.
class EcranAllume {
  EcranAllume._();

  static const MethodChannel _canal = MethodChannel('app.mavioh/ecran');

  static Future<void> activer() => _appel(true);

  static Future<void> desactiver() => _appel(false);

  static Future<void> _appel(bool allume) async {
    try {
      await _canal.invokeMethod<void>('garderAllume', allume);
    } on MissingPluginException {
      // Plateforme sans canal : rien à faire.
    } on PlatformException {
      // Refus natif : rien à faire, ce n'est qu'un confort.
    }
  }
}
