import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/recipe.dart';
import '../services/recipe_service.dart';
import '../theme/app_theme.dart';
import '../widgets/add_to_meal_sheet.dart';
import '../widgets/app_card.dart';
import '../widgets/barcode_scanner_sheet.dart';
import '../widgets/confirm_dialog.dart';
import '../widgets/empty_state.dart';
import '../widgets/error_state.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/recipe_tile.dart';
import '../widgets/section_header.dart';
import '../widgets/status_banner.dart';
import 'recipes/recipe_editor_sheet.dart';

/// Visibility filter of the list (§16.4).
enum RecipeScope { publiques, miennes, toutes }

/// Recettes (§5 + §16.4). Rendered inside the « Plus » tab: no Scaffold, no AppBar.
///
/// Everything goes through [RecipeService]: no Dio, no secure storage and no
/// direct Open Food Facts call (the editor resolves ingredients through
/// `POST /recipes/estimate`).
class RecipesScreen extends StatefulWidget {
  const RecipesScreen({super.key, this.onNavigate, this.service});

  /// Section slug callback provided by `HomeScreen`.
  final ValueChanged<String>? onNavigate;

  /// Injectable for tests.
  final RecipeService? service;

  @override
  State<RecipesScreen> createState() => _RecipesScreenState();
}

class _RecipesScreenState extends State<RecipesScreen> {
  static const Duration _debounceDelay = Duration(milliseconds: 400);

  late final RecipeService _service = widget.service ?? RecipeService();
  final _searchController = TextEditingController();

  Timer? _debounce;
  int _run = 0;

  List<Recipe> _recipes = const [];
  bool _loading = true;
  String? _error;
  String? _refreshError;

  RecipeScope _scope = RecipeScope.toutes;
  String? _tag;
  String? _eanFilter;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  // ----- Loading ------------------------------------------------------------

  Future<void> _load({bool silent = false}) async {
    final run = ++_run;
    if (!silent) {
      setState(() {
        _loading = _recipes.isEmpty;
        _error = null;
        _refreshError = null;
      });
    }
    try {
      final paged = await _service.list(
        q: _searchController.text.trim().isEmpty ? null : _searchController.text.trim(),
        tag: _tag,
      );
      if (!mounted || run != _run) return;
      setState(() {
        _recipes = paged.items;
        _loading = false;
        _error = null;
        _refreshError = null;
      });
    } on ApiException catch (e) {
      if (!mounted || run != _run) return;
      setState(() {
        _loading = false;
        if (_recipes.isEmpty) {
          _error = e.message;
        } else {
          _refreshError = e.message;
        }
      });
    }
  }

  void _onQueryChanged(String _) {
    _debounce?.cancel();
    _debounce = Timer(_debounceDelay, () => _load(silent: _recipes.isNotEmpty));
  }

  void _setScope(RecipeScope scope) => setState(() => _scope = scope);

  void _setTag(String? tag) {
    setState(() => _tag = _tag == tag ? null : tag);
    _load(silent: _recipes.isNotEmpty);
  }

  Future<void> _scanIngredient() async {
    final code = await BarcodeScannerSheet.show(context);
    if (code == null || !mounted) return;
    setState(() => _eanFilter = code);
  }

  List<Recipe> get _visible {
    return _recipes.where((recipe) {
      switch (_scope) {
        case RecipeScope.publiques:
          if (!recipe.isPublic) return false;
        case RecipeScope.miennes:
          if (!recipe.isOwner) return false;
        case RecipeScope.toutes:
          break;
      }
      final ean = _eanFilter;
      if (ean != null && !recipe.ingredients.any((i) => i.ean == ean)) return false;
      return true;
    }).toList();
  }

  String get _headerTitle {
    switch (_scope) {
      case RecipeScope.publiques:
        return 'Recettes publiques';
      case RecipeScope.miennes:
        return 'Mes recettes';
      case RecipeScope.toutes:
        return 'Toutes les recettes';
    }
  }

  String get _headerSubtitle {
    switch (_scope) {
      case RecipeScope.publiques:
        return 'Les recettes partagées par la communauté Mavi’oh.';
      case RecipeScope.miennes:
        return 'Les recettes que tu as créées : modifie-les ou publie-les.';
      case RecipeScope.toutes:
        return 'Tes recettes et celles de la communauté, au même endroit.';
    }
  }

  // ----- Actions ------------------------------------------------------------

  Future<void> _create() async {
    final recipe = await RecipeEditorSheet.show(context, service: widget.service);
    if (recipe == null || !mounted) return;
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(
      SnackBar(content: Text('Recette « ${recipe.title} » créée.')),
    );
    await _load(silent: true);
  }

  Future<void> _edit(Recipe recipe) async {
    final updated = await RecipeEditorSheet.show(context, recipe: recipe, service: widget.service);
    if (updated == null || !mounted) return;
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(
      const SnackBar(content: Text('Recette mise à jour.')),
    );
    await _load(silent: true);
  }

  Future<void> _delete(Recipe recipe) async {
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'Supprimer « ${recipe.title} » ?',
      message: 'Cette recette sera définitivement supprimée.',
      confirmLabel: 'Supprimer',
      destructive: true,
      icon: Icons.delete_outline_rounded,
    );
    if (!confirmed || !mounted) return;
    try {
      await _service.delete(recipe.id);
      if (!mounted) return;
      Session.instance.invalidate('recipes');
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        const SnackBar(content: Text('Recette supprimée.')),
      );
      await _load(silent: true);
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _addToMeal(Recipe recipe) async {
    await AddToMealSheet.show(
      context,
      preset: AddToMealPreset.recipe(recipe),
      mode: AddToMealMode.portionOnly,
    );
  }

  Future<void> _openDetail(Recipe recipe) async {
    final action = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _RecipeDetailSheet(recipe: recipe),
    );
    if (action == null || !mounted) return;
    switch (action) {
      case 'meal':
        await _addToMeal(recipe);
      case 'edit':
        await _edit(recipe);
      case 'delete':
        await _delete(recipe);
    }
  }

  // ----- UI -----------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    if (_loading && _recipes.isEmpty) return const LoadingState(skeleton: true, skeletonCount: 4);
    if (_error != null && _recipes.isEmpty) return ErrorState(message: _error!, onRetry: _load);

    final visible = _visible;
    final featured = visible.isEmpty ? null : visible.first;
    final rest = visible.length <= 1 ? const <Recipe>[] : visible.sublist(1);

    return Stack(
      children: [
        RefreshIndicator(
          onRefresh: () => _load(silent: true),
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 110),
            children: [
              if (_refreshError != null)
                StatusBanner.warning(
                  'Données peut-être obsolètes : $_refreshError',
                  margin: const EdgeInsets.only(bottom: 12),
                  onClose: () => setState(() => _refreshError = null),
                ),
              Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _searchController,
                      textInputAction: TextInputAction.search,
                      onChanged: _onQueryChanged,
                      onSubmitted: (_) => _load(silent: _recipes.isNotEmpty),
                      decoration: const InputDecoration(
                        hintText: 'Chercher une recette',
                        prefixIcon: Icon(Icons.search_rounded),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  IconButton.filledTonal(
                    tooltip: 'Filtrer par code-barres d’ingrédient',
                    onPressed: _scanIngredient,
                    constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
                    icon: const Icon(Icons.qr_code_scanner_rounded),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              SegmentedButton<RecipeScope>(
                segments: const [
                  ButtonSegment<RecipeScope>(value: RecipeScope.publiques, label: Text('Publiques')),
                  ButtonSegment<RecipeScope>(value: RecipeScope.miennes, label: Text('Mes recettes')),
                  ButtonSegment<RecipeScope>(value: RecipeScope.toutes, label: Text('Toutes')),
                ],
                selected: {_scope},
                showSelectedIcon: false,
                onSelectionChanged: (values) => _setScope(values.first),
              ),
              const SizedBox(height: 12),
              SizedBox(
                height: 44,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  physics: const AlwaysScrollableScrollPhysics(),
                  children: [
                    for (final entry in AppStrings.recipeTagLabels.entries)
                      Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: FilterChip(
                          label: Text(entry.value),
                          selected: _tag == entry.key,
                          showCheckmark: false,
                          onSelected: (_) => _setTag(entry.key),
                        ),
                      ),
                  ],
                ),
              ),
              if (_eanFilter != null)
                Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: InputChip(
                      label: Text('Ingrédient $_eanFilter'),
                      avatar: const Icon(Icons.qr_code_rounded, size: 16),
                      onDeleted: () => setState(() => _eanFilter = null),
                    ),
                  ),
                ),
              const SizedBox(height: 14),
              SectionHeader(
                eyebrow: '${visible.length} recette${visible.length > 1 ? 's' : ''}',
                title: _headerTitle,
                subtitle: _headerSubtitle,
              ),
              if (featured == null)
                Padding(
                  padding: const EdgeInsets.only(top: 16),
                  child: EmptyState(
                    icon: Icons.menu_book_outlined,
                    title: _scope == RecipeScope.miennes ? 'Aucune recette personnelle' : 'Aucune recette',
                    message: _scope == RecipeScope.miennes
                        ? 'Crée ta première recette : ses calories par portion alimenteront tes repas et ton planificateur.'
                        : 'Change de filtre ou crée ta propre recette pour la retrouver ici.',
                    ctaLabel: 'Nouvelle recette',
                    onCta: _create,
                  ),
                )
              else ...[
                _FeaturedRecipeCard(
                  recipe: featured,
                  onOpen: () => _openDetail(featured),
                  onAddToMeal: () => _addToMeal(featured),
                  onEdit: featured.isOwner ? () => _edit(featured) : null,
                  onDelete: featured.isOwner ? () => _delete(featured) : null,
                ),
                const SizedBox(height: 16),
                for (final recipe in rest)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: RecipeTile(
                      key: ValueKey('recipe-${recipe.id}'),
                      recipe: recipe,
                      onTap: () => _openDetail(recipe),
                      trailing: IconButton(
                        tooltip: 'Ajouter à un repas',
                        onPressed: () => _addToMeal(recipe),
                        icon: const Icon(Icons.add_circle_outline_rounded),
                      ),
                    ),
                  ),
              ],
            ],
          ),
        ),
        Positioned(
          right: 16,
          bottom: 16,
          child: FloatingActionButton.extended(
            heroTag: 'recipes-add-fab',
            onPressed: _create,
            tooltip: 'Créer une recette',
            icon: const Icon(Icons.add_rounded),
            label: const Text('Nouvelle recette'),
          ),
        ),
      ],
    );
  }
}

/// « — » when the server has no per-serving value (§16.4).
String perServingLabel(double? value) => value == null ? '—' : fmtKcal(value);

/// Big card for the first visible recipe.
class _FeaturedRecipeCard extends StatelessWidget {
  const _FeaturedRecipeCard({
    required this.recipe,
    required this.onOpen,
    required this.onAddToMeal,
    this.onEdit,
    this.onDelete,
  });

  final Recipe recipe;
  final VoidCallback onOpen;
  final VoidCallback onAddToMeal;
  final VoidCallback? onEdit;
  final VoidCallback? onDelete;

  @override
  Widget build(BuildContext context) {
    final perServing = recipe.perServing;
    return AppCard.hero(
      onTap: onOpen,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              RecipeThumb(imageUrl: recipe.imageUrl, size: 76),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      recipe.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      [
                        '${perServingLabel(perServing.calories)} / portion',
                        if (recipe.prepTimeMinutes != null) '${recipe.prepTimeMinutes}${nbsp}min',
                        '${recipe.ingredientsCount} ingrédient${recipe.ingredientsCount > 1 ? 's' : ''}',
                      ].join(' · '),
                      style: const TextStyle(fontSize: 13, color: MaviohColors.textTertiary),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              MacroPill.proteins(value: perServing.proteins, compact: true),
              MacroPill.carbs(value: perServing.carbs, compact: true),
              MacroPill.fat(value: perServing.fat, compact: true),
              if (recipe.isEstimate) const EstimatePill(),
              if (recipe.isPublic) const TonePill(label: 'Publique', tone: MaviohColors.sky, icon: Icons.public_rounded),
              if (recipe.isOwner) const TonePill(label: 'Ma recette', tone: MaviohColors.primary, icon: Icons.person_outline),
            ],
          ),
          if (recipe.description != null && recipe.description!.isNotEmpty) ...[
            const SizedBox(height: 10),
            Text(
              recipe.description!,
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 13, color: MaviohColors.textSecondary, height: 1.4),
            ),
          ],
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 4,
            children: [
              FilledButton.icon(
                onPressed: onAddToMeal,
                icon: const Icon(Icons.restaurant_rounded, size: 18),
                label: const Text('Ajouter à un repas'),
                style: FilledButton.styleFrom(minimumSize: const Size(48, 48)),
              ),
              if (onEdit != null)
                OutlinedButton.icon(
                  onPressed: onEdit,
                  icon: const Icon(Icons.edit_outlined, size: 18),
                  label: const Text('Modifier'),
                  style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
                ),
              if (onDelete != null)
                TextButton.icon(
                  onPressed: onDelete,
                  icon: const Icon(Icons.delete_outline_rounded, size: 18),
                  label: const Text('Supprimer'),
                  style: TextButton.styleFrom(minimumSize: const Size(48, 48), foregroundColor: MaviohColors.error),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Detail sheet: ingredients + the three actions (pops with the chosen action).
class _RecipeDetailSheet extends StatelessWidget {
  const _RecipeDetailSheet({required this.recipe});

  final Recipe recipe;

  @override
  Widget build(BuildContext context) {
    final perServing = recipe.perServing;
    return SizedBox(
      height: MediaQuery.sizeOf(context).height * 0.85,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 4, 10, 4),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    recipe.title,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
                  ),
                ),
                IconButton(
                  tooltip: AppStrings.close,
                  onPressed: () => Navigator.of(context).pop(),
                  icon: const Icon(Icons.close_rounded),
                ),
              ],
            ),
          ),
          Expanded(
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 20),
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    RecipeThumb(imageUrl: recipe.imageUrl, size: 84),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            '${perServingLabel(perServing.calories)} / portion',
                            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: MaviohColors.text),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            '${fmtDecimal(recipe.servings, decimals: recipe.servings == recipe.servings.roundToDouble() ? 0 : 1)} portion${recipe.servings > 1 ? 's' : ''}'
                            '${recipe.calories == null ? '' : ' · ${fmtKcal(recipe.calories)} au total'}',
                            style: const TextStyle(fontSize: 13, color: MaviohColors.textTertiary),
                          ),
                          const SizedBox(height: 8),
                          Wrap(
                            spacing: 6,
                            runSpacing: 6,
                            children: [
                              MacroPill.proteins(value: perServing.proteins, compact: true),
                              MacroPill.carbs(value: perServing.carbs, compact: true),
                              MacroPill.fat(value: perServing.fat, compact: true),
                              if (recipe.isEstimate) const EstimatePill(),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                if (recipe.tags.isNotEmpty) ...[
                  const SizedBox(height: 14),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final tag in recipe.tags)
                        Chip(label: Text(AppStrings.recipeTagLabels[tag] ?? tag)),
                    ],
                  ),
                ],
                if (recipe.description != null && recipe.description!.isNotEmpty) ...[
                  const SizedBox(height: 14),
                  Text(
                    recipe.description!,
                    style: const TextStyle(fontSize: 13.5, color: MaviohColors.textSecondary, height: 1.45),
                  ),
                ],
                const SizedBox(height: 18),
                const Text(
                  'Ingrédients',
                  style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                ),
                const SizedBox(height: 8),
                if (recipe.ingredients.isEmpty)
                  const Text(
                    'Aucun ingrédient renseigné.',
                    style: TextStyle(fontSize: 13, color: MaviohColors.muted),
                  )
                else
                  for (final ingredient in recipe.ingredients)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Padding(
                            padding: EdgeInsets.only(top: 4, right: 8),
                            child: Icon(Icons.circle, size: 6, color: MaviohColors.muted),
                          ),
                          Expanded(
                            child: Text(
                              ingredient.amount == null
                                  ? ingredient.name
                                  : '${fmtQty(ingredient.amount, ingredient.unit)} · ${ingredient.name}',
                              style: const TextStyle(fontSize: 13.5, color: MaviohColors.textSecondary),
                            ),
                          ),
                        ],
                      ),
                    ),
              ],
            ),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 12),
              child: Row(
                children: [
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: () => Navigator.of(context).pop('meal'),
                      icon: const Icon(Icons.restaurant_rounded, size: 18),
                      label: const Text('Ajouter à un repas'),
                      style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                    ),
                  ),
                  if (recipe.isOwner) ...[
                    const SizedBox(width: 8),
                    IconButton.outlined(
                      tooltip: 'Modifier la recette',
                      onPressed: () => Navigator.of(context).pop('edit'),
                      icon: const Icon(Icons.edit_outlined),
                    ),
                    IconButton.outlined(
                      tooltip: 'Supprimer la recette',
                      onPressed: () => Navigator.of(context).pop('delete'),
                      icon: const Icon(Icons.delete_outline_rounded, color: MaviohColors.error),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
