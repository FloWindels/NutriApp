import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/diet.dart';
import '../models/profile.dart';
import '../services/diet_service.dart';
import '../services/profile_service.dart';
import '../theme/app_theme.dart';
import '../widgets/app_card.dart';
import '../widgets/empty_state.dart';
import '../widgets/error_state.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/section_header.dart';
import '../widgets/status_banner.dart';
import 'diet/diet_detail_sheet.dart';
import 'diet/diet_score_gauge.dart';
import 'diet/diet_widgets.dart';

/// Régime reconnu (§16.4 « DietScreen » + §9).
///
/// En-tête du régime courant (profil) avec « Changer », principes et conseils
/// du régime (`GET /diets/{key}`), carte d’évaluation (`GET /diets/evaluate`)
/// avec jauge de score, statut, mention, écarts groupés par jour et régimes
/// proches, puis le catalogue complet (`GET /diets`).
class DietScreen extends StatefulWidget {
  const DietScreen({super.key, this.onNavigate});

  /// Navigation rapide vers une autre section (slug).
  final ValueChanged<String>? onNavigate;

  @override
  State<DietScreen> createState() => _DietScreenState();
}

class _DietScreenState extends State<DietScreen> {
  static const String _profileCacheKey = 'profile';
  static const String _catalogCacheKey = 'diet:catalog';
  static const List<int> _dayOptions = [7, 14, 30];

  static String _evaluationCacheKey(int days) => 'diet:evaluate:$days';

  final ProfileService _profileService = ProfileService();
  final DietService _dietService = DietService();

  Profile? _profile;
  List<Diet>? _catalog;
  Diet? _diet;
  DietEvaluation? _evaluation;

  bool _loading = true;
  String? _error;
  String? _refreshError;
  String? _detailError;
  String? _evaluationError;
  bool _evaluationLoading = false;

  int _days = 7;
  String? _openProche;

  String? get _regimeKey {
    final key = _profile?.regimeAlimentaire;
    if (key == null || key.trim().isEmpty) return null;
    return key;
  }

  String get _regimeLabel {
    final key = _regimeKey;
    if (key == null) return '—';
    return _diet?.nom ?? AppStrings.regimeLabels[key] ?? capitalize(key);
  }

  @override
  void initState() {
    super.initState();
    final cachedProfile = Session.instance.cached(_profileCacheKey);
    final cachedCatalog = Session.instance.cached(_catalogCacheKey);
    if (cachedProfile != null && cachedCatalog != null) {
      _profile = Profile.fromJson(cachedProfile.data);
      _catalog = DietService.parseList(cachedCatalog.data);
      _loading = false;
      _hydrateRegimeFromCache();
      _load(silent: true);
    } else {
      _load();
    }
  }

  /// Rebuilds the regime detail and the evaluation from `Session.cache`.
  void _hydrateRegimeFromCache() {
    final key = _regimeKey;
    if (key == null) return;
    final detail = Session.instance.cached(DietDetailSheet.cacheKey(key));
    if (detail != null) _diet = DietService.parseDiet(detail.data, key);
    final evaluation = Session.instance.cached(_evaluationCacheKey(_days));
    if (evaluation != null) _evaluation = DietService.parseEvaluation(evaluation.data);
  }

  // ------------------------------------------------------------- chargement ---

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = _profile == null || _catalog == null;
        _error = null;
        _refreshError = null;
      });
    }
    late final Map<String, dynamic> profileRaw;
    late final Map<String, dynamic> catalogRaw;
    try {
      final results = await Future.wait<Map<String, dynamic>>([
        _profileService.raw(),
        _dietService.listRaw(),
      ]);
      profileRaw = results[0];
      catalogRaw = results[1];
    } on ApiException catch (e) {
      if (!mounted) return;
      final hasData = _profile != null && _catalog != null;
      setState(() {
        _loading = false;
        if (hasData) {
          _refreshError = e.message;
        } else {
          _error = e.message;
        }
      });
      if (hasData && !silent) _snack(e.message);
      return;
    }
    if (!mounted) return;
    Session.instance.put(_profileCacheKey, profileRaw);
    Session.instance.put(_catalogCacheKey, catalogRaw);
    setState(() {
      _profile = Profile.fromJson(profileRaw);
      _catalog = DietService.parseList(catalogRaw);
      _loading = false;
      _error = null;
      _refreshError = null;
    });
    await _loadRegimeData();
  }

  Future<void> _loadRegimeData() async {
    final key = _regimeKey;
    if (key == null) {
      if (!mounted) return;
      setState(() {
        _diet = null;
        _evaluation = null;
        _detailError = null;
        _evaluationError = null;
      });
      return;
    }
    await Future.wait<void>([
      _loadDetail(key),
      _loadEvaluation(days: _days, showLoader: _evaluation == null),
    ]);
  }

  Future<void> _loadDetail(String key) async {
    try {
      final raw = await _dietService.getRaw(key);
      if (!mounted) return;
      Session.instance.put(DietDetailSheet.cacheKey(key), raw);
      setState(() {
        _diet = DietService.parseDiet(raw, key);
        _detailError = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _detailError = e.message);
    }
  }

  Future<void> _loadEvaluation({required int days, bool showLoader = true}) async {
    if (showLoader && mounted) {
      setState(() {
        _evaluationLoading = true;
        _evaluationError = null;
      });
    }
    try {
      final raw = await _dietService.evaluateRaw(days: days);
      if (!mounted) return;
      Session.instance.put(_evaluationCacheKey(days), raw);
      if (days != _days) return;
      setState(() {
        _evaluation = DietService.parseEvaluation(raw);
        _evaluationLoading = false;
        _evaluationError = null;
      });
    } on ApiException catch (e) {
      if (!mounted || days != _days) return;
      setState(() {
        _evaluationLoading = false;
        _evaluationError = e.message;
      });
    }
  }

  void _onDaysChanged(int days) {
    if (days == _days) return;
    final cached = Session.instance.cached(_evaluationCacheKey(days));
    setState(() {
      _days = days;
      _openProche = null;
      _evaluationError = null;
      _evaluation = cached == null ? null : DietService.parseEvaluation(cached.data);
    });
    if (cached != null && !cached.isStale()) return;
    _loadEvaluation(days: days, showLoader: cached == null);
  }

  void _snack(String message) {
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(message)));
  }

  void _navigate(String slug) => widget.onNavigate?.call(slug);

  Future<void> _openDiet(Diet diet) {
    return DietDetailSheet.show(
      context,
      dietKey: diet.key,
      nom: diet.nom,
      isCurrent: diet.key == _regimeKey,
    );
  }

  // ------------------------------------------------------------------ build ---

  @override
  Widget build(BuildContext context) {
    if (_loading && _profile == null) return const LoadingState(skeleton: true, skeletonCount: 3);
    if (_error != null && _profile == null) return ErrorState(message: _error!, onRetry: _load);

    final hasRegime = _regimeKey != null;

    return RefreshIndicator(
      onRefresh: () => _load(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 96),
        children: [
          if (_refreshError != null)
            StatusBanner.warning(
              'Données peut-être obsolètes : $_refreshError',
              margin: const EdgeInsets.only(bottom: 14),
              onClose: () => setState(() => _refreshError = null),
            ),
          if (!hasRegime)
            SizedBox(
              height: 320,
              child: EmptyState(
                icon: Icons.restaurant_menu_outlined,
                title: 'Aucun régime enregistré',
                message:
                    'Choisis ton régime alimentaire dans ton profil : Mavi’oh pourra alors comparer tes repas à ses règles.',
                ctaLabel: 'Compléter mon profil',
                onCta: () => _navigate('profil'),
              ),
            )
          else ...[
            _header(),
            const SizedBox(height: 14),
            _principlesCard(),
            const SizedBox(height: 14),
            _evaluationCard(),
          ],
          const SizedBox(height: 22),
          _catalogSection(),
          const SizedBox(height: 18),
          const Text(
            AppStrings.disclaimer,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 11.5, color: MaviohColors.muted, height: 1.4),
          ),
        ],
      ),
    );
  }

  // ----------------------------------------------------------------- header ---

  Widget _header() {
    final description = _diet?.description.trim() ?? '';
    return AppCard.hero(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: MaviohColors.tint(MaviohColors.primary),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: const Icon(Icons.eco_outlined, color: MaviohColors.primary, size: 22),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'TON RÉGIME',
                      style: TextStyle(
                        fontSize: 11,
                        letterSpacing: 1.2,
                        fontWeight: FontWeight.w700,
                        color: MaviohColors.muted,
                      ),
                    ),
                    const SizedBox(height: 4),
                    FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerLeft,
                      child: Text(
                        _regimeLabel,
                        style: const TextStyle(
                          fontSize: 22,
                          fontWeight: FontWeight.w800,
                          color: MaviohColors.text,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              TextButton.icon(
                onPressed: () => _navigate('profil'),
                style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
                icon: const Icon(Icons.tune_rounded, size: 18),
                label: const Text('Changer'),
              ),
            ],
          ),
          if (description.isNotEmpty) ...[
            const SizedBox(height: 10),
            Text(
              description,
              style: const TextStyle(color: MaviohColors.textTertiary, height: 1.5, fontSize: 13.5),
            ),
          ],
          if (_diet != null && !_diet!.mineursAutorise && (_profile?.isMinor ?? false)) ...[
            const SizedBox(height: 12),
            const StatusBanner.warning(
              'Ce régime n’est pas proposé aux moins de 18 ans. Parles-en à un professionnel de santé.',
            ),
          ],
        ],
      ),
    );
  }

  // ------------------------------------------------------ principes/conseils ---

  Widget _principlesCard() {
    final diet = _diet;
    if (_detailError != null && diet == null) {
      return AppCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            StatusBanner.error('Principes indisponibles : $_detailError'),
            const SizedBox(height: 10),
            Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton.icon(
                onPressed: () {
                  final key = _regimeKey;
                  if (key != null) _loadDetail(key);
                },
                style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
                icon: const Icon(Icons.refresh_rounded, size: 18),
                label: const Text(AppStrings.retry),
              ),
            ),
          ],
        ),
      );
    }
    if (diet == null) {
      return const AppCard(child: SizedBox(height: 90, child: LoadingState()));
    }
    if (diet.principes.isEmpty && diet.conseils.isEmpty) {
      return AppCard(
        child: EmptyState(
          compact: true,
          icon: Icons.menu_book_outlined,
          title: 'Pas encore de détail pour ce régime',
          message: 'Découvre les autres régimes dans le catalogue ci-dessous.',
          ctaLabel: 'Voir la fiche',
          onCta: () => _openDiet(diet),
        ),
      );
    }
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SectionHeader(
            eyebrow: 'Repères',
            title: 'Principes et conseils',
            padding: EdgeInsets.only(bottom: 12),
          ),
          if (diet.principes.isNotEmpty) ...[
            const DietSubtitle('Principes', icon: Icons.checklist_rounded),
            DietBulletList(items: diet.principes),
          ],
          if (diet.conseils.isNotEmpty) ...[
            const SizedBox(height: 12),
            const DietSubtitle('Conseils', icon: Icons.tips_and_updates_outlined),
            DietBulletList(items: diet.conseils, color: MaviohColors.sky),
          ],
          const SizedBox(height: 6),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: () => _openDiet(diet),
              style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
              icon: const Icon(Icons.open_in_new_rounded, size: 18),
              label: const Text('Voir la fiche complète'),
            ),
          ),
        ],
      ),
    );
  }

  // -------------------------------------------------------------- évaluation ---

  static Color _statutTone(String statut) {
    switch (statut) {
      case 'conforme':
        return MaviohColors.emerald;
      case 'partiel':
        return MaviohColors.amber;
      case 'non_conforme':
        return MaviohColors.rose;
      default:
        return MaviohColors.slate;
    }
  }

  Widget _evaluationCard() {
    final evaluation = _evaluation;
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SectionHeader(
            eyebrow: 'Reconnaissance',
            title: 'Tes repas suivent-ils ce régime ?',
            subtitle: 'Analyse indicative de tes repas enregistrés.',
            padding: EdgeInsets.only(bottom: 12),
          ),
          Wrap(
            spacing: 8,
            children: [
              for (final days in _dayOptions)
                ChoiceChip(
                  label: Text('$days jours'),
                  selected: _days == days,
                  onSelected: (_) => _onDaysChanged(days),
                ),
            ],
          ),
          const SizedBox(height: 14),
          if (_evaluationLoading && evaluation == null)
            const SizedBox(height: 120, child: LoadingState(message: 'Analyse en cours…'))
          else if (_evaluationError != null && evaluation == null)
            _evaluationErrorBox(_evaluationError!)
          else if (evaluation == null)
            const SizedBox(height: 120, child: LoadingState())
          else if (evaluation.insufficientData)
            _insufficientData(evaluation)
          else
            _evaluationBody(evaluation),
        ],
      ),
    );
  }

  Widget _evaluationErrorBox(String message) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        StatusBanner.error(message),
        const SizedBox(height: 10),
        Align(
          alignment: Alignment.centerLeft,
          child: OutlinedButton.icon(
            onPressed: () => _loadEvaluation(days: _days),
            style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
            icon: const Icon(Icons.refresh_rounded, size: 18),
            label: const Text(AppStrings.retry),
          ),
        ),
      ],
    );
  }

  Widget _insufficientData(DietEvaluation evaluation) {
    return EmptyState(
      compact: true,
      icon: Icons.insights_outlined,
      tone: MaviohColors.sky,
      title: 'Données insuffisantes',
      message: evaluation.message ?? 'Enregistre au moins 3 journées complètes pour une évaluation.',
      ctaLabel: 'Enregistrer un repas',
      onCta: () => _navigate('historique-repas-journee'),
    );
  }

  Widget _evaluationBody(DietEvaluation evaluation) {
    final tone = _statutTone(evaluation.statut);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            DietScoreGauge(scorePct: evaluation.scorePct, color: tone),
            const SizedBox(width: 6),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      TonePill(
                        label: evaluation.statutLabel,
                        tone: tone,
                        icon: evaluation.statut == 'conforme'
                            ? Icons.check_circle_outline_rounded
                            : Icons.info_outline_rounded,
                      ),
                      if (evaluation.isEstimate) const EstimatePill(),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'Sur les $_days derniers jours.',
                    style: const TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w600,
                      color: MaviohColors.muted,
                    ),
                  ),
                  if (_evaluationLoading) ...[
                    const SizedBox(height: 8),
                    const Row(
                      children: [
                        SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2)),
                        SizedBox(width: 8),
                        Text(
                          'Mise à jour…',
                          style: TextStyle(fontSize: 12, color: MaviohColors.muted),
                        ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
        if (evaluation.mention.isNotEmpty) ...[
          const SizedBox(height: 12),
          Text(
            evaluation.mention,
            style: const TextStyle(fontSize: 12, color: MaviohColors.muted, height: 1.45),
          ),
        ],
        if (evaluation.conseils.isNotEmpty) ...[
          const SizedBox(height: 16),
          const DietSubtitle('Ce que tu peux ajuster', icon: Icons.tips_and_updates_outlined),
          DietBulletList(items: evaluation.conseils, color: MaviohColors.sky),
        ],
        _ecartsSection(evaluation),
        _regimesProchesSection(evaluation),
      ],
    );
  }

  Widget _ecartsSection(DietEvaluation evaluation) {
    if (evaluation.ecarts.isEmpty) {
      return Padding(
        padding: const EdgeInsets.only(top: 16),
        child: Container(
          width: double.infinity,
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
          decoration: BoxDecoration(
            color: MaviohColors.successBg,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: MaviohColors.success.withValues(alpha: 0.22)),
          ),
          child: const Text(
            'Aucun ingrédient exclu détecté sur la période analysée.',
            style: TextStyle(
              color: MaviohColors.success,
              fontWeight: FontWeight.w600,
              height: 1.4,
              fontSize: 13.5,
            ),
          ),
        ),
      );
    }

    final groups = <String, List<DietEcart>>{};
    final dates = <String, DateTime?>{};
    for (final ecart in evaluation.ecarts) {
      final key = ecart.date == null ? '' : isoDate(ecart.date!);
      groups.putIfAbsent(key, () => <DietEcart>[]).add(ecart);
      dates[key] = ecart.date;
    }
    final keys = groups.keys.toList()..sort((a, b) => b.compareTo(a));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SizedBox(height: 16),
        DietSubtitle('Écarts relevés (${evaluation.ecarts.length})', icon: Icons.report_outlined),
        for (final key in keys) ...[
          Padding(
            padding: const EdgeInsets.only(top: 4, bottom: 6),
            child: Text(
              dates[key] == null ? 'Sans date' : capitalize(fmtRelativeDay(dates[key]!)),
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.3,
                color: MaviohColors.muted,
              ),
            ),
          ),
          for (final ecart in groups[key]!)
            Container(
              width: double.infinity,
              margin: const EdgeInsets.only(bottom: 8),
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
              decoration: BoxDecoration(
                color: MaviohColors.surface,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: MaviohColors.border),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          ecart.itemLabel ?? AppStrings.mealType(ecart.mealType),
                          style: const TextStyle(
                            fontWeight: FontWeight.w700,
                            color: MaviohColors.text,
                            fontSize: 13.5,
                          ),
                        ),
                      ),
                      if (ecart.mealType != null) ...[
                        const SizedBox(width: 8),
                        TonePill(label: AppStrings.mealType(ecart.mealType), tone: MaviohColors.slate),
                      ],
                    ],
                  ),
                  if (ecart.explication.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      ecart.explication,
                      style: const TextStyle(
                        fontSize: 12.5,
                        color: MaviohColors.textTertiary,
                        height: 1.4,
                      ),
                    ),
                  ],
                  if (ecart.regle.isNotEmpty) ...[
                    const SizedBox(height: 6),
                    TonePill(label: ecart.regle, tone: MaviohColors.amber, icon: Icons.rule_rounded),
                  ],
                ],
              ),
            ),
        ],
      ],
    );
  }

  Widget _regimesProchesSection(DietEvaluation evaluation) {
    if (evaluation.regimesProches.isEmpty) return const SizedBox.shrink();
    final open = evaluation.regimesProches.where((r) => r.key == _openProche).toList();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SizedBox(height: 16),
        const DietSubtitle('Régimes proches de tes repas', icon: Icons.compare_arrows_rounded),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final proche in evaluation.regimesProches)
              ChoiceChip(
                label: Text('${proche.nom} · ${fmtPct(proche.scorePct)}'),
                selected: _openProche == proche.key,
                onSelected: (selected) => setState(() => _openProche = selected ? proche.key : null),
              ),
          ],
        ),
        if (open.isNotEmpty)
          Container(
            width: double.infinity,
            margin: const EdgeInsets.only(top: 10),
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
            decoration: BoxDecoration(
              color: MaviohColors.surface,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: MaviohColors.border),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Pourquoi ${open.first.nom} ?',
                  style: const TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w800,
                    color: MaviohColors.textSecondary,
                  ),
                ),
                const SizedBox(height: 6),
                if (open.first.raisons.isEmpty)
                  const Text(
                    'Aucune précision fournie pour ce régime.',
                    style: TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.4),
                  )
                else
                  DietBulletList(items: open.first.raisons, fontSize: 12.5),
                const SizedBox(height: 4),
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () => DietDetailSheet.show(
                      context,
                      dietKey: open.first.key,
                      nom: open.first.nom,
                      isCurrent: open.first.key == _regimeKey,
                    ),
                    style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
                    icon: const Icon(Icons.open_in_new_rounded, size: 18),
                    label: const Text('Voir la fiche'),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }

  // --------------------------------------------------------------- catalogue ---

  Widget _catalogSection() {
    final catalog = _catalog ?? const <Diet>[];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SectionHeader(
          eyebrow: 'Catalogue',
          title: 'Tous les régimes',
          subtitle: 'Touche un régime pour voir ses principes et ses aliments.',
        ),
        if (catalog.isEmpty)
          AppCard(
            child: EmptyState(
              compact: true,
              icon: Icons.menu_book_outlined,
              title: 'Catalogue indisponible',
              message: 'Les régimes n’ont pas pu être chargés.',
              ctaLabel: AppStrings.retry,
              onCta: _load,
            ),
          )
        else
          for (final diet in catalog)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: AppCard(
                padding: const EdgeInsets.fromLTRB(16, 14, 12, 14),
                onTap: () => _openDiet(diet),
                borderColor: diet.key == _regimeKey ? MaviohColors.primary.withValues(alpha: 0.35) : null,
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Flexible(
                                child: Text(
                                  diet.nom,
                                  style: const TextStyle(
                                    fontSize: 15,
                                    fontWeight: FontWeight.w800,
                                    color: MaviohColors.text,
                                  ),
                                ),
                              ),
                              if (diet.key == _regimeKey) ...[
                                const SizedBox(width: 8),
                                const TonePill(label: 'Ton régime', tone: MaviohColors.primary),
                              ],
                              if (!diet.mineursAutorise) ...[
                                const SizedBox(width: 8),
                                const TonePill(label: '18+', tone: MaviohColors.amber),
                              ],
                            ],
                          ),
                          if (diet.description.trim().isNotEmpty) ...[
                            const SizedBox(height: 4),
                            Text(
                              diet.description.trim(),
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                fontSize: 12.5,
                                color: MaviohColors.muted,
                                height: 1.4,
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                    const Icon(Icons.chevron_right_rounded, color: MaviohColors.muted),
                  ],
                ),
              ),
            ),
      ],
    );
  }
}
