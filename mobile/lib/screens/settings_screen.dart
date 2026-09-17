import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/env.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/settings.dart';
import '../services/auth_service.dart';
import '../services/settings_service.dart';
import '../theme/app_theme.dart';
import '../widgets/app_card.dart';
import '../widgets/confirm_dialog.dart';
import '../widgets/error_state.dart';
import '../widgets/loading_state.dart';
import '../widgets/notification_bell.dart';
import '../widgets/status_banner.dart';
import 'login_screen.dart';
import 'settings/settings_sheets.dart';
import 'settings/settings_tiles.dart';

/// Paramètres (§16.4) : compte, rappels dans l’app, sport, foyer, apparence, à propos.
///
/// Chaque interrupteur et chaque curseur s’enregistre tout seul via
/// `PUT /settings` : l’état local est mis à jour tout de suite (optimiste) puis
/// remis en arrière si l’appel échoue.
class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key, this.onNavigate});

  /// Navigation rapide vers une autre section (slug).
  final ValueChanged<String>? onNavigate;

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  static const String _cacheKey = 'settings';

  final _service = SettingsService();

  UserSettings? _settings;
  bool _loading = true;
  String? _error;
  String? _refreshError;
  String? _saveError;
  String? _saveSuccess;
  bool _exporting = false;
  int? _daysDraft;

  @override
  void initState() {
    super.initState();
    final cached = Session.instance.cached(_cacheKey);
    if (cached != null) {
      _settings = UserSettings.fromJson(cached.data);
      _loading = false;
      if (cached.isStale()) _load(silent: true);
    } else {
      _load();
    }
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = _settings == null;
        _error = null;
        _refreshError = null;
      });
    }
    try {
      final settings = await _service.get();
      if (!mounted) return;
      Session.instance.put(_cacheKey, settings.toJson());
      setState(() {
        _settings = settings;
        _loading = false;
        _error = null;
        _refreshError = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (_settings == null) {
          _error = e.message;
        } else {
          _refreshError = e.message;
        }
      });
      if (_settings != null && !silent) {
        ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  /// Enregistrement optimiste : [optimistic] s’affiche tout de suite, l’état
  /// précédent revient si `PUT /settings` échoue.
  Future<void> _save(Map<String, dynamic> patch, UserSettings optimistic) async {
    final previous = _settings;
    if (previous == null) return;
    setState(() {
      _settings = optimistic;
      _saveError = null;
      _saveSuccess = null;
    });
    try {
      final saved = await _service.update(patch);
      if (!mounted) return;
      Session.instance.put(_cacheKey, saved.toJson());
      Session.instance.invalidate('dashboard');
      Session.instance.invalidate('notifications');
      Session.instance.invalidatePrefix('sport');
      setState(() {
        _settings = saved;
        _daysDraft = null;
      });
      NotificationBell.refresh(force: true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _settings = previous;
        _daysDraft = null;
        _saveError = e.message;
      });
    }
  }

  // ----- Compte ---------------------------------------------------------------

  Future<void> _editAccount() async {
    final user = Session.instance.user;
    final saved = await AccountEditSheet.show(
      context,
      name: user?.name ?? '',
      email: user?.email ?? '',
    );
    if (!mounted || !saved) return;
    setState(() {
      _saveError = null;
      _saveSuccess = 'Compte mis à jour.';
    });
  }

  Future<void> _changePassword() async {
    final message = await PasswordChangeSheet.show(context);
    if (!mounted || message == null) return;
    setState(() {
      _saveError = null;
      _saveSuccess = message;
    });
  }

  Future<void> _exportData() async {
    setState(() {
      _exporting = true;
      _saveError = null;
    });
    try {
      final data = await AuthService().exportAccount();
      if (!mounted) return;
      setState(() => _exporting = false);
      await ExportDataDialog.show(context, data);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _exporting = false;
        _saveError = e.message;
      });
    }
  }

  Future<void> _deleteAccount() async {
    final done = await DeleteAccountSheet.show(context);
    if (!mounted || !done) return;
    Navigator.of(context, rootNavigator: true).pushAndRemoveUntil(
      MaterialPageRoute<void>(
        builder: (_) => const LoginScreen(initialMessage: 'Ton compte a été supprimé. À bientôt !'),
      ),
      (route) => false,
    );
  }

  Future<void> _logout() async {
    final ok = await ConfirmDialog.show(
      context,
      title: 'Se déconnecter ?',
      message: 'Tu pourras te reconnecter à tout moment avec ton email et ton mot de passe.',
      confirmLabel: AppStrings.logout,
      icon: Icons.logout_rounded,
    );
    if (!ok) return;
    await Session.instance.logout();
  }

  // ----- Rappels ---------------------------------------------------------------

  Future<void> _pickTime() async {
    final settings = _settings;
    if (settings == null) return;
    final parts = (settings.heureRappel ?? '19:00').split(':');
    final initial = TimeOfDay(
      hour: parseIntOr(parts.isNotEmpty ? parts[0] : null, 19).clamp(0, 23),
      minute: parseIntOr(parts.length > 1 ? parts[1] : null, 0).clamp(0, 59),
    );
    final picked = await showTimePicker(context: context, initialTime: initial);
    if (picked == null || !mounted) return;
    final value = '${picked.hour.toString().padLeft(2, '0')}:${picked.minute.toString().padLeft(2, '0')}';
    await _save({'heure_rappel': value}, settings.copyWith(heureRappel: value));
  }

  // ----- Build ------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    if (_loading && _settings == null) return const LoadingState(skeleton: true, skeletonCount: 4);
    if (_error != null && _settings == null) {
      return ErrorState(message: _error!, onRetry: _load);
    }
    final settings = _settings!;

    return RefreshIndicator(
      onRefresh: () => _load(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
        children: [
          if (_refreshError != null)
            StatusBanner.warning(
              'Données peut-être obsolètes : $_refreshError',
              margin: const EdgeInsets.only(bottom: 12),
              onClose: () => setState(() => _refreshError = null),
            ),
          if (_saveError != null)
            StatusBanner.error(
              _saveError!,
              margin: const EdgeInsets.only(bottom: 12),
              onClose: () => setState(() => _saveError = null),
            ),
          if (_saveSuccess != null)
            StatusBanner.success(
              _saveSuccess!,
              margin: const EdgeInsets.only(bottom: 12),
              onClose: () => setState(() => _saveSuccess = null),
            ),
          _accountSection(),
          _remindersSection(settings),
          _sportSection(settings),
          if (_hasHousehold(settings)) _householdSection(settings),
          _appearanceSection(),
          _aboutSection(),
          const SizedBox(height: 4),
          OutlinedButton.icon(
            onPressed: _logout,
            style: OutlinedButton.styleFrom(
              foregroundColor: MaviohColors.error,
              side: const BorderSide(color: Color(0xFFFECDD3)),
              minimumSize: const Size.fromHeight(48),
            ),
            icon: const Icon(Icons.logout_rounded),
            label: const Text(AppStrings.logout),
          ),
        ],
      ),
    );
  }

  bool _hasHousehold(UserSettings settings) =>
      settings.partageProfilFoyer != null || Session.instance.user?.householdId != null;

  Widget _accountSection() {
    final user = Session.instance.user;
    return SettingsSection(
      title: 'Compte',
      icon: Icons.person_outline_rounded,
      tone: MaviohColors.primary,
      children: [
        SettingsActionTile(
          title: user?.name.isNotEmpty == true ? user!.name : 'Nom et email',
          subtitle: user?.email ?? 'Modifie ton nom et ton adresse email',
          icon: Icons.badge_outlined,
          onTap: _editAccount,
        ),
        SettingsActionTile(
          title: 'Changer le mot de passe',
          subtitle: 'Tes autres appareils seront déconnectés',
          icon: Icons.lock_outline_rounded,
          onTap: _changePassword,
        ),
        SettingsActionTile(
          title: 'Exporter mes données',
          subtitle: 'Tout ce que Mavi’oh conserve sur toi, au format JSON',
          icon: Icons.download_outlined,
          busy: _exporting,
          onTap: _exportData,
        ),
        SettingsActionTile(
          title: 'Supprimer mon compte',
          subtitle: 'Suppression définitive, confirmée par ton mot de passe',
          icon: Icons.delete_outline_rounded,
          danger: true,
          onTap: _deleteAccount,
        ),
      ],
    );
  }

  Widget _remindersSection(UserSettings settings) {
    final days = _daysDraft ?? settings.joursAlertePeremption;
    final anyReminder = settings.notifPeremption || settings.notifRappelRepas || settings.notifRappelSport;
    return SettingsSection(
      title: 'Rappels dans l’app',
      icon: Icons.notifications_none_rounded,
      tone: MaviohColors.amber,
      caption: 'Aucune notification système pour l’instant : les rappels s’affichent dans Mavi’oh.',
      children: [
        SettingsSwitchTile(
          title: 'Péremption du stock',
          subtitle: 'Prévenir quand un aliment approche de sa date',
          value: settings.notifPeremption,
          onChanged: (value) => _save({'notif_peremption': value}, settings.copyWith(notifPeremption: value)),
        ),
        if (settings.notifPeremption) ...[
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              'Prévenir $days jour${days > 1 ? 's' : ''} avant',
              style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
            ),
          ),
          Slider(
            value: days.toDouble().clamp(1, 7),
            min: 1,
            max: 7,
            divisions: 6,
            label: '$days j',
            onChanged: (value) => setState(() => _daysDraft = value.round()),
            onChangeEnd: (value) {
              final rounded = value.round();
              _save(
                {'jours_alerte_peremption': rounded},
                settings.copyWith(joursAlertePeremption: rounded),
              );
            },
          ),
        ],
        SettingsSwitchTile(
          title: 'Rappel des repas',
          subtitle: 'Quand un repas prévu n’est pas encore enregistré',
          value: settings.notifRappelRepas,
          onChanged: (value) => _save({'notif_rappel_repas': value}, settings.copyWith(notifRappelRepas: value)),
        ),
        SettingsSwitchTile(
          title: 'Rappel des séances',
          subtitle: 'Quand une séance est prévue aujourd’hui',
          value: settings.notifRappelSport,
          onChanged: (value) => _save({'notif_rappel_sport': value}, settings.copyWith(notifRappelSport: value)),
        ),
        if (anyReminder)
          SettingsActionTile(
            title: 'Heure du rappel',
            subtitle: 'Moment de la journée où les rappels apparaissent',
            icon: Icons.schedule_rounded,
            onTap: _pickTime,
            trailing: Text(
              fmtTimeString(settings.heureRappel) == '—' ? 'Choisir' : fmtTimeString(settings.heureRappel),
              style: const TextStyle(fontWeight: FontWeight.w800, color: MaviohColors.primary),
            ),
          ),
      ],
    );
  }

  Widget _sportSection(UserSettings settings) {
    return SettingsSection(
      title: AppStrings.sectionSport,
      icon: Icons.fitness_center_rounded,
      tone: MaviohColors.lime,
      children: [
        SettingsSwitchTile(
          title: 'Séances proposées par l’IA',
          subtitle: 'Le coach Mavi’oh s’appuie sur l’IA pour adapter tes séances. '
              'Sans elle, les séances suivent les règles Mavi’oh.',
          value: settings.iaSeances,
          onChanged: (value) => _save({'ia_seances': value}, settings.copyWith(iaSeances: value)),
        ),
      ],
    );
  }

  Widget _householdSection(UserSettings settings) {
    return SettingsSection(
      title: 'Foyer',
      icon: Icons.groups_outlined,
      tone: MaviohColors.violet,
      children: [
        SettingsSwitchTile(
          title: 'Partager mon profil avec le foyer',
          subtitle: 'Les autres membres voient tes cibles et ton régime',
          value: settings.partageProfilFoyer ?? false,
          onChanged: (value) => _save(
            {'partage_profil_foyer': value},
            settings.copyWith(partageProfilFoyer: value),
          ),
        ),
        SettingsActionTile(
          title: 'Gérer mon foyer',
          icon: Icons.open_in_new_rounded,
          onTap: widget.onNavigate == null ? null : () => widget.onNavigate!('famille'),
        ),
      ],
    );
  }

  Widget _appearanceSection() {
    return const SettingsSection(
      title: 'Apparence',
      icon: Icons.palette_outlined,
      tone: MaviohColors.sky,
      children: [
        SettingsActionTile(
          title: 'Thème',
          subtitle: 'Clair · Sombre bientôt',
          icon: Icons.light_mode_outlined,
        ),
      ],
    );
  }

  Widget _aboutSection() {
    return AppCard(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.info_outline_rounded, size: 20, color: MaviohColors.slate),
              const SizedBox(width: 8),
              const Expanded(
                child: Text(
                  'À propos',
                  style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                ),
              ),
              Text(
                'version ${Env.appVersion}',
                style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, fontWeight: FontWeight.w700),
              ),
            ],
          ),
          const SizedBox(height: 10),
          const Text(
            AppStrings.offAttribution,
            style: TextStyle(fontSize: 12.5, color: MaviohColors.textTertiary, height: 1.4),
          ),
          const SizedBox(height: 6),
          const Text(
            'Les objectifs sont des estimations, pas un avis médical.',
            style: TextStyle(fontSize: 12.5, color: MaviohColors.textTertiary, height: 1.4),
          ),
        ],
      ),
    );
  }
}
