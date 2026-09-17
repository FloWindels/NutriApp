import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/strings.dart';
import '../../services/auth_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/status_banner.dart';

EdgeInsets _sheetPadding(BuildContext context) =>
    EdgeInsets.fromLTRB(20, 4, 20, 20 + MediaQuery.viewInsetsOf(context).bottom);

const TextStyle _sheetTitle = TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text);
const TextStyle _sheetCaption = TextStyle(color: MaviohColors.muted, height: 1.4, fontSize: 13);

/// Feuille « Nom et email » (`PUT /account`). Ne se ferme jamais avant un 2xx.
class AccountEditSheet extends StatefulWidget {
  const AccountEditSheet({super.key, required this.initialName, required this.initialEmail});

  final String initialName;
  final String initialEmail;

  /// Renvoie `true` quand le compte a été enregistré.
  static Future<bool> show(BuildContext context, {required String name, required String email}) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => AccountEditSheet(initialName: name, initialEmail: email),
    );
    return saved ?? false;
  }

  @override
  State<AccountEditSheet> createState() => _AccountEditSheetState();
}

class _AccountEditSheetState extends State<AccountEditSheet> {
  late final TextEditingController _name = TextEditingController(text: widget.initialName);
  late final TextEditingController _email = TextEditingController(text: widget.initialEmail);
  bool _busy = false;
  String? _error;
  String? _nameError;
  String? _emailError;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final name = _name.text.trim();
    final email = _email.text.trim();
    setState(() {
      _nameError = name.isEmpty ? 'Indique ton nom.' : null;
      _emailError = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(email) ? null : 'Adresse email invalide';
    });
    if (_nameError != null || _emailError != null) return;

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await AuthService().updateAccount(name: name, email: email);
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = e.message;
        _nameError = e.fieldError('name');
        _emailError = e.fieldError('email');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: _sheetPadding(context),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('Nom et email', style: _sheetTitle),
          const SizedBox(height: 6),
          const Text('Ton nom apparaît dans l’application ; ton email sert à te connecter.', style: _sheetCaption),
          if (_error != null)
            StatusBanner.error(_error!, margin: const EdgeInsets.only(top: 14)),
          const SizedBox(height: 14),
          TextField(
            controller: _name,
            textCapitalization: TextCapitalization.words,
            autofillHints: const [AutofillHints.name],
            decoration: InputDecoration(labelText: 'Nom', errorText: _nameError),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            autofillHints: const [AutofillHints.email],
            decoration: InputDecoration(labelText: 'Email', errorText: _emailError),
            onSubmitted: (_) => _submit(),
          ),
          const SizedBox(height: 18),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.2))
                : const Text(AppStrings.save),
          ),
        ],
      ),
    );
  }
}

/// Feuille « Changer le mot de passe » (`PUT /account/password`).
class PasswordChangeSheet extends StatefulWidget {
  const PasswordChangeSheet({super.key});

  /// Renvoie le message serveur en cas de succès.
  static Future<String?> show(BuildContext context) {
    return showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => const PasswordChangeSheet(),
    );
  }

  @override
  State<PasswordChangeSheet> createState() => _PasswordChangeSheetState();
}

class _PasswordChangeSheetState extends State<PasswordChangeSheet> {
  final _current = TextEditingController();
  final _password = TextEditingController();
  final _confirmation = TextEditingController();
  bool _obscure = true;
  bool _busy = false;
  String? _error;
  String? _currentError;
  String? _passwordError;
  String? _confirmationError;

  @override
  void dispose() {
    _current.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _currentError = _current.text.isEmpty ? 'Indique ton mot de passe actuel.' : null;
      _passwordError = _password.text.length < 8 ? 'Utilise au moins 8 caractères.' : null;
      _confirmationError = _confirmation.text == _password.text ? null : 'Les deux mots de passe ne sont pas identiques.';
    });
    if (_currentError != null || _passwordError != null || _confirmationError != null) return;

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final message = await AuthService().changePassword(
        currentPassword: _current.text,
        password: _password.text,
        passwordConfirmation: _confirmation.text,
      );
      if (!mounted) return;
      Navigator.of(context).pop(message);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = e.message;
        _currentError = e.fieldError('current_password');
        _passwordError = e.fieldError('password');
        _confirmationError = e.fieldError('password_confirmation');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: _sheetPadding(context),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('Changer le mot de passe', style: _sheetTitle),
          const SizedBox(height: 6),
          const Text('Tes autres appareils seront déconnectés.', style: _sheetCaption),
          if (_error != null)
            StatusBanner.error(_error!, margin: const EdgeInsets.only(top: 14)),
          const SizedBox(height: 14),
          TextField(
            controller: _current,
            obscureText: _obscure,
            autofillHints: const [AutofillHints.password],
            decoration: InputDecoration(
              labelText: 'Mot de passe actuel',
              errorText: _currentError,
              suffixIcon: IconButton(
                tooltip: _obscure ? 'Afficher les mots de passe' : 'Masquer les mots de passe',
                onPressed: () => setState(() => _obscure = !_obscure),
                icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
              ),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _password,
            obscureText: _obscure,
            autofillHints: const [AutofillHints.newPassword],
            decoration: InputDecoration(labelText: 'Nouveau mot de passe', errorText: _passwordError),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _confirmation,
            obscureText: _obscure,
            autofillHints: const [AutofillHints.newPassword],
            decoration: InputDecoration(labelText: 'Confirme le nouveau mot de passe', errorText: _confirmationError),
            onSubmitted: (_) => _submit(),
          ),
          const SizedBox(height: 18),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.2))
                : const Text('Modifier le mot de passe'),
          ),
        ],
      ),
    );
  }
}

/// Feuille « Supprimer mon compte » (`DELETE /account`). Renvoie `true` après un 2xx.
class DeleteAccountSheet extends StatefulWidget {
  const DeleteAccountSheet({super.key});

  static Future<bool> show(BuildContext context) async {
    final done = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => const DeleteAccountSheet(),
    );
    return done ?? false;
  }

  @override
  State<DeleteAccountSheet> createState() => _DeleteAccountSheetState();
}

class _DeleteAccountSheetState extends State<DeleteAccountSheet> {
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;
  String? _passwordError;

  @override
  void dispose() {
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_password.text.isEmpty) {
      setState(() => _passwordError = 'Indique ton mot de passe.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _passwordError = null;
    });
    try {
      await AuthService().deleteAccount(_password.text);
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = e.message;
        _passwordError = e.fieldError('password');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: _sheetPadding(context),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('Supprimer mon compte', style: _sheetTitle),
          const SizedBox(height: 6),
          const Text(
            'Tes repas, ton stock, tes séances et ton profil seront supprimés définitivement. '
            'Confirme avec ton mot de passe.',
            style: _sheetCaption,
          ),
          if (_error != null)
            StatusBanner.error(_error!, margin: const EdgeInsets.only(top: 14)),
          const SizedBox(height: 14),
          TextField(
            controller: _password,
            obscureText: true,
            decoration: InputDecoration(labelText: 'Mot de passe', errorText: _passwordError),
            onSubmitted: (_) => _submit(),
          ),
          const SizedBox(height: 18),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: MaviohColors.error),
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.2))
                : const Text('Supprimer définitivement'),
          ),
          const SizedBox(height: 6),
          TextButton(
            onPressed: _busy ? null : () => Navigator.of(context).pop(false),
            child: const Text(AppStrings.cancel),
          ),
        ],
      ),
    );
  }
}

/// Dialogue « Exporter mes données » : le JSON complet, sélectionnable et copiable.
class ExportDataDialog extends StatelessWidget {
  const ExportDataDialog({super.key, required this.json});

  final String json;

  static Future<void> show(BuildContext context, Map<String, dynamic> data) {
    final pretty = const JsonEncoder.withIndent('  ').convert(data);
    return showDialog<void>(
      context: context,
      builder: (_) => ExportDataDialog(json: pretty),
    );
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      icon: const Icon(Icons.download_outlined, color: MaviohColors.primary, size: 28),
      title: const Text('Mes données'),
      content: SizedBox(
        width: 420,
        child: SingleChildScrollView(
          child: SelectableText(
            json,
            style: const TextStyle(fontSize: 11.5, height: 1.35, fontFamily: 'monospace'),
          ),
        ),
      ),
      actionsPadding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text(AppStrings.close)),
        FilledButton.icon(
          onPressed: () async {
            await Clipboard.setData(ClipboardData(text: json));
            if (!context.mounted) return;
            ScaffoldMessenger.maybeOf(context)?.showSnackBar(
              const SnackBar(content: Text('Données copiées dans le presse-papiers.')),
            );
          },
          icon: const Icon(Icons.copy_rounded, size: 18),
          label: const Text('Copier'),
        ),
      ],
    );
  }
}
