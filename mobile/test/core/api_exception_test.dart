import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mavioh/core/api_client.dart';

import '../support/fake_api_client.dart';

void main() {
  final options = RequestOptions(path: '/x');

  group('ApiException.fromDio', () {
    test('timeouts → timeout kind with French message', () {
      for (final type in [DioExceptionType.connectionTimeout, DioExceptionType.sendTimeout, DioExceptionType.receiveTimeout]) {
        final e = ApiException.fromDio(DioException(requestOptions: options, type: type));
        expect(e.kind, ApiErrorKind.timeout);
        expect(e.message, 'Le serveur met trop de temps à répondre.');
      }
    });

    test('connection error → network kind', () {
      final e = ApiException.fromDio(DioException.connectionError(requestOptions: options, reason: 'x'));
      expect(e.kind, ApiErrorKind.network);
      expect(e.message, 'Pas de connexion. Vérifie ton réseau puis réessaie.');
      expect(e.isNetwork, isTrue);
    });

    test('unknown without response → network kind', () {
      final e = ApiException.fromDio(DioException(requestOptions: options, type: DioExceptionType.unknown));
      expect(e.kind, ApiErrorKind.network);
    });
  });

  group('ApiException.fromResponse', () {
    test('401', () {
      final e = ApiException.fromResponse(401, {'message': 'Non authentifié.'});
      expect(e.kind, ApiErrorKind.unauthorized);
      expect(e.statusCode, 401);
      expect(e.isUnauthorized, isTrue);
    });

    test('403 always uses the fixed French message', () {
      final e = ApiException.fromResponse(403, {'message': 'Forbidden.'});
      expect(e.kind, ApiErrorKind.forbidden);
      expect(e.message, 'Action non autorisée.');
    });

    test('404 uses the server message or Introuvable.', () {
      expect(ApiException.fromResponse(404, {'message': 'Produit introuvable, même sur Open Food Facts.'}).message,
          'Produit introuvable, même sur Open Food Facts.');
      expect(ApiException.fromResponse(404, null).message, 'Introuvable.');
      expect(ApiException.fromResponse(404, null).kind, ApiErrorKind.notFound);
    });

    test('422 exposes message and field errors', () {
      final e = ApiException.fromResponse(422, {
        'message': 'Les données sont invalides.',
        'errors': {
          'email': ['Identifiants invalides.'],
          'poids_souhaite_kg': ['Le poids souhaité doit être inférieur au poids actuel pour un objectif de perte.', 'Autre'],
        },
      });
      expect(e.kind, ApiErrorKind.validation);
      expect(e.message, 'Les données sont invalides.');
      expect(e.fieldError('email'), 'Identifiants invalides.');
      expect(e.fieldErrors['poids_souhaite_kg']!.length, 2);
      expect(e.fieldError('inconnu'), isNull);
    });

    test('422 without message falls back to the first field error', () {
      final e = ApiException.fromResponse(422, {'errors': {'name': ['Le nom est requis.']}});
      expect(e.message, 'Le nom est requis.');
    });

    test('429', () {
      final e = ApiException.fromResponse(429, {'message': 'Trop de requêtes, réessaie dans une minute.'});
      expect(e.kind, ApiErrorKind.server);
      expect(e.message, 'Trop de requêtes, réessaie dans une minute.');
    });

    test('5xx → server unavailable; 502 keeps the OFF message', () {
      expect(ApiException.fromResponse(500, {'message': 'Whoops'}).message, 'Le serveur est indisponible pour le moment.');
      expect(ApiException.fromResponse(503, null).kind, ApiErrorKind.server);
      expect(ApiException.fromResponse(502, {'message': 'Open Food Facts indisponible.'}).message, 'Open Food Facts indisponible.');
    });

    test('other statuses → unknown with server message or generic', () {
      expect(ApiException.fromResponse(418, {'message': 'Théière.'}).message, 'Théière.');
      expect(ApiException.fromResponse(418, null).message, 'Une erreur est survenue.');
      expect(ApiException.fromResponse(418, null).kind, ApiErrorKind.unknown);
    });
  });

  group('defensive parsers', () {
    test('parseNum accepts numbers, numeric strings, comma decimals and rejects garbage', () {
      expect(parseNum(12), 12.0);
      expect(parseNum(12.5), 12.5);
      expect(parseNum('12.5'), 12.5);
      expect(parseNum('12,5'), 12.5);
      expect(parseNum('1 250'), 1250.0);
      expect(parseNum(null), isNull);
      expect(parseNum('null'), isNull);
      expect(parseNum('abc'), isNull);
      expect(parseNumOr('abc', 3), 3);
    });

    test('parseInt', () {
      expect(parseInt(3), 3);
      expect(parseInt(3.0), 3);
      expect(parseInt('3'), 3);
      expect(parseInt('3.6'), 4);
      expect(parseInt(null), isNull);
      expect(parseIntOr('x', 7), 7);
    });

    test('parseBool', () {
      expect(parseBool(true), isTrue);
      expect(parseBool(1), isTrue);
      expect(parseBool('1'), isTrue);
      expect(parseBool('true'), isTrue);
      expect(parseBool(0), isFalse);
      expect(parseBool('false'), isFalse);
      expect(parseBool(null), isFalse);
      expect(parseBool(null, fallback: true), isTrue);
    });

    test('parseDate handles Y-m-d and ISO datetimes', () {
      expect(parseDate('2026-09-16'), DateTime(2026, 9, 16));
      expect(parseDate('2026-09-16T10:00:00.000000Z'), isNotNull);
      expect(parseDate(null), isNull);
      expect(parseDate('nope'), isNull);
    });

    test('parseString treats empty and "null" as null', () {
      expect(parseString('a'), 'a');
      expect(parseString(''), isNull);
      expect(parseString('null'), isNull);
      expect(parseString(null), isNull);
    });
  });

  group('ApiClient typed helpers with fake transport', () {
    test('getJson returns the JSON map', () async {
      final api = FakeApiClient.build({
        'GET /me': (_) => FakeResponse(FakePayloads.me()),
      });
      final json = await api.getJson('/me');
      expect(json['name'], 'Demo Mavioh');
    });

    test('non-2xx responses throw ApiException with the mapped kind', () async {
      final api = FakeApiClient.build({
        'GET /foods/barcode/1': (_) => const FakeResponse.notFound(),
        'POST /profile': (_) => const FakeResponse({'message': 'Invalide', 'errors': {'age': ['Requis.']}}, status: 422),
      });
      await expectLater(api.getJson('/foods/barcode/1'), throwsA(isA<ApiException>().having((e) => e.kind, 'kind', ApiErrorKind.notFound)));
      await expectLater(
        api.postJson('/profile', body: {}),
        throwsA(isA<ApiException>().having((e) => e.fieldError('age'), 'age', 'Requis.')),
      );
    });

    test('network failure throws network ApiException', () async {
      final api = FakeApiClient.offline();
      await expectLater(api.getJson('/dashboard'), throwsA(isA<ApiException>().having((e) => e.kind, 'kind', ApiErrorKind.network)));
    });

    test('query booleans are sent as 1/0 and nulls dropped', () async {
      RequestOptions? seen;
      final api = FakeApiClient.build({
        'GET /foods/search': (req) {
          seen = req;
          return const FakeResponse({'data': []});
        },
      });
      await api.getJson('/foods/search', query: {'q': 'riz', 'off': true, 'page': null});
      expect(seen!.uri.queryParameters['off'], '1');
      expect(seen!.uri.queryParameters.containsKey('page'), isFalse);
    });
  });
}
