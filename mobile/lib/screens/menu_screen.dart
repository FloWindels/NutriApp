import 'package:flutter/material.dart';

import '../core/env.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../navigation/app_sections.dart';
import '../services/auth_service.dart';
import '../theme/app_theme.dart';
import '../widgets/app_card.dart';
import '../widgets/confirm_dialog.dart';

/// « Plus » tab: user card + grid of the 13 sections grouped by category + Déconnexion.
class MenuScreen extends StatelessWidget {
  const MenuScreen({super.key, required this.onNavigate});

  final ValueChanged<String> onNavigate;

  Future<void> _logout(BuildContext context) async {
    final ok = await ConfirmDialog.show(
      context,
      title: 'Se déconnecter ?',
      message: 'Tu pourras te reconnecter à tout moment avec ton email et ton mot de passe.',
      confirmLabel: AppStrings.logout,
      icon: Icons.logout_rounded,
    );
    if (!ok) return;
    await AuthService().logout();
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: Session.instance,
      builder: (context, _) {
        final user = Session.instance.user;
        return ListView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
          children: [
            AppCard(
              onTap: () => onNavigate('profil'),
              child: Row(
                children: [
                  Container(
                    width: 52,
                    height: 52,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: MaviohColors.primary,
                      borderRadius: BorderRadius.circular(18),
                    ),
                    child: Text(
                      _initials(user?.name),
                      style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 18),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          user?.name ?? 'Utilisateur',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: MaviohColors.text),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          user?.email ?? '',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(color: MaviohColors.muted, fontSize: 13),
                        ),
                        if (user != null && !user.hasProfile) ...[
                          const SizedBox(height: 6),
                          const Text(
                            'Profil à compléter',
                            style: TextStyle(color: MaviohColors.warning, fontSize: 12, fontWeight: FontWeight.w700),
                          ),
                        ],
                      ],
                    ),
                  ),
                  const Icon(Icons.chevron_right_rounded, color: MaviohColors.muted),
                ],
              ),
            ),
            const SizedBox(height: 18),
            for (final category in AppSections.categoryOrder) ...[
              Padding(
                padding: const EdgeInsets.fromLTRB(4, 6, 4, 10),
                child: Text(
                  category.toUpperCase(),
                  style: const TextStyle(fontSize: 11, letterSpacing: 1.2, fontWeight: FontWeight.w700, color: MaviohColors.muted),
                ),
              ),
              GridView.count(
                crossAxisCount: 2,
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                mainAxisSpacing: 10,
                crossAxisSpacing: 10,
                childAspectRatio: 1.55,
                children: [
                  for (final section in AppSections.byCategory(category))
                    _SectionTile(section: section, onTap: () => onNavigate(section.slug)),
                ],
              ),
              const SizedBox(height: 14),
            ],
            OutlinedButton.icon(
              onPressed: () => _logout(context),
              style: OutlinedButton.styleFrom(foregroundColor: MaviohColors.error, side: const BorderSide(color: Color(0xFFFECDD3))),
              icon: const Icon(Icons.logout_rounded),
              label: const Text(AppStrings.logout),
            ),
            const SizedBox(height: 14),
            Text(
              '${AppStrings.brand} · version ${Env.appVersion}',
              textAlign: TextAlign.center,
              style: const TextStyle(color: MaviohColors.muted, fontSize: 12),
            ),
          ],
        );
      },
    );
  }

  static String _initials(String? name) {
    if (name == null || name.trim().isEmpty) return '?';
    final parts = name.trim().split(RegExp(r'\s+'));
    if (parts.length == 1) return parts.first.substring(0, 1).toUpperCase();
    return (parts.first.substring(0, 1) + parts.last.substring(0, 1)).toUpperCase();
  }
}

class _SectionTile extends StatelessWidget {
  final AppSection section;
  final VoidCallback onTap;

  const _SectionTile({required this.section, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(20),
      child: InkWell(
        borderRadius: BorderRadius.circular(20),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: MaviohColors.border),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(color: section.tone, borderRadius: BorderRadius.circular(11)),
                child: Icon(section.icon, color: Colors.white, size: 19),
              ),
              Text(
                section.title,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: MaviohColors.text, height: 1.2),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
