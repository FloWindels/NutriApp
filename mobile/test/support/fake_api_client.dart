import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:mavioh/core/api_client.dart';

/// A canned response for the fake transport.
class FakeResponse {
  final int status;
  final Object? body;

  const FakeResponse(this.body, {this.status = 200});

  const FakeResponse.notFound() : this(const {'message': 'Introuvable.'}, status: 404);

  const FakeResponse.unauthorized() : this(const {'message': 'Non authentifié.'}, status: 401);

  const FakeResponse.serverError() : this(const {'message': 'Erreur serveur.'}, status: 500);
}

/// Route handler: receives the request and returns a canned response (or throws a [DioException]).
typedef FakeHandler = FakeResponse Function(RequestOptions request);

/// Builds an [ApiClient] whose transport is an in-memory map of routes.
///
/// ```dart
/// final api = FakeApiClient.build({
///   'GET /dashboard': (_) => FakeResponse({'data': {...}}),
/// });
/// ApiClient.instance = api;
/// ```
/// Keys are `'METHOD /path'` (path without base URL or query). A key `'* /path'`
/// matches any method. Unmatched requests return 404 unless [networkError] is set,
/// in which case every request fails with a connection error.
class FakeApiClient {
  FakeApiClient._();

  static const String baseUrl = 'http://fake.test/api';

  static ApiClient build(Map<String, FakeHandler> routes, {bool networkError = false, Duration? delay}) {
    final dio = Dio(BaseOptions(baseUrl: baseUrl, headers: {'Accept': 'application/json'}));
    dio.httpClientAdapter = _FakeAdapter(routes, networkError: networkError, delay: delay);
    return ApiClient.withDio(dio, installInterceptors: false);
  }

  /// Convenience: a client where every request fails with a network error.
  static ApiClient offline() => build(const {}, networkError: true);

  /// Requests seen by the last built adapter (method + path), newest last.
  static final List<String> calls = <String>[];
}

class _FakeAdapter implements HttpClientAdapter {
  final Map<String, FakeHandler> routes;
  final bool networkError;
  final Duration? delay;

  _FakeAdapter(this.routes, {required this.networkError, this.delay});

  @override
  void close({bool force = false}) {}

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    if (delay != null) await Future<void>.delayed(delay!);
    final path = options.uri.path.replaceFirst(Uri.parse(FakeApiClient.baseUrl).path, '');
    final key = '${options.method.toUpperCase()} $path';
    FakeApiClient.calls.add(key);

    if (networkError) {
      throw DioException.connectionError(requestOptions: options, reason: 'offline');
    }

    final handler = routes[key] ?? routes['* $path'];
    final response = handler == null ? const FakeResponse.notFound() : handler(options);
    final body = response.body == null ? '' : jsonEncode(response.body);
    return ResponseBody.fromString(
      body,
      response.status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }
}

/// Canned payloads matching the contract (§1, §7).
class FakePayloads {
  FakePayloads._();

  static Map<String, dynamic> me({bool hasProfile = true}) => {
        'id': 1,
        'name': 'Demo Mavioh',
        'email': 'demo@mavioh.app',
        'email_verified_at': null,
        'created_at': '2026-01-01T10:00:00.000000Z',
        'updated_at': '2026-01-01T10:00:00.000000Z',
        'has_profile': hasProfile,
        'household_id': null,
        'consentement_sante': true,
        'settings': {'timezone': 'Europe/Paris', 'theme': 'systeme'},
      };

  static Map<String, dynamic> dashboard({bool hasProfile = true}) => {
        'data': {
          'date': '2026-09-16',
          'user': {'name': 'Demo Mavioh', 'first_name': 'Demo'},
          'has_profile': hasProfile,
          'targets': {'calories': 2130, 'proteins': 126, 'carbs': 280, 'fat': 56, 'fiber': 25, 'sugar': null, 'salt': 5, 'is_partial': false},
          'consumed': {'calories': '890.5', 'proteins': 40, 'carbs': 110, 'fat': 25, 'fiber': null, 'sugar': null, 'salt': null, 'is_partial': false},
          'remaining': {'calories': 1344.5, 'proteins': 86, 'carbs': 170, 'fat': 31, 'fiber': null, 'sugar': null, 'salt': null, 'is_partial': false},
          'progress_pct': 41.8,
          'calories_bonus': 105,
          'plancher_kcal': 1648,
          'is_estimate': true,
          'meals': [
            {'id': 10, 'type': 'petit_dejeuner', 'name': null, 'calories': 420, 'items_count': 3},
            {'id': 11, 'type': 'dejeuner', 'name': null, 'calories': 470.5, 'items_count': 2},
          ],
          'next_meal_type': 'diner',
          'stock': {
            'expiring_count': 2,
            'expired_count': 1,
            'low_count': 0,
            'expiring': [
              {'id': 5, 'label': 'Yaourt nature', 'expires_at': '2026-09-17', 'days_left': 1, 'stock_name': 'Frigo'},
            ],
          },
          'sport': {
            'sessions_today': [
              {'id': 3, 'title': 'Séance haut du corps', 'status': 'prevue', 'duration_min': 30, 'calories_burned': null},
            ],
            'calories_burned': 0,
            'planned': [],
            'week_minutes': 75,
            'week_sessions': 2,
            'streak_days': 1,
          },
          'recommendations': [
            {
              'id': 1,
              'date': '2026-09-16',
              'type': 'manque_proteines',
              'title': 'Un peu plus de protéines',
              'message': 'Il te manque environ 40 g de protéines.',
              'factors': ['restant : 86 g'],
              'actions': [
                {'kind': 'ajouter_au_repas', 'food_id': 7, 'quantity': 150, 'unit': 'g', 'meal_type': 'diner'},
              ],
              'priority': 2,
              'status': 'new',
              'is_estimate': true,
            },
          ],
          'weight': {
            'current': 81.4,
            'target': 76,
            'history': [
              {'date': '2026-09-10', 'weight_kg': 82.0},
              {'date': '2026-09-13', 'weight_kg': 81.7},
              {'date': '2026-09-16', 'weight_kg': 81.4},
            ],
            'variation_hebdo_kg': -0.39,
          },
          'notifications_unread': 3,
        },
      };

  static Map<String, dynamic> notifications() => {
        'data': [
          {'key': 'exp-5', 'type': 'peremption', 'title': 'Yaourt nature expire demain', 'message': '', 'date': '2026-09-16', 'read': false},
        ],
        'unread_count': 3,
      };

  static Map<String, dynamic> portions() => {
        'data': [
          {'unit': 'g', 'label': 'gramme', 'label_short': 'g', 'grams': 1, 'step': 10, 'is_estimate': false},
          {'unit': 'portion', 'label': 'portion', 'label_short': 'portion', 'grams': null, 'step': 0.5, 'is_estimate': true},
        ],
        'aliases': {},
      };
}
