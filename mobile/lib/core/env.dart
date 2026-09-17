/// Compile-time environment (`--dart-define=API_BASE_URL=…`).
class Env {
  Env._();

  /// Base URL of the Laravel API, e.g. `https://api.mavioh.app/api`.
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api',
  );

  /// Version shown in « À propos ».
  static const String appVersion = String.fromEnvironment(
    'APP_VERSION',
    defaultValue: '1.0.0',
  );
}
