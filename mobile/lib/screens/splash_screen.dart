import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/me.dart';
import '../theme/app_theme.dart';
import '../widgets/error_state.dart';
import 'home_screen.dart';
import 'login_screen.dart';

/// Logo + spinner; decides between Login and Home (§16.1).
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _bootstrap());
  }

  Future<void> _bootstrap() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final api = ApiClient.instance;
    final token = await api.readToken();
    if (!mounted) return;
    if (token == null || token.isEmpty) {
      _goLogin();
      return;
    }
    try {
      final me = Me.fromJson(await api.getJson('/me'));
      if (!mounted) return;
      Session.instance.hydrate(me);
      // Portions catalogue in the background (failure tolerated).
      Session.instance.loadPortions();
      _goHome(me);
    } on ApiException catch (e) {
      if (!mounted) return;
      if (e.kind == ApiErrorKind.unauthorized) {
        await Session.instance.expire();
        if (!mounted) return;
        // The API client may already have pushed the Login screen.
        if (ModalRoute.of(context)?.isCurrent ?? true) _goLogin();
        return;
      }
      setState(() {
        _loading = false;
        _error = AppStrings.errorServerUnreachable;
      });
    }
  }

  void _goLogin({String? message}) {
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute<void>(builder: (_) => LoginScreen(initialMessage: message)),
      (route) => false,
    );
  }

  void _goHome(Me me) {
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute<void>(
        builder: (_) => me.hasProfile ? const HomeScreen() : const HomeScreen(initialTab: 4, initialPlusSlug: 'profil'),
      ),
      (route) => false,
    );
  }

  Future<void> _logout() async {
    await ApiClient.instance.clearToken();
    await Session.instance.expire();
    if (!mounted) return;
    _goLogin();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: MaviohColors.background,
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 96,
                  height: 96,
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF0FDF4),
                    borderRadius: BorderRadius.circular(28),
                    border: Border.all(color: MaviohColors.border),
                  ),
                  child: Image.asset('assets/images/logoMoh.png', fit: BoxFit.contain),
                ),
                const SizedBox(height: 18),
                const Text(
                  AppStrings.brand,
                  style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: MaviohColors.text),
                ),
                const SizedBox(height: 6),
                const Text(AppStrings.tagline, textAlign: TextAlign.center, style: TextStyle(color: MaviohColors.muted)),
                const SizedBox(height: 32),
                if (_loading)
                  const SizedBox(width: 28, height: 28, child: CircularProgressIndicator(strokeWidth: 2.6))
                else if (_error != null)
                  ErrorState(
                    message: _error!,
                    onRetry: _bootstrap,
                    secondaryLabel: 'Se déconnecter',
                    onSecondary: _logout,
                    compact: true,
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
