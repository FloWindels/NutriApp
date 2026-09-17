import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';
import '../../widgets/app_card.dart';

/// Une section de réglages : carte blanche avec un titre et ses lignes.
class SettingsSection extends StatelessWidget {
  const SettingsSection({
    super.key,
    required this.title,
    required this.icon,
    this.caption,
    this.tone = MaviohColors.primary,
    required this.children,
  });

  final String title;
  final IconData icon;
  final String? caption;
  final Color tone;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, size: 20, color: tone),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  title,
                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                ),
              ),
            ],
          ),
          if (caption != null) ...[
            const SizedBox(height: 6),
            Text(
              caption!,
              style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.35),
            ),
          ],
          const SizedBox(height: 4),
          ...children,
        ],
      ),
    );
  }
}

/// Interrupteur autosauvegardé (mise à jour optimiste côté écran).
class SettingsSwitchTile extends StatelessWidget {
  const SettingsSwitchTile({
    super.key,
    required this.title,
    required this.value,
    required this.onChanged,
    this.subtitle,
    this.enabled = true,
  });

  final String title;
  final String? subtitle;
  final bool value;
  final ValueChanged<bool>? onChanged;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    return SwitchListTile.adaptive(
      contentPadding: EdgeInsets.zero,
      visualDensity: VisualDensity.standard,
      title: Text(
        title,
        style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w700, color: MaviohColors.text),
      ),
      subtitle: subtitle == null
          ? null
          : Text(subtitle!, style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.35)),
      value: value,
      onChanged: enabled ? onChanged : null,
    );
  }
}

/// Ligne d’action (ouvre une feuille, un dialogue, une autre section).
class SettingsActionTile extends StatelessWidget {
  const SettingsActionTile({
    super.key,
    required this.title,
    required this.icon,
    this.subtitle,
    this.onTap,
    this.danger = false,
    this.busy = false,
    this.trailing,
  });

  final String title;
  final String? subtitle;
  final IconData icon;
  final VoidCallback? onTap;
  final bool danger;
  final bool busy;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final color = danger ? MaviohColors.error : MaviohColors.text;
    return ListTile(
      contentPadding: EdgeInsets.zero,
      minVerticalPadding: 12,
      enabled: onTap != null && !busy,
      leading: Icon(icon, size: 20, color: danger ? MaviohColors.error : MaviohColors.textTertiary),
      title: Text(
        title,
        style: TextStyle(fontSize: 14.5, fontWeight: FontWeight.w700, color: color),
      ),
      subtitle: subtitle == null
          ? null
          : Text(subtitle!, style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.35)),
      trailing: busy
          ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.2))
          : (trailing ?? (onTap == null ? null : const Icon(Icons.chevron_right_rounded, color: MaviohColors.muted))),
      onTap: busy ? null : onTap,
    );
  }
}
