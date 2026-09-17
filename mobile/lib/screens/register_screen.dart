import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/me.dart';
import '../services/auth_service.dart';
import '../theme/app_theme.dart';
import '../widgets/status_banner.dart';
import 'home_screen.dart';
import 'login_screen.dart';

/// Inscription.
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final _passwordConfirmationController = TextEditingController();
  final AuthService _authService = AuthService();

  bool _isLoading = false;
  bool _obscure = true;
  String? _errorMessage;
  Map<String, String> _fieldErrors = {};

  static final RegExp _emailRegex = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _passwordController.dispose();
    _passwordConfirmationController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _errorMessage = null;
      _fieldErrors = {};
    });
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isLoading = true);
    try {
      // Passwords are never trimmed.
      final result = await _authService.register(
        name: _nameController.text.trim(),
        email: _emailController.text.trim(),
        password: _passwordController.text,
        passwordConfirmation: _passwordConfirmationController.text,
      );
      if (!mounted) return;
      await _routeAfterAuth(result.user);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _isLoading = false;
        _fieldErrors = {for (final entry in e.fieldErrors.entries) entry.key: entry.value.first};
        _errorMessage = _fieldErrors.isEmpty ? e.message : null;
      });
    }
  }

  /// New accounts have no profile yet → Plus › Profil with the banner.
  Future<void> _routeAfterAuth(Me user) async {
    Me me = user;
    try {
      me = await Session.instance.refreshMe();
    } on ApiException {
      // Keep the register payload (has_profile defaults to false).
    }
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute<void>(
        builder: (_) => me.hasProfile ? const HomeScreen() : const HomeScreen(initialTab: 4, initialPlusSlug: 'profil'),
      ),
      (route) => false,
    );
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
            colors: [Color(0xFFDBEAFE), Color(0xFFF8FAFC), Color(0xFFF0FDFA)],
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
                    color: Colors.white.withValues(alpha: 0.93),
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
                              'Créer ton espace ${AppStrings.brand}',
                              textAlign: TextAlign.center,
                              style: TextStyle(fontSize: titleSize, height: 1.2, fontWeight: FontWeight.w700, color: MaviohColors.text),
                            ),
                            const SizedBox(height: 8),
                            const Text(
                              'Un compte pour suivre tes repas, ton stock et tes séances, simplement.',
                              textAlign: TextAlign.center,
                              style: TextStyle(color: MaviohColors.muted),
                            ),
                            const SizedBox(height: 24),
                            TextFormField(
                              controller: _nameController,
                              textCapitalization: TextCapitalization.words,
                              autofillHints: const [AutofillHints.name],
                              textInputAction: TextInputAction.next,
                              decoration: InputDecoration(
                                labelText: 'Nom',
                                prefixIcon: const Icon(Icons.badge_outlined),
                                errorText: _fieldErrors['name'],
                              ),
                              validator: (value) {
                                if (value == null || value.trim().isEmpty) return 'Entre ton nom';
                                return null;
                              },
                            ),
                            const SizedBox(height: 14),
                            TextFormField(
                              controller: _emailController,
                              keyboardType: TextInputType.emailAddress,
                              autocorrect: false,
                              autofillHints: const [AutofillHints.email],
                              textInputAction: TextInputAction.next,
                              decoration: InputDecoration(
                                labelText: 'Email',
                                prefixIcon: const Icon(Icons.mail_outline),
                                errorText: _fieldErrors['email'],
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
                              autofillHints: const [AutofillHints.newPassword],
                              textInputAction: TextInputAction.next,
                              decoration: InputDecoration(
                                labelText: 'Mot de passe',
                                helperText: '8 caractères minimum',
                                prefixIcon: const Icon(Icons.lock_outline),
                                errorText: _fieldErrors['password'],
                                suffixIcon: IconButton(
                                  tooltip: _obscure ? 'Afficher le mot de passe' : 'Masquer le mot de passe',
                                  onPressed: () => setState(() => _obscure = !_obscure),
                                  icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                                ),
                              ),
                              validator: (value) {
                                if (value == null || value.isEmpty) return 'Entre un mot de passe';
                                if (value.length < 8) return 'Minimum 8 caractères';
                                return null;
                              },
                            ),
                            const SizedBox(height: 14),
                            TextFormField(
                              controller: _passwordConfirmationController,
                              obscureText: _obscure,
                              autofillHints: const [AutofillHints.newPassword],
                              textInputAction: TextInputAction.done,
                              onFieldSubmitted: (_) => _isLoading ? null : _submit(),
                              decoration: InputDecoration(
                                labelText: 'Confirmation du mot de passe',
                                prefixIcon: const Icon(Icons.verified_user_outlined),
                                errorText: _fieldErrors['password_confirmation'],
                              ),
                              validator: (value) {
                                if (value == null || value.isEmpty) return 'Confirme ton mot de passe';
                                if (value != _passwordController.text) return 'Les mots de passe ne correspondent pas';
                                return null;
                              },
                            ),
                            const SizedBox(height: 14),
                            if (_errorMessage != null)
                              StatusBanner.error(_errorMessage!, margin: const EdgeInsets.only(bottom: 14)),
                            ElevatedButton(
                              onPressed: _isLoading ? null : _submit,
                              style: ElevatedButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 15)),
                              child: Text(_isLoading ? 'Création…' : 'Créer mon compte'),
                            ),
                            const SizedBox(height: 10),
                            TextButton(
                              onPressed: _isLoading
                                  ? null
                                  : () => Navigator.of(context).pushReplacement(
                                        MaterialPageRoute<void>(builder: (_) => const LoginScreen()),
                                      ),
                              child: const Text('Déjà un compte ? Se connecter'),
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
