import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../screens/login_screen.dart';
import 'env.dart';
import 'session.dart';

/// Kind of failure surfaced to the UI.
enum ApiErrorKind {
  network,
  timeout,
  unauthorized,
  forbidden,
  notFound,
  validation,
  server,
  unknown,
}

/// Uniform exception thrown by every service call.
class ApiException implements Exception {
  final ApiErrorKind kind;
  final String message;
  final Map<String, List<String>> fieldErrors;
  final int? statusCode;

  const ApiException({
    required this.kind,
    required this.message,
    this.fieldErrors = const {},
    this.statusCode,
  });

  /// First error message for a given field, or null.
  String? fieldError(String field) {
    final list = fieldErrors[field];
    if (list == null || list.isEmpty) return null;
    return list.first;
  }

  bool get isNetwork => kind == ApiErrorKind.network || kind == ApiErrorKind.timeout;

  bool get isUnauthorized => kind == ApiErrorKind.unauthorized;

  /// Builds an [ApiException] from a [DioException], applying the French
  /// mapping of the contract (§16.1).
  factory ApiException.fromDio(DioException e) {
    switch (e.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
        return const ApiException(
          kind: ApiErrorKind.timeout,
          message: 'Le serveur met trop de temps à répondre.',
        );
      case DioExceptionType.connectionError:
      case DioExceptionType.unknown:
        if (e.response == null) {
          return const ApiException(
            kind: ApiErrorKind.network,
            message: 'Pas de connexion. Vérifie ton réseau puis réessaie.',
          );
        }
        return ApiException.fromResponse(e.response!.statusCode, e.response!.data);
      case DioExceptionType.badResponse:
        return ApiException.fromResponse(e.response?.statusCode, e.response?.data);
      case DioExceptionType.cancel:
        return const ApiException(kind: ApiErrorKind.unknown, message: 'Requête annulée.');
      case DioExceptionType.badCertificate:
        return const ApiException(
          kind: ApiErrorKind.network,
          message: 'Pas de connexion. Vérifie ton réseau puis réessaie.',
        );
    }
  }

  /// Maps an HTTP status + Laravel body to an [ApiException].
  factory ApiException.fromResponse(int? status, dynamic data) {
    final serverMessage = _extractMessage(data);
    final errors = _extractErrors(data);

    if (status == 401) {
      return ApiException(
        kind: ApiErrorKind.unauthorized,
        message: serverMessage ?? 'Non authentifié.',
        statusCode: status,
      );
    }
    if (status == 403) {
      return ApiException(
        kind: ApiErrorKind.forbidden,
        message: 'Action non autorisée.',
        statusCode: status,
      );
    }
    if (status == 404) {
      return ApiException(
        kind: ApiErrorKind.notFound,
        message: serverMessage ?? 'Introuvable.',
        statusCode: status,
      );
    }
    if (status == 422) {
      final fallback = errors.isNotEmpty ? errors.values.first.first : 'Vérifie les champs du formulaire.';
      return ApiException(
        kind: ApiErrorKind.validation,
        message: serverMessage ?? fallback,
        fieldErrors: errors,
        statusCode: status,
      );
    }
    if (status == 429) {
      return ApiException(
        kind: ApiErrorKind.server,
        message: serverMessage ?? 'Trop de requêtes, réessaie dans une minute.',
        statusCode: status,
      );
    }
    if (status != null && status >= 500) {
      return ApiException(
        kind: ApiErrorKind.server,
        message: status == 502 && serverMessage != null ? serverMessage : 'Le serveur est indisponible pour le moment.',
        statusCode: status,
      );
    }
    return ApiException(
      kind: ApiErrorKind.unknown,
      message: serverMessage ?? 'Une erreur est survenue.',
      fieldErrors: errors,
      statusCode: status,
    );
  }

  static String? _extractMessage(dynamic data) {
    if (data is Map) {
      final message = data['message'];
      if (message != null && message.toString().trim().isNotEmpty) {
        return message.toString();
      }
    }
    return null;
  }

  static Map<String, List<String>> _extractErrors(dynamic data) {
    final result = <String, List<String>>{};
    if (data is Map && data['errors'] is Map) {
      final errors = data['errors'] as Map;
      errors.forEach((key, value) {
        if (value is Iterable) {
          result[key.toString()] = value.map((e) => e.toString()).toList();
        } else if (value != null) {
          result[key.toString()] = [value.toString()];
        }
      });
    }
    return result;
  }

  @override
  String toString() => 'ApiException($kind, $statusCode): $message';
}

/// Single HTTP client of the app. Injectable for tests via [ApiClient.instance].
class ApiClient {
  ApiClient._internal(this._dio, this._storage) {
    _installInterceptors();
  }

  /// Creates a client bound to [Env.apiBaseUrl].
  factory ApiClient.production() {
    final dio = Dio(
      BaseOptions(
        baseUrl: Env.apiBaseUrl,
        connectTimeout: const Duration(seconds: 8),
        sendTimeout: const Duration(seconds: 8),
        receiveTimeout: const Duration(seconds: 15),
        headers: const {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      ),
    );
    return ApiClient._internal(dio, const FlutterSecureStorage());
  }

  /// Creates a client from a custom [Dio] (tests, mocks).
  @visibleForTesting
  factory ApiClient.withDio(Dio dio, {FlutterSecureStorage? storage, bool installInterceptors = true}) {
    if (!installInterceptors) {
      return ApiClient._raw(dio, storage ?? const FlutterSecureStorage());
    }
    return ApiClient._internal(dio, storage ?? const FlutterSecureStorage());
  }

  ApiClient._raw(this._dio, this._storage);

  static ApiClient? _override;
  static ApiClient? _instance;

  /// The shared client. Tests may set [ApiClient.instance] to a fake.
  static ApiClient get instance => _override ?? (_instance ??= ApiClient.production());

  static set instance(ApiClient? client) => _override = client;

  /// Restores the production client (tests).
  static void resetInstance() {
    _override = null;
    _instance = null;
  }

  final Dio _dio;
  final FlutterSecureStorage _storage;

  Dio get dio => _dio;

  static const String tokenKey = 'token';

  static const Set<String> _publicPaths = {
    '/login',
    '/register',
    '/forgot-password',
    '/reset-password',
  };

  bool _expiring = false;

  void _installInterceptors() {
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          try {
            final token = await _storage.read(key: tokenKey);
            if (token != null && token.isNotEmpty) {
              options.headers['Authorization'] = 'Bearer $token';
            }
          } catch (_) {
            // Secure storage unavailable (tests / web preview): continue without token.
          }
          handler.next(options);
        },
        onError: (error, handler) async {
          final status = error.response?.statusCode;
          final path = _pathOf(error.requestOptions);
          if (status == 401 && !_publicPaths.contains(path)) {
            await _handleExpiry();
          }
          handler.next(error);
        },
      ),
    );
  }

  String _pathOf(RequestOptions options) {
    var path = options.path;
    final base = options.baseUrl;
    if (path.startsWith('http')) {
      final uri = Uri.tryParse(path);
      path = uri?.path ?? path;
      final baseUri = Uri.tryParse(base);
      final basePath = baseUri?.path ?? '';
      if (basePath.isNotEmpty && path.startsWith(basePath)) {
        path = path.substring(basePath.length);
      }
    }
    if (!path.startsWith('/')) path = '/$path';
    return path;
  }

  Future<void> _handleExpiry() async {
    if (_expiring) return;
    _expiring = true;
    try {
      await Session.instance.expire();
      final navigator = AppNavigator.key.currentState;
      if (navigator != null) {
        navigator.pushAndRemoveUntil(
          MaterialPageRoute<void>(
            builder: (_) => const LoginScreen(
              initialMessage: 'Ta session a expiré, reconnecte-toi.',
            ),
          ),
          (route) => false,
        );
      }
    } finally {
      // Allow a later, genuine 401 (after a fresh login) to be handled again.
      Future<void>.delayed(const Duration(seconds: 2), () => _expiring = false);
    }
  }

  // ----- Token helpers ------------------------------------------------------

  Future<String?> readToken() async {
    try {
      return await _storage.read(key: tokenKey);
    } catch (_) {
      return null;
    }
  }

  Future<void> saveToken(String token) async {
    try {
      await _storage.write(key: tokenKey, value: token);
    } catch (_) {}
  }

  Future<void> clearToken() async {
    try {
      await _storage.delete(key: tokenKey);
    } catch (_) {}
  }

  Future<void> writeSecure(String key, String value) async {
    try {
      await _storage.write(key: key, value: value);
    } catch (_) {}
  }

  Future<String?> readSecure(String key) async {
    try {
      return await _storage.read(key: key);
    } catch (_) {
      return null;
    }
  }

  Future<void> deleteSecure(String key) async {
    try {
      await _storage.delete(key: key);
    } catch (_) {}
  }

  // ----- Typed helpers ------------------------------------------------------

  /// GET returning the JSON object body.
  Future<Map<String, dynamic>> getJson(
    String path, {
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) async {
    return _request(() => _dio.get<dynamic>(path, queryParameters: _cleanQuery(query), cancelToken: cancelToken));
  }

  /// POST returning the JSON object body.
  Future<Map<String, dynamic>> postJson(
    String path, {
    Object? body,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
    Duration? receiveTimeout,
  }) async {
    return _request(
      () => _dio.post<dynamic>(
        path,
        data: body,
        queryParameters: _cleanQuery(query),
        cancelToken: cancelToken,
        options: receiveTimeout == null ? null : Options(receiveTimeout: receiveTimeout),
      ),
    );
  }

  /// PUT returning the JSON object body.
  Future<Map<String, dynamic>> putJson(
    String path, {
    Object? body,
    Map<String, dynamic>? query,
  }) async {
    return _request(() => _dio.put<dynamic>(path, data: body, queryParameters: _cleanQuery(query)));
  }

  /// DELETE returning the JSON object body (may be empty).
  Future<Map<String, dynamic>> deleteJson(
    String path, {
    Object? body,
    Map<String, dynamic>? query,
  }) async {
    return _request(() => _dio.delete<dynamic>(path, data: body, queryParameters: _cleanQuery(query)));
  }

  /// GET returning `data` as a list of maps.
  Future<List<Map<String, dynamic>>> getList(String path, {Map<String, dynamic>? query}) async {
    final json = await getJson(path, query: query);
    return asList(json['data']);
  }

  Future<Map<String, dynamic>> _request(Future<Response<dynamic>> Function() call) async {
    try {
      final response = await call();
      return asMap(response.data);
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(kind: ApiErrorKind.unknown, message: 'Une erreur est survenue.');
    }
  }

  Map<String, dynamic>? _cleanQuery(Map<String, dynamic>? query) {
    if (query == null) return null;
    final cleaned = <String, dynamic>{};
    query.forEach((key, value) {
      if (value == null) return;
      if (value is bool) {
        cleaned[key] = value ? 1 : 0;
      } else {
        cleaned[key] = value;
      }
    });
    return cleaned.isEmpty ? null : cleaned;
  }

  // ----- Defensive parsing ---------------------------------------------------

  /// Converts any JSON value to a `Map<String, dynamic>` (empty when not a map).
  static Map<String, dynamic> asMap(dynamic value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return <String, dynamic>{};
  }

  /// Converts any JSON value to a list of maps (skips non-map entries).
  static List<Map<String, dynamic>> asList(dynamic value) {
    if (value is List) {
      return value.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
    }
    return const [];
  }

  /// Converts a JSON list to a list of strings.
  static List<String> asStringList(dynamic value) {
    if (value is List) {
      return value.where((e) => e != null).map((e) => e.toString()).toList();
    }
    if (value is String && value.isNotEmpty) return [value];
    return const [];
  }
}

// ----- Standalone parsers (decimals may arrive as strings) -------------------

/// Parses a number defensively (`12`, `12.5`, `"12,5"`, `null`).
double? parseNum(dynamic value) {
  if (value == null) return null;
  if (value is num) return value.toDouble();
  if (value is bool) return value ? 1 : 0;
  final text = value.toString().trim().replaceAll(' ', '').replaceAll(' ', '');
  if (text.isEmpty || text.toLowerCase() == 'null') return null;
  return double.tryParse(text.replaceAll(',', '.'));
}

/// Same as [parseNum] with a default.
double parseNumOr(dynamic value, double fallback) => parseNum(value) ?? fallback;

/// Parses an integer defensively (`3`, `3.0`, `"3"`).
int? parseInt(dynamic value) {
  if (value == null) return null;
  if (value is int) return value;
  if (value is num) return value.round();
  if (value is bool) return value ? 1 : 0;
  final text = value.toString().trim();
  if (text.isEmpty || text.toLowerCase() == 'null') return null;
  return int.tryParse(text) ?? double.tryParse(text.replaceAll(',', '.'))?.round();
}

/// Same as [parseInt] with a default.
int parseIntOr(dynamic value, int fallback) => parseInt(value) ?? fallback;

/// Parses a boolean defensively (`true`, `1`, `"1"`, `"true"`, `"oui"`).
bool parseBool(dynamic value, {bool fallback = false}) {
  if (value == null) return fallback;
  if (value is bool) return value;
  if (value is num) return value != 0;
  final text = value.toString().trim().toLowerCase();
  if (text == 'true' || text == '1' || text == 'oui' || text == 'yes') return true;
  if (text == 'false' || text == '0' || text == 'non' || text == 'no' || text.isEmpty) return false;
  return fallback;
}

/// Parses an ISO date/datetime string to a local [DateTime] (null when invalid).
DateTime? parseDate(dynamic value) {
  if (value == null) return null;
  if (value is DateTime) return value;
  final text = value.toString().trim();
  if (text.isEmpty || text.toLowerCase() == 'null') return null;
  final parsed = DateTime.tryParse(text);
  if (parsed == null) return null;
  // Date-only strings (Y-m-d) are kept as local midnight; datetimes are converted to local.
  if (text.length <= 10) return DateTime(parsed.year, parsed.month, parsed.day);
  return parsed.toLocal();
}

/// Parses a string defensively (null for empty / `"null"`).
String? parseString(dynamic value) {
  if (value == null) return null;
  final text = value.toString();
  if (text.trim().isEmpty || text.trim().toLowerCase() == 'null') return null;
  return text;
}
