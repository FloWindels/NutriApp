import '../core/api_client.dart';

/// Minimal settings embedded in `/me`.
class MeSettings {
  final String timezone;
  final String theme;

  const MeSettings({this.timezone = 'Europe/Paris', this.theme = 'systeme'});

  factory MeSettings.fromJson(Map<String, dynamic> json) => MeSettings(
        timezone: parseString(json['timezone']) ?? 'Europe/Paris',
        theme: parseString(json['theme']) ?? 'systeme',
      );

  Map<String, dynamic> toJson() => {'timezone': timezone, 'theme': theme};
}

/// `GET /me` (top-level user + siblings).
class Me {
  final int id;
  final String name;
  final String email;
  final DateTime? emailVerifiedAt;
  final DateTime? createdAt;
  final DateTime? updatedAt;
  final bool hasProfile;
  final int? householdId;
  final bool consentementSante;
  final MeSettings settings;

  const Me({
    required this.id,
    required this.name,
    required this.email,
    this.emailVerifiedAt,
    this.createdAt,
    this.updatedAt,
    this.hasProfile = false,
    this.householdId,
    this.consentementSante = false,
    this.settings = const MeSettings(),
  });

  /// Works for both `/me` (flat) and `/login` (`user` nested) payloads.
  factory Me.fromJson(Map<String, dynamic> json) {
    final user = json['user'] is Map ? ApiClient.asMap(json['user']) : json;
    return Me(
      id: parseIntOr(user['id'], 0),
      name: parseString(user['name']) ?? 'Utilisateur',
      email: parseString(user['email']) ?? '',
      emailVerifiedAt: parseDate(user['email_verified_at']),
      createdAt: parseDate(user['created_at']),
      updatedAt: parseDate(user['updated_at']),
      hasProfile: parseBool(json['has_profile'] ?? user['has_profile']),
      householdId: parseInt(json['household_id'] ?? user['household_id']),
      consentementSante: parseBool(json['consentement_sante'] ?? user['consentement_sante']),
      settings: MeSettings.fromJson(ApiClient.asMap(json['settings'] ?? user['settings'])),
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'email': email,
        'email_verified_at': emailVerifiedAt?.toIso8601String(),
        'created_at': createdAt?.toIso8601String(),
        'updated_at': updatedAt?.toIso8601String(),
        'has_profile': hasProfile,
        'household_id': householdId,
        'consentement_sante': consentementSante,
        'settings': settings.toJson(),
      };

  /// First name (first word of [name]).
  String get firstName {
    final trimmed = name.trim();
    if (trimmed.isEmpty) return 'toi';
    return trimmed.split(RegExp(r'\s+')).first;
  }

  bool get hasHousehold => householdId != null;

  Me copyWith({
    String? name,
    String? email,
    bool? hasProfile,
    int? householdId,
    bool clearHousehold = false,
    bool? consentementSante,
    MeSettings? settings,
  }) {
    return Me(
      id: id,
      name: name ?? this.name,
      email: email ?? this.email,
      emailVerifiedAt: emailVerifiedAt,
      createdAt: createdAt,
      updatedAt: updatedAt,
      hasProfile: hasProfile ?? this.hasProfile,
      householdId: clearHousehold ? null : (householdId ?? this.householdId),
      consentementSante: consentementSante ?? this.consentementSante,
      settings: settings ?? this.settings,
    );
  }
}

/// `POST /login` / `POST /register` response.
class AuthResult {
  final String token;
  final Me user;

  const AuthResult({required this.token, required this.user});

  factory AuthResult.fromJson(Map<String, dynamic> json) => AuthResult(
        token: parseString(json['token']) ?? '',
        user: Me.fromJson(ApiClient.asMap(json['user'])),
      );
}
