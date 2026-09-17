import 'package:flutter/material.dart';

import '../models/me.dart';
import '../models/portion.dart';
import '../screens/login_screen.dart';
import 'api_client.dart';

/// Global navigator key (used by the API client on session expiry).
class AppNavigator {
  AppNavigator._();

  static final GlobalKey<NavigatorState> key = GlobalKey<NavigatorState>(debugLabel: 'mavioh-root');
}

/// A cached payload with its fetch time.
class CachedPayload {
  final Map<String, dynamic> data;
  final DateTime fetchedAt;

  const CachedPayload(this.data, this.fetchedAt);

  bool isStale([Duration ttl = Session.staleAfter]) => DateTime.now().difference(fetchedAt) > ttl;
}

/// Application session: current user, portions catalogue and per-section cache.
class Session extends ChangeNotifier {
  Session._();

  static final Session instance = Session._();

  /// Payloads are considered stale after this duration.
  static const Duration staleAfter = Duration(seconds: 60);

  Me? _user;
  List<Portion> _portions = const [];
  final Map<String, CachedPayload> cache = <String, CachedPayload>{};

  Me? get user => _user;

  bool get isAuthenticated => _user != null;

  List<Portion> get portions => _portions;

  set portions(List<Portion> value) {
    _portions = value;
    notifyListeners();
  }

  /// Sets the current user (after login/register/splash).
  void hydrate(Me me) {
    _user = me;
    notifyListeners();
  }

  /// Updates the current user without a network call.
  void updateUser(Me me) => hydrate(me);

  /// Reloads `GET /me` and updates [user]. Errors propagate as [ApiException].
  Future<Me> refreshMe() async {
    final json = await ApiClient.instance.getJson('/me');
    final me = Me.fromJson(json);
    hydrate(me);
    return me;
  }

  /// Loads `GET /portions` once (ignores failures, keeps previous value).
  Future<void> loadPortions({bool force = false}) async {
    if (_portions.isNotEmpty && !force) return;
    try {
      final json = await ApiClient.instance.getJson('/portions');
      final list = ApiClient.asList(json['data']).map(Portion.fromJson).toList();
      if (list.isNotEmpty) {
        _portions = list;
        notifyListeners();
      }
    } on ApiException {
      if (_portions.isEmpty) {
        _portions = Portion.defaults;
        notifyListeners();
      }
    }
  }

  // ----- Cache --------------------------------------------------------------

  /// Returns the cached payload for [slug] (even if stale), or null.
  CachedPayload? cached(String slug) => cache[slug];

  /// Returns the cached payload only when fresh.
  Map<String, dynamic>? fresh(String slug) {
    final entry = cache[slug];
    if (entry == null || entry.isStale()) return null;
    return entry.data;
  }

  /// Stores a payload under [slug].
  void put(String slug, Map<String, dynamic> data) {
    cache[slug] = CachedPayload(data, DateTime.now());
  }

  /// Removes one cached payload.
  void invalidate(String slug) => cache.remove(slug);

  /// Removes payloads whose slug starts with [prefix].
  void invalidatePrefix(String prefix) {
    cache.removeWhere((key, _) => key.startsWith(prefix));
  }

  /// Clears everything except the user.
  void clearCache() => cache.clear();

  // ----- Lifecycle ----------------------------------------------------------

  /// Clears the in-memory session (token deletion is done by the caller).
  void _reset() {
    _user = null;
    cache.clear();
    notifyListeners();
  }

  /// POST /logout (failure ignored) → clear → Login.
  Future<void> logout() async {
    try {
      await ApiClient.instance.postJson('/logout');
    } catch (_) {
      // Ignore: the token is deleted locally anyway.
    }
    await ApiClient.instance.clearToken();
    _reset();
    final navigator = AppNavigator.key.currentState;
    if (navigator != null) {
      navigator.pushAndRemoveUntil(
        MaterialPageRoute<void>(builder: (_) => const LoginScreen()),
        (route) => false,
      );
    }
  }

  /// Called by the API client on a 401: delete the token and clear state.
  Future<void> expire() async {
    await ApiClient.instance.clearToken();
    _reset();
  }
}
