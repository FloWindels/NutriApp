import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../models/diet.dart';
import '../../services/diet_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/error_state.dart';
import '../../widgets/loading_state.dart';
import '../../widgets/macro_pill.dart';
import '../../widgets/status_banner.dart';
import 'diet_widgets.dart';

/// Fiche détaillée d’un régime du catalogue (`GET /diets/{key}`, §9).
///
/// Nom, description, principes, aliments conseillés et à limiter, conseils, et
/// la mention « pas proposé aux moins de 18 ans » quand `mineurs_autorise`
/// est faux.
class DietDetailSheet extends StatefulWidget {
  const DietDetailSheet({
    super.key,
    required this.dietKey,
    this.nom,
    this.isCurrent = false,
  });

  final String dietKey;
  final String? nom;
  final bool isCurrent;

  /// Cache slug used for the full config of one diet.
  static String cacheKey(String key) => 'diet:$key';

  static Future<void> show(
    BuildContext context, {
    required String dietKey,
    String? nom,
    bool isCurrent = false,
  }) {
    final height = (MediaQuery.sizeOf(context).height * 0.85).clamp(420.0, 760.0);
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      useRootNavigator: true,
      builder: (_) => SizedBox(
        height: height,
        child: DietDetailSheet(dietKey: dietKey, nom: nom, isCurrent: isCurrent),
      ),
    );
  }

  @override
  State<DietDetailSheet> createState() => _DietDetailSheetState();
}

class _DietDetailSheetState extends State<DietDetailSheet> {
  final DietService _service = DietService();

  Diet? _diet;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    final cached = Session.instance.cached(DietDetailSheet.cacheKey(widget.dietKey));
    if (cached != null) {
      _diet = DietService.parseDiet(cached.data, widget.dietKey);
      _loading = false;
      if (cached.isStale()) _load(silent: true);
    } else {
      _load();
    }
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = _diet == null;
        _error = null;
      });
    }
    try {
      final raw = await _service.getRaw(widget.dietKey);
      if (!mounted) return;
      Session.instance.put(DietDetailSheet.cacheKey(widget.dietKey), raw);
      setState(() {
        _diet = DietService.parseDiet(raw, widget.dietKey);
        _loading = false;
        _error = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (_diet == null) _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _diet == null) {
      return const LoadingState(message: 'Chargement du régime…');
    }
    if (_error != null && _diet == null) {
      return ErrorState(message: _error!, onRetry: _load);
    }
    final diet = _diet!;
    return Column(
      children: [
        Expanded(
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 16),
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Text(
                      diet.nom,
                      style: const TextStyle(
                        fontSize: 20,
                        fontWeight: FontWeight.w800,
                        color: MaviohColors.text,
                      ),
                    ),
                  ),
                  if (widget.isCurrent)
                    const TonePill(
                      label: 'Ton régime',
                      tone: MaviohColors.primary,
                      icon: Icons.check_rounded,
                    ),
                ],
              ),
              if (diet.description.trim().isNotEmpty) ...[
                const SizedBox(height: 10),
                Text(
                  diet.description.trim(),
                  style: const TextStyle(color: MaviohColors.textTertiary, height: 1.5),
                ),
              ],
              if (!diet.mineursAutorise) ...[
                const SizedBox(height: 14),
                const StatusBanner.warning(
                  'Ce régime n’est pas proposé aux moins de 18 ans. Parles-en à un professionnel de santé.',
                ),
              ],
              if (diet.principes.isNotEmpty) ...[
                const SizedBox(height: 18),
                const DietSubtitle('Principes', icon: Icons.checklist_rounded),
                DietBulletList(items: diet.principes),
              ],
              if (diet.alimentsConseilles.isNotEmpty) ...[
                const SizedBox(height: 16),
                const DietSubtitle('Aliments conseillés', icon: Icons.thumb_up_outlined),
                DietFoodChips(items: diet.alimentsConseilles, tone: MaviohColors.emerald),
              ],
              if (diet.alimentsALimiter.isNotEmpty) ...[
                const SizedBox(height: 16),
                const DietSubtitle('Aliments à limiter', icon: Icons.do_not_disturb_on_outlined),
                DietFoodChips(items: diet.alimentsALimiter, tone: MaviohColors.rose),
              ],
              if (diet.conseils.isNotEmpty) ...[
                const SizedBox(height: 16),
                const DietSubtitle('Conseils', icon: Icons.tips_and_updates_outlined),
                DietBulletList(items: diet.conseils, color: MaviohColors.sky),
              ],
              const SizedBox(height: 18),
              const Text(
                AppStrings.disclaimer,
                style: TextStyle(fontSize: 11.5, color: MaviohColors.muted, height: 1.4),
              ),
            ],
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
            child: SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text(AppStrings.close),
              ),
            ),
          ),
        ),
      ],
    );
  }
}
