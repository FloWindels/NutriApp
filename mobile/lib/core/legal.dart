/// Versions des textes légaux et liens publics.
///
/// Ces versions doivent rester identiques à celles de `web/src/lib/legal/versions.ts` :
/// elles sont enregistrées avec l'accord de la personne à l'inscription, et constituent la
/// preuve exigée par l'article 7.1 du RGPD.
class Legal {
  const Legal._();

  static const String versionCgu = '2026-09-29';
  static const String versionConfidentialite = '2026-09-29';

  /// Chemins publics, à concaténer à l'URL du site.
  static const String cheminCgu = '/cgu';
  static const String cheminConfidentialite = '/confidentialite';
  static const String cheminMentions = '/mentions-legales';
  static const String cheminCookies = '/cookies';
}
