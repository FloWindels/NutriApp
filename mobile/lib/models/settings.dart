import '../core/api_client.dart';

/// `GET /settings` → `data` (§14 + addendum `ia_seances`).
class UserSettings {
  final bool notifPeremption;
  final bool notifRappelRepas;
  final bool notifRappelSport;
  final String? heureRappel; // HH:mm
  final int joursAlertePeremption;
  final String unites;
  final String theme;
  final String langue;
  final String timezone;
  final bool iaSeances;
  final bool? partageProfilFoyer; // null without household

  const UserSettings({
    this.notifPeremption = true,
    this.notifRappelRepas = false,
    this.notifRappelSport = false,
    this.heureRappel,
    this.joursAlertePeremption = 3,
    this.unites = 'metrique',
    this.theme = 'systeme',
    this.langue = 'fr',
    this.timezone = 'Europe/Paris',
    this.iaSeances = true,
    this.partageProfilFoyer,
  });

  factory UserSettings.fromJson(Map<String, dynamic> json) => UserSettings(
        notifPeremption: parseBool(json['notif_peremption'], fallback: true),
        notifRappelRepas: parseBool(json['notif_rappel_repas']),
        notifRappelSport: parseBool(json['notif_rappel_sport']),
        heureRappel: _time(json['heure_rappel']),
        joursAlertePeremption: parseIntOr(json['jours_alerte_peremption'], 3),
        unites: parseString(json['unites']) ?? 'metrique',
        theme: parseString(json['theme']) ?? 'systeme',
        langue: parseString(json['langue']) ?? 'fr',
        timezone: parseString(json['timezone']) ?? 'Europe/Paris',
        iaSeances: parseBool(json['ia_seances'], fallback: true),
        partageProfilFoyer: json.containsKey('partage_profil_foyer') && json['partage_profil_foyer'] != null
            ? parseBool(json['partage_profil_foyer'])
            : null,
      );

  static String? _time(dynamic value) {
    final text = parseString(value);
    if (text == null) return null;
    final parts = text.split(':');
    if (parts.length < 2) return text;
    return '${parts[0].padLeft(2, '0')}:${parts[1].padLeft(2, '0')}';
  }

  Map<String, dynamic> toJson() => {
        'notif_peremption': notifPeremption,
        'notif_rappel_repas': notifRappelRepas,
        'notif_rappel_sport': notifRappelSport,
        'heure_rappel': heureRappel,
        'jours_alerte_peremption': joursAlertePeremption,
        'unites': unites,
        'theme': theme,
        'langue': langue,
        'timezone': timezone,
        'ia_seances': iaSeances,
        'partage_profil_foyer': ?partageProfilFoyer,
      };

  UserSettings copyWith({
    bool? notifPeremption,
    bool? notifRappelRepas,
    bool? notifRappelSport,
    String? heureRappel,
    bool clearHeureRappel = false,
    int? joursAlertePeremption,
    String? unites,
    String? theme,
    String? langue,
    String? timezone,
    bool? iaSeances,
    bool? partageProfilFoyer,
  }) {
    return UserSettings(
      notifPeremption: notifPeremption ?? this.notifPeremption,
      notifRappelRepas: notifRappelRepas ?? this.notifRappelRepas,
      notifRappelSport: notifRappelSport ?? this.notifRappelSport,
      heureRappel: clearHeureRappel ? null : (heureRappel ?? this.heureRappel),
      joursAlertePeremption: joursAlertePeremption ?? this.joursAlertePeremption,
      unites: unites ?? this.unites,
      theme: theme ?? this.theme,
      langue: langue ?? this.langue,
      timezone: timezone ?? this.timezone,
      iaSeances: iaSeances ?? this.iaSeances,
      partageProfilFoyer: partageProfilFoyer ?? this.partageProfilFoyer,
    );
  }
}

/// `GET /account/export` → `data` (kept raw for the JSON dialog).
class AccountExport {
  final Map<String, dynamic> data;
  final DateTime? exportedAt;

  const AccountExport({required this.data, this.exportedAt});

  factory AccountExport.fromJson(Map<String, dynamic> json) => AccountExport(
        data: json,
        exportedAt: parseDate(json['exported_at']),
      );
}
