/// Compile-time environment (`--dart-define=API_BASE_URL=…`).
class Env {
  Env._();

  /// Base URL of the Laravel API. Par défaut le serveur public, en HTTPS : une version
  /// construite sans `--dart-define` ne doit jamais envoyer d'identifiants en clair. En
  /// développement, passer `--dart-define=API_BASE_URL=http://10.0.2.2:8000/api`.
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://mavioh-api.florentwindels.com/api',
  );

  /// Base URL du site web, d'où sont servies les pages légales (l'API publique ne sert que
  /// `/api`). Déduite de l'API quand elle est vide : `https://api.x/api` → `https://api.x`.
  static const String webBaseUrl = String.fromEnvironment(
    'WEB_BASE_URL',
    defaultValue: 'https://mavioh.florentwindels.com',
  );

  /// URL publique d'une page légale (`/cgu`, `/confidentialite`…).
  static String legalUrl(String chemin) {
    if (webBaseUrl.isNotEmpty) return '$webBaseUrl$chemin';
    final base = apiBaseUrl.endsWith('/api')
        ? apiBaseUrl.substring(0, apiBaseUrl.length - 4)
        : apiBaseUrl;
    return '$base$chemin';
  }

  /// Version shown in « À propos ».
  static const String appVersion = String.fromEnvironment(
    'APP_VERSION',
    defaultValue: '1.0.0',
  );
}
