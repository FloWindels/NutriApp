import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class AuthService {
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api',
  );

  final Dio _dio = Dio(
    BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 8),
      sendTimeout: const Duration(seconds: 8),
      receiveTimeout: const Duration(seconds: 12),
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    ),
  );

  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  String _extractErrorMessage(dynamic data, String fallback) {
    if (data is Map) {
      final message = data['message'];
      if (message != null && message.toString().trim().isNotEmpty) {
        return message.toString();
      }

      final errors = data['errors'];
      if (errors is Map) {
        final parts = <String>[];
        for (final value in errors.values) {
          if (value is Iterable) {
            parts.addAll(value.map((item) => item.toString()));
          } else if (value != null) {
            parts.add(value.toString());
          }
        }

        if (parts.isNotEmpty) {
          return parts.join(' ');
        }
      }
    }

    return fallback;
  }

  String _networkErrorMessage(DioException e, String fallback) {
    switch (e.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
        return 'Délai dépassé. Vérifie que le backend est démarré et accessible depuis ton téléphone.';
      case DioExceptionType.connectionError:
        return 'Serveur injoignable. Vérifie API_BASE_URL et que téléphone + PC sont sur le même réseau.';
      case DioExceptionType.badResponse:
        return _extractErrorMessage(e.response?.data, fallback);
      default:
        return _extractErrorMessage(e.response?.data, fallback);
    }
  }

  Future<Map<String, dynamic>> login({
    required String email,
    required String password,
  }) async {
    try {
      final response = await _dio.post(
        '/login',
        data: {
          'email': email,
          'password': password,
        },
      );

      final token = response.data['token'];
      final user = Map<String, dynamic>.from(response.data['user']);

      await _storage.write(key: 'token', value: token);

      return {
        'success': true,
        'token': token,
        'user': user,
      };
    } on DioException catch (e) {
      final message = _networkErrorMessage(e, 'Connexion impossible. Vérifie tes identifiants.');

      return {
        'success': false,
        'message': message,
      };
    } catch (_) {
      return {
        'success': false,
        'message': 'Une erreur inattendue est survenue.',
      };
    }
  }

  Future<Map<String, dynamic>> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) async {
    try {
      final response = await _dio.post(
        '/register',
        data: {
          'name': name,
          'email': email,
          'password': password,
          'password_confirmation': passwordConfirmation,
        },
      );

      final token = response.data['token'];
      final user = Map<String, dynamic>.from(response.data['user']);

      await _storage.write(key: 'token', value: token);

      return {
        'success': true,
        'token': token,
        'user': user,
      };
    } on DioException catch (e) {
      final message = _networkErrorMessage(e, 'Inscription impossible.');

      return {
        'success': false,
        'message': message,
      };
    } catch (_) {
      return {
        'success': false,
        'message': 'Une erreur inattendue est survenue.',
      };
    }
  }

  Future<String?> getToken() async {
    return _storage.read(key: 'token');
  }

  Future<void> logout() async {
    await _storage.delete(key: 'token');
  }
}