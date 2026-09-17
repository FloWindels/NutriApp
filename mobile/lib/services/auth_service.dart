import '../core/api_client.dart';
import '../core/session.dart';
import '../models/me.dart';

/// Auth & account endpoints (§1). Passwords are never trimmed.
class AuthService {
  AuthService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// `POST /login` → stores the token and hydrates [Session].
  Future<AuthResult> login({required String email, required String password}) async {
    final json = await _api.postJson('/login', body: {
      'email': email.trim(),
      'password': password,
    });
    final result = AuthResult.fromJson(json);
    await _api.saveToken(result.token);
    Session.instance.hydrate(result.user);
    return result;
  }

  /// `POST /register` → stores the token and hydrates [Session].
  Future<AuthResult> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) async {
    final json = await _api.postJson('/register', body: {
      'name': name.trim(),
      'email': email.trim(),
      'password': password,
      'password_confirmation': passwordConfirmation,
    });
    final result = AuthResult.fromJson(json);
    await _api.saveToken(result.token);
    Session.instance.hydrate(result.user);
    return result;
  }

  /// `GET /me`.
  Future<Me> me() async {
    final json = await _api.getJson('/me');
    return Me.fromJson(json);
  }

  /// `POST /logout` (failure ignored) → clears token + session → Login.
  Future<void> logout() => Session.instance.logout();

  /// `POST /forgot-password` → French message (always 200).
  Future<String> forgotPassword(String email) async {
    final json = await _api.postJson('/forgot-password', body: {'email': email.trim()});
    return parseString(json['message']) ?? 'Si un compte existe, un lien de réinitialisation a été envoyé.';
  }

  /// `POST /reset-password`.
  Future<String> resetPassword({
    required String email,
    required String token,
    required String password,
    required String passwordConfirmation,
  }) async {
    final json = await _api.postJson('/reset-password', body: {
      'email': email.trim(),
      'token': token,
      'password': password,
      'password_confirmation': passwordConfirmation,
    });
    return parseString(json['message']) ?? 'Mot de passe réinitialisé.';
  }

  /// `PUT /account {name, email}` → updated user in [Session].
  Future<Me> updateAccount({required String name, required String email}) async {
    final json = await _api.putJson('/account', body: {'name': name.trim(), 'email': email.trim()});
    final data = ApiClient.asMap(json['data']);
    final current = Session.instance.user;
    final updated = (current ?? Me.fromJson(data)).copyWith(
      name: parseString(data['name']) ?? name,
      email: parseString(data['email']) ?? email,
    );
    Session.instance.hydrate(updated);
    return updated;
  }

  /// `PUT /account/password`.
  Future<String> changePassword({
    required String currentPassword,
    required String password,
    required String passwordConfirmation,
  }) async {
    final json = await _api.putJson('/account/password', body: {
      'current_password': currentPassword,
      'password': password,
      'password_confirmation': passwordConfirmation,
    });
    return parseString(json['message']) ?? 'Mot de passe modifié.';
  }

  /// `DELETE /account {password}` → clears the local session (caller navigates).
  Future<String> deleteAccount(String password) async {
    final json = await _api.deleteJson('/account', body: {'password': password});
    await _api.clearToken();
    await Session.instance.expire();
    return parseString(json['message']) ?? 'Compte supprimé.';
  }

  /// `GET /account/export` → raw `data`.
  Future<Map<String, dynamic>> exportAccount() async {
    final json = await _api.getJson('/account/export');
    return ApiClient.asMap(json['data']);
  }

  /// Reads the stored token (null when logged out).
  Future<String?> getToken() => _api.readToken();
}
