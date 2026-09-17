import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/me.dart';
import '../services/auth_service.dart';
import '../theme/app_theme.dart';
import '../widgets/status_banner.dart';
import 'home_screen.dart';
import 'register_screen.dart';

/// Connexion. [initialMessage] is shown as an info banner (e.g. session expirée).
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, this.initialMessage});

  final String? initialMessage;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final AuthService _authService = AuthService();

  bool _isLoading = false;
  bool _obscure = true;
  String? _errorMessage;
  String? _infoMessage;
  String? _emailError;

  static final RegExp _emailRegex = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

  @override
  void initState() {
    super.initState();
    _infoMessage = widget.initialMessage;
  }

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _emailError = null;
      _errorMessage = null;
      _infoMessage = null;
    });
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isLoading = true);
    try {
      // Passwords are never trimmed.
      final result = await _authService.login(
        email: _emailController.text.trim(),
        password: _passwordController.text,
      );
      if (!mounted) return;
      await _routeAfterAuth(result.user);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _isLoading = false;
        _emailError = e.fieldError('email');
        _errorMessage = e.kind == ApiErrorKind.validation && _emailError != null ? null : e.message;
      });
    }
  }

  /// Post-auth routing: refresh `/me` for `has_profile`, then HomeScreen (first-run rule).
  Future<void> _routeAfterAuth(Me user) async {
    Me me = user;
    try {
      me = await Session.instance.refreshMe();
    } on ApiException {
      // Keep the login payload; the dashboard will refresh has_profile.
    }
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute<void>(
        builder: (_) => me.hasProfile ? const HomeScreen() : const HomeScreen(initialTab: 4, initialPlusSlug: 'profil'),
      ),
      (route) => false,
    );
  }

  Future<void> _forgotPassword() async {
    final email = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _ForgotPasswordSheet(initialEmail: _emailController.text.trim(), service: _authService),
    );
    if (email != null && mounted) {
      setState(() => _infoMessage = 'Si un compte existe pour $email, un lien de réinitialisation a été envoyé.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    final isCompact = width < 360;
    final horizontalPadding = isCompact ? 14.0 : 20.0;
    final cardPadding = isCompact ? 18.0 : 24.0;
    final logoSize = isCompact ? 62.0 : 74.0;
    final titleSize = isCompact ? 23.0 : 26.0;

    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [Color(0xFFE0F2FE), Color(0xFFF8FAFC), Color(0xFFECFEFF)],
          ),
        ),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: EdgeInsets.symmetric(horizontal: horizontalPadding, vertical: 20),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 430),
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.92),
                    borderRadius: BorderRadius.circular(isCompact ? 22 : 28),
                    border: Border.all(color: Colors.white, width: 1.2),
                    boxShadow: const [
                      BoxShadow(color: Color(0x1A0F172A), blurRadius: 28, offset: Offset(0, 16)),
                    ],
                  ),
                  child: Padding(
                    padding: EdgeInsets.fromLTRB(cardPadding, 24, cardPadding, 20),
                    child: AutofillGroup(
                      child: Form(
                        key: _formKey,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Center(
                              child: Container(
                                width: logoSize,
                                height: logoSize,
                                padding: const EdgeInsets.all(10),
                                decoration: BoxDecoration(
                                  color: const Color(0xFFF0FDF4),
                                  borderRadius: BorderRadius.circular(isCompact ? 18 : 22),
                                ),
                                child: Image.asset('assets/images/logoMoh.png', fit: BoxFit.contain),
                              ),
                            ),
                            const SizedBox(height: 14),
                            Text(
                              AppStrings.brand,
                              textAlign: TextAlign.center,
                              style: TextStyle(fontSize: titleSize, height: 1.2, fontWeight: FontWeight.w700, color: MaviohColors.text),
                            ),
                            const SizedBox(height: 8),
                            const Text(
                              AppStrings.tagline,
                              textAlign: TextAlign.center,
                              style: TextStyle(color: MaviohColors.muted),
                            ),
                            const SizedBox(height: 26),
                            if (_infoMessage != null)
                              StatusBanner.info(
                                _infoMessage!,
                                margin: const EdgeInsets.only(bottom: 14),
                                onClose: () => setState(() => _infoMessage = null),
                              ),
                            TextFormField(
                              controller: _emailController,
                              keyboardType: TextInputType.emailAddress,
                              autocorrect: false,
                              autofillHints: const [AutofillHints.username, AutofillHints.email],
                              textInputAction: TextInputAction.next,
                              decoration: InputDecoration(
                                labelText: 'Email',
                                prefixIcon: const Icon(Icons.mail_outline),
                                errorText: _emailError,
                              ),
                              validator: (value) {
                                final text = value?.trim() ?? '';
                                if (text.isEmpty) return 'Entre ton email';
                                if (!_emailRegex.hasMatch(text)) return 'Adresse email invalide';
                                return null;
                              },
                            ),
                            const SizedBox(height: 14),
                            TextFormField(
                              controller: _passwordController,
                              obscureText: _obscure,
                              autofillHints: const [AutofillHints.password],
                              textInputAction: TextInputAction.done,
                              onFieldSubmitted: (_) => _isLoading ? null : _submit(),
                              decoration: InputDecoration(
                                labelText: 'Mot de passe',
                                prefixIcon: const Icon(Icons.lock_outline),
                                suffixIcon: IconButton(
                                  tooltip: _obscure ? 'Afficher le mot de passe' : 'Masquer le mot de passe',
                                  onPressed: () => setState(() => _obscure = !_obscure),
                                  icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                                ),
                              ),
                              validator: (value) {
                                if (value == null || value.isEmpty) return 'Entre ton mot de passe';
                                return null;
                              },
                            ),
                            Align(
                              alignment: Alignment.centerRight,
                              child: TextButton(
                                onPressed: _isLoading ? null : _forgotPassword,
                                child: const Text('Mot de passe oublié ?'),
                              ),
                            ),
                            if (_errorMessage != null)
                              StatusBanner.error(_errorMessage!, margin: const EdgeInsets.only(bottom: 14)),
                            ElevatedButton(
                              onPressed: _isLoading ? null : _submit,
                              style: ElevatedButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 15)),
                              child: Text(_isLoading ? 'Connexion…' : 'Se connecter'),
                            ),
                            const SizedBox(height: 10),
                            TextButton(
                              onPressed: _isLoading
                                  ? null
                                  : () => Navigator.of(context).push(
                                        MaterialPageRoute<void>(builder: (_) => const RegisterScreen()),
                                      ),
                              child: const Text('Créer un compte'),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ForgotPasswordSheet extends StatefulWidget {
  final String initialEmail;
  final AuthService service;

  const _ForgotPasswordSheet({required this.initialEmail, required this.service});

  @override
  State<_ForgotPasswordSheet> createState() => _ForgotPasswordSheetState();
}

class _ForgotPasswordSheetState extends State<_ForgotPasswordSheet> {
  late final TextEditingController _controller = TextEditingController(text: widget.initialEmail);
  bool _sending = false;
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final email = _controller.text.trim();
    if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(email)) {
      setState(() => _error = 'Adresse email invalide');
      return;
    }
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      await widget.service.forgotPassword(email);
      if (!mounted) return;
      Navigator.of(context).pop(email);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _sending = false;
        _error = e.fieldError('email') ?? e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final inset = MediaQuery.viewInsetsOf(context).bottom;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 4, 20, 20 + inset),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('Mot de passe oublié', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text)),
          const SizedBox(height: 6),
          const Text(
            'Indique ton email : si un compte existe, tu recevras un lien pour choisir un nouveau mot de passe.',
            style: TextStyle(color: MaviohColors.muted, height: 1.4),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _controller,
            autofocus: true,
            keyboardType: TextInputType.emailAddress,
            autocorrect: false,
            decoration: InputDecoration(labelText: 'Email', prefixIcon: const Icon(Icons.mail_outline), errorText: _error),
            onSubmitted: (_) => _send(),
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _sending ? null : _send,
            child: Text(_sending ? 'Envoi…' : 'Envoyer le lien'),
          ),
        ],
      ),
    );
  }
}
