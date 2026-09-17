import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/recipe.dart';
import '../models/recommendation.dart';
import '../services/recipe_service.dart';
import '../services/recommendation_service.dart';
import '../services/shopping_service.dart';
import '../services/stock_service.dart';
import '../theme/app_theme.dart';
import '../widgets/add_to_meal_sheet.dart';
import '../widgets/app_card.dart';
import '../widgets/confirm_dialog.dart';
import '../widgets/date_strip.dart';
import '../widgets/empty_state.dart';
import '../widgets/error_state.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/recipe_tile.dart';
import '../widgets/status_banner.dart';

/// Coach du jour (§16.4 « RecommendationsScreen » + §8).
///
/// Date strip, cards with a priority stripe (1 rose, 2 amber, 3 slate), an
/// expandable « Pourquoi ? » listing the factors, action chips mapped by
/// `kind`, swipe-to-ignore with undo and a « Afficher les ignorées » toggle.
class RecommendationsScreen extends StatefulWidget {
  const RecommendationsScreen({super.key, this.onNavigate});

  /// Navigation rapide vers une autre section (slug).
  final ValueChanged<String>? onNavigate;

  @override
  State<RecommendationsScreen> createState() => _RecommendationsScreenState();
}

class _RecommendationsScreenState extends State<RecommendationsScreen> {
  /// Cache slug invalidated by `AddToMealSheet` after every add.
  static const String _cacheKey = 'recommendations';

  final RecommendationService _service = RecommendationService();

  DateTime _date = today();
  bool _showIgnored = false;
  List<Recommendation>? _items;
  bool _loading = true;
  String? _error;
  String? _refreshError;

  /// Only the default view (today, ignorées masquées) matches the cached payload.
  bool get _isDefaultView => _date == today() && !_showIgnored;

  @override
  void initState() {
    super.initState();
    final cached = Session.instance.cached(_cacheKey);
    if (cached != null) {
      _items = _parse(cached.data);
      _loading = false;
      if (cached.isStale()) _load(silent: true);
    } else {
      _load();
    }
  }

  List<Recommendation> _parse(Map<String, dynamic> data) {
    final list = ApiClient.asList(data['data']).map(Recommendation.fromJson).toList();
    list.sort((a, b) => a.priority.compareTo(b.priority));
    return list;
  }

  Future<void> _load({bool silent = false}) async {
    final useCache = _isDefaultView;
    if (!silent) {
      setState(() {
        _loading = _items == null;
        _error = null;
        _refreshError = null;
      });
    }
    try {
      final data = await _service.raw(date: _date, all: _showIgnored);
      if (!mounted) return;
      if (useCache) Session.instance.put(_cacheKey, data);
      setState(() {
        _items = _parse(data);
        _loading = false;
        _error = null;
        _refreshError = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (_items == null) {
          _error = e.message;
        } else {
          _refreshError = e.message;
        }
      });
      if (_items != null && !silent) _snack(e.message);
    }
  }

  void _snack(String message, {SnackBarAction? action}) {
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(
      SnackBar(content: Text(message), action: action),
    );
  }

  void _navigate(String slug) => widget.onNavigate?.call(slug);

  void _onDateChanged(DateTime date) {
    setState(() {
      _date = DateTime(date.year, date.month, date.day);
      _items = null;
      _loading = true;
    });
    _load();
  }

  void _toggleIgnored(bool value) {
    setState(() {
      _showIgnored = value;
      _items = null;
      _loading = true;
    });
    _load();
  }

  // ---------------------------------------------------------------- statut ---

  /// Swipe → `PUT /recommendations/{id} {status: ignoree}`; the row only leaves
  /// the list once the server answered 2xx.
  Future<bool> _ignore(Recommendation reco) async {
    try {
      await _service.ignore(reco.id);
    } on ApiException catch (e) {
      if (!mounted) return false;
      _snack(e.message);
      return false;
    }
    if (!mounted) return false;
    Session.instance.invalidate(_cacheKey);
    setState(() => _items = [
          for (final r in _items ?? const <Recommendation>[])
            if (r.id == reco.id) r.copyWith(status: 'ignoree') else r,
        ]);
    _snack(
      'Conseil ignoré.',
      action: SnackBarAction(label: AppStrings.undo, onPressed: () => _restore(reco)),
    );
    return true;
  }

  /// Undo / « Rétablir » → `PUT /recommendations/{id} {status: new}`.
  Future<void> _restore(Recommendation reco) async {
    try {
      await _service.setStatus(reco.id, 'new');
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.message);
      return;
    }
    if (!mounted) return;
    Session.instance.invalidate(_cacheKey);
    await _load(silent: true);
  }

  // --------------------------------------------------------------- actions ---

  Future<void> _runAction(Recommendation reco, RecoAction action) async {
    switch (action.kind) {
      case 'ajouter_au_repas':
        await _addToMeal(reco, action);
      case 'ouvrir_recette':
        await _openRecipe(action);
      case 'ouvrir_stock':
        _navigate('stock');
      case 'generer_seance':
        _navigate('sport');
      case 'ajouter_courses':
        await _addToShopping(reco, action);
      case 'ouvrir_planificateur':
        _navigate('planificateur-semaine');
      case 'supprimer_stock':
        await _deleteStockItem(action);
      default:
        _snack('Cette action n’est pas disponible.');
    }
  }

  Future<void> _addToMeal(Recommendation reco, RecoAction action) async {
    final hasTarget = action.foodId != null || action.recipeId != null;
    final result = await AddToMealSheet.show(
      context,
      date: _date,
      mealType: action.mealType,
      preset: hasTarget
          ? AddToMealPreset.ids(
              foodId: action.foodId,
              recipeId: action.recipeId,
              quantity: action.quantity,
              unit: action.unit,
            )
          : null,
      mode: hasTarget ? AddToMealMode.portionOnly : AddToMealMode.search,
    );
    if (result != null && mounted) await _load(silent: true);
  }

  Future<void> _openRecipe(RecoAction action) async {
    final recipeId = action.recipeId;
    if (recipeId == null) {
      _navigate('recettes');
      return;
    }
    final height = (MediaQuery.sizeOf(context).height * 0.85).clamp(420.0, 760.0);
    final recipe = await showModalBottomSheet<Recipe>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      useRootNavigator: true,
      builder: (_) => SizedBox(height: height, child: _RecipeDetailSheet(recipeId: recipeId)),
    );
    if (recipe == null || !mounted) return;
    final result = await AddToMealSheet.show(
      context,
      date: _date,
      mealType: action.mealType,
      preset: AddToMealPreset.recipe(recipe, quantity: action.quantity ?? 1),
      mode: AddToMealMode.portionOnly,
    );
    if (result != null && mounted) await _load(silent: true);
  }

  Future<void> _addToShopping(Recommendation reco, RecoAction action) async {
    final label = (action.label ?? '').trim().isEmpty ? reco.title : action.label!.trim();
    try {
      await ShoppingService().add(label: label, quantity: action.quantity, unit: action.unit);
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.message);
      return;
    }
    if (!mounted) return;
    Session.instance.invalidate('shopping');
    _snack(
      '$label ajouté à ta liste de courses.',
      action: SnackBarAction(label: 'Voir', onPressed: () => _navigate('liste-course')),
    );
  }

  Future<void> _deleteStockItem(RecoAction action) async {
    final id = action.stockItemId ?? (action.stockItemIds.isEmpty ? null : action.stockItemIds.first);
    if (id == null) {
      _navigate('stock');
      return;
    }
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'Retirer du stock ?',
      message: 'L’article sera supprimé de ton stock. Cette action est définitive.',
      confirmLabel: AppStrings.delete,
      destructive: true,
      icon: Icons.delete_outline_rounded,
    );
    if (!confirmed || !mounted) return;
    try {
      await StockService().deleteItem(id);
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.message);
      return;
    }
    if (!mounted) return;
    Session.instance.invalidate('stock');
    Session.instance.invalidate('dashboard');
    _snack('Article retiré de ton stock.');
    await _load(silent: true);
  }

  Future<void> _logMeal() async {
    final result = await AddToMealSheet.show(context, date: _date);
    if (result != null && mounted) await _load(silent: true);
  }

  // ----------------------------------------------------------------- build ---

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
          child: Column(
            children: [
              DateStrip(date: _date, onChanged: _onDateChanged),
              _IgnoredToggle(value: _showIgnored, onChanged: _toggleIgnored),
            ],
          ),
        ),
        Expanded(child: _body()),
      ],
    );
  }

  Widget _body() {
    if (_loading && _items == null) return const LoadingState(skeleton: true, skeletonCount: 3);
    if (_error != null && _items == null) return ErrorState(message: _error!, onRetry: _load);

    final items = _items ?? const <Recommendation>[];
    final visible = _showIgnored ? items : items.where((r) => !r.isIgnored).toList();

    return RefreshIndicator(
      onRefresh: () => _load(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 96),
        children: [
          if (_refreshError != null)
            StatusBanner.warning(
              'Données peut-être obsolètes : $_refreshError',
              margin: const EdgeInsets.only(bottom: 12),
              onClose: () => setState(() => _refreshError = null),
            ),
          if (visible.isEmpty)
            SizedBox(
              height: 340,
              child: EmptyState(
                icon: Icons.tips_and_updates_outlined,
                title: 'Ton coach n’a rien à signaler aujourd’hui',
                message: _showIgnored
                    ? 'Aucun conseil pour cette journée. Enregistre tes repas pour que le coach puisse t’aider.'
                    : 'Aucun conseil pour cette journée. Enregistre tes repas pour que le coach puisse t’aider.',
                ctaLabel: 'Enregistrer un repas',
                onCta: _logMeal,
              ),
            )
          else
            for (final reco in visible)
              Padding(
                padding: const EdgeInsets.only(bottom: 14),
                child: _RecoCard(
                  key: ValueKey('reco-${reco.id}'),
                  reco: reco,
                  onIgnore: () => _ignore(reco),
                  onRestore: () => _restore(reco),
                  onAction: (action) => _runAction(reco, action),
                ),
              ),
          const SizedBox(height: 4),
          const Text(
            AppStrings.disclaimer,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 11.5, color: MaviohColors.muted, height: 1.4),
          ),
        ],
      ),
    );
  }
}

/// « Afficher les ignorées » toggle (adds `all=1` to the request).
class _IgnoredToggle extends StatelessWidget {
  final bool value;
  final ValueChanged<bool> onChanged;

  const _IgnoredToggle({required this.value, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: () => onChanged(!value),
      child: SizedBox(
        height: 48,
        child: Row(
          children: [
            const Icon(Icons.visibility_off_outlined, size: 18, color: MaviohColors.muted),
            const SizedBox(width: 8),
            const Expanded(
              child: Text(
                'Afficher les ignorées',
                style: TextStyle(fontWeight: FontWeight.w600, color: MaviohColors.textSecondary),
              ),
            ),
            Switch(value: value, onChanged: onChanged),
          ],
        ),
      ),
    );
  }
}

/// One recommendation: priority stripe, type icon, title, message,
/// « Pourquoi ? » (factors) and the action chips.
class _RecoCard extends StatefulWidget {
  final Recommendation reco;
  final Future<bool> Function() onIgnore;
  final VoidCallback onRestore;
  final ValueChanged<RecoAction> onAction;

  const _RecoCard({
    super.key,
    required this.reco,
    required this.onIgnore,
    required this.onRestore,
    required this.onAction,
  });

  @override
  State<_RecoCard> createState() => _RecoCardState();
}

class _RecoCardState extends State<_RecoCard> {
  bool _expanded = false;

  static Color toneFor(int priority) {
    switch (priority) {
      case 1:
        return MaviohColors.rose;
      case 2:
        return MaviohColors.amber;
      default:
        return MaviohColors.slate;
    }
  }

  static String priorityLabel(int priority) {
    switch (priority) {
      case 1:
        return 'Sécurité';
      case 2:
        return 'Objectif du jour';
      default:
        return 'Confort';
    }
  }

  static IconData iconFor(String type) {
    switch (type) {
      case 'sous_plancher':
        return Icons.trending_down_rounded;
      case 'produit_perime':
        return Icons.warning_amber_rounded;
      case 'alerte_budget':
        return Icons.speed_rounded;
      case 'budget_restant':
        return Icons.restaurant_outlined;
      case 'manque_proteines':
        return Icons.egg_alt_outlined;
      case 'anti_gaspillage':
        return Icons.recycling_outlined;
      case 'suggestion_repas':
        return Icons.local_dining_outlined;
      case 'ajustement_portions':
        return Icons.balance_rounded;
      case 'sport_pre':
        return Icons.directions_run_rounded;
      case 'sport_post':
        return Icons.bolt_outlined;
      case 'courses':
        return Icons.shopping_cart_outlined;
      case 'hydratation':
        return Icons.water_drop_outlined;
      case 'profil_incomplet':
        return Icons.person_outline_rounded;
      default:
        return Icons.tips_and_updates_outlined;
    }
  }

  static IconData actionIcon(String kind) {
    switch (kind) {
      case 'ajouter_au_repas':
        return Icons.add_rounded;
      case 'ouvrir_recette':
        return Icons.menu_book_outlined;
      case 'ouvrir_stock':
        return Icons.kitchen_outlined;
      case 'generer_seance':
        return Icons.fitness_center_outlined;
      case 'ajouter_courses':
        return Icons.shopping_cart_outlined;
      case 'ouvrir_planificateur':
        return Icons.calendar_month_outlined;
      case 'supprimer_stock':
        return Icons.delete_outline_rounded;
      default:
        return Icons.arrow_forward_rounded;
    }
  }

  @override
  Widget build(BuildContext context) {
    final reco = widget.reco;
    final card = _card(reco);
    if (reco.isIgnored) return card;

    return Dismissible(
      key: ValueKey('dismiss-${reco.id}'),
      direction: DismissDirection.horizontal,
      background: const _IgnoreBackground(alignment: Alignment.centerLeft),
      secondaryBackground: const _IgnoreBackground(alignment: Alignment.centerRight),
      confirmDismiss: (_) => widget.onIgnore(),
      child: card,
    );
  }

  Widget _card(Recommendation reco) {
    final tone = toneFor(reco.priority);
    return AppCard(
      padding: EdgeInsets.zero,
      borderColor: reco.isIgnored ? MaviohColors.border : tone.withValues(alpha: 0.28),
      child: IntrinsicHeight(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Container(width: 6, color: reco.isIgnored ? MaviohColors.border : tone),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(14, 14, 14, 12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Container(
                          width: 38,
                          height: 38,
                          decoration: BoxDecoration(
                            color: MaviohColors.tint(tone),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: Icon(iconFor(reco.type), color: tone, size: 20),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                priorityLabel(reco.priority).toUpperCase(),
                                style: TextStyle(
                                  fontSize: 10.5,
                                  letterSpacing: 0.9,
                                  fontWeight: FontWeight.w700,
                                  color: reco.isIgnored ? MaviohColors.muted : tone,
                                ),
                              ),
                              const SizedBox(height: 2),
                              Text(
                                reco.title,
                                style: const TextStyle(
                                  fontSize: 15.5,
                                  fontWeight: FontWeight.w800,
                                  color: MaviohColors.text,
                                  height: 1.25,
                                ),
                              ),
                            ],
                          ),
                        ),
                        if (reco.isEstimate) ...[
                          const SizedBox(width: 8),
                          const EstimatePill(),
                        ],
                      ],
                    ),
                    const SizedBox(height: 10),
                    Text(
                      reco.message,
                      style: const TextStyle(color: MaviohColors.textTertiary, height: 1.45, fontSize: 13.5),
                    ),
                    if (reco.factors.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Align(
                        alignment: Alignment.centerLeft,
                        child: TextButton.icon(
                          onPressed: () => setState(() => _expanded = !_expanded),
                          style: TextButton.styleFrom(
                            minimumSize: const Size(48, 48),
                            padding: const EdgeInsets.symmetric(horizontal: 8),
                          ),
                          icon: Icon(
                            _expanded ? Icons.expand_less_rounded : Icons.expand_more_rounded,
                            size: 18,
                          ),
                          label: const Text('Pourquoi ?'),
                        ),
                      ),
                      if (_expanded)
                        Container(
                          width: double.infinity,
                          margin: const EdgeInsets.only(top: 2, bottom: 6),
                          padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
                          decoration: BoxDecoration(
                            color: MaviohColors.surface,
                            borderRadius: BorderRadius.circular(14),
                            border: Border.all(color: MaviohColors.border),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text(
                                'Ce que le coach a regardé',
                                style: TextStyle(
                                  fontSize: 11.5,
                                  fontWeight: FontWeight.w700,
                                  letterSpacing: 0.4,
                                  color: MaviohColors.muted,
                                ),
                              ),
                              const SizedBox(height: 6),
                              for (final factor in reco.factors)
                                Padding(
                                  padding: const EdgeInsets.only(bottom: 4),
                                  child: Row(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      const Padding(
                                        padding: EdgeInsets.only(top: 6, right: 8),
                                        child: SizedBox(
                                          width: 5,
                                          height: 5,
                                          child: DecoratedBox(
                                            decoration: BoxDecoration(
                                              color: MaviohColors.muted,
                                              shape: BoxShape.circle,
                                            ),
                                          ),
                                        ),
                                      ),
                                      Expanded(
                                        child: Text(
                                          factor,
                                          style: const TextStyle(
                                            fontSize: 12.5,
                                            color: MaviohColors.textSecondary,
                                            height: 1.4,
                                          ),
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                            ],
                          ),
                        ),
                    ],
                    if (reco.actions.isNotEmpty) ...[
                      const SizedBox(height: 6),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          for (final action in reco.actions)
                            OutlinedButton.icon(
                              onPressed: () => widget.onAction(action),
                              style: OutlinedButton.styleFrom(
                                minimumSize: const Size(48, 48),
                                padding: const EdgeInsets.symmetric(horizontal: 14),
                                foregroundColor: MaviohColors.primary,
                              ),
                              icon: Icon(actionIcon(action.kind), size: 18),
                              label: Text(action.buttonLabel),
                            ),
                        ],
                      ),
                    ],
                    if (reco.isIgnored) ...[
                      const SizedBox(height: 6),
                      Row(
                        children: [
                          const TonePill(
                            label: 'Ignorée',
                            tone: MaviohColors.slate,
                            icon: Icons.visibility_off_outlined,
                          ),
                          const Spacer(),
                          TextButton.icon(
                            onPressed: widget.onRestore,
                            style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
                            icon: const Icon(Icons.undo_rounded, size: 18),
                            label: const Text('Rétablir'),
                          ),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _IgnoreBackground extends StatelessWidget {
  final Alignment alignment;

  const _IgnoreBackground({required this.alignment});

  @override
  Widget build(BuildContext context) {
    return Container(
      alignment: alignment,
      padding: const EdgeInsets.symmetric(horizontal: 22),
      decoration: BoxDecoration(
        color: MaviohColors.surface,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: MaviohColors.border),
      ),
      child: const Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.visibility_off_outlined, color: MaviohColors.muted, size: 20),
          SizedBox(width: 8),
          Text(
            'Ignorer',
            style: TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.muted),
          ),
        ],
      ),
    );
  }
}

/// Recipe detail opened by the `ouvrir_recette` action.
/// Pops with the [Recipe] when « Ajouter à un repas » is tapped, else `null`.
class _RecipeDetailSheet extends StatefulWidget {
  final int recipeId;

  const _RecipeDetailSheet({required this.recipeId});

  @override
  State<_RecipeDetailSheet> createState() => _RecipeDetailSheetState();
}

class _RecipeDetailSheetState extends State<_RecipeDetailSheet> {
  Recipe? _recipe;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final recipe = await RecipeService().get(widget.recipeId);
      if (!mounted) return;
      setState(() {
        _recipe = recipe;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const LoadingState(message: 'Chargement de la recette…');
    if (_error != null) return ErrorState(message: _error!, onRetry: _load);
    final recipe = _recipe!;
    final perServing = recipe.perServing;

    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 16),
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  RecipeThumb(imageUrl: recipe.imageUrl, size: 64),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          recipe.title,
                          style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          [
                            '${fmtDecimal(recipe.servings)} portion${recipe.servings > 1 ? 's' : ''}',
                            if (recipe.prepTimeMinutes != null) fmtMinutes(recipe.prepTimeMinutes),
                          ].join(' · '),
                          style: const TextStyle(color: MaviohColors.muted, fontWeight: FontWeight.w600, fontSize: 12.5),
                        ),
                      ],
                    ),
                  ),
                  if (recipe.isEstimate) const EstimatePill(),
                ],
              ),
              const SizedBox(height: 14),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      perServing.calories == null ? '—' : '${fmtKcal(perServing.calories)} / portion',
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  MacroPill.proteins(value: perServing.proteins),
                  MacroPill.carbs(value: perServing.carbs),
                  MacroPill.fat(value: perServing.fat),
                ],
              ),
              if ((recipe.description ?? '').trim().isNotEmpty) ...[
                const SizedBox(height: 16),
                Text(
                  recipe.description!.trim(),
                  style: const TextStyle(color: MaviohColors.textTertiary, height: 1.45),
                ),
              ],
              if (recipe.ingredients.isNotEmpty) ...[
                const SizedBox(height: 18),
                const Text(
                  'Ingrédients',
                  style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                ),
                const SizedBox(height: 8),
                for (final ingredient in recipe.ingredients)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Padding(
                          padding: EdgeInsets.only(top: 7, right: 8),
                          child: SizedBox(
                            width: 5,
                            height: 5,
                            child: DecoratedBox(
                              decoration: BoxDecoration(color: MaviohColors.accent, shape: BoxShape.circle),
                            ),
                          ),
                        ),
                        Expanded(
                          child: Text(
                            ingredient.name,
                            style: const TextStyle(color: MaviohColors.textSecondary, height: 1.4),
                          ),
                        ),
                        if (ingredient.amount != null)
                          Text(
                            fmtQty(ingredient.amount, ingredient.unit),
                            style: const TextStyle(color: MaviohColors.muted, fontWeight: FontWeight.w600, fontSize: 12.5),
                          ),
                      ],
                    ),
                  ),
              ],
            ],
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
            child: SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: () => Navigator.of(context).pop(recipe),
                icon: const Icon(Icons.add_rounded),
                label: const Text('Ajouter à un repas'),
              ),
            ),
          ),
        ),
      ],
    );
  }
}
