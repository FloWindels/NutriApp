import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/strings.dart';
import '../models/food.dart';
import '../services/food_service.dart';
import '../theme/app_theme.dart';
import '../widgets/add_to_meal_sheet.dart';
import '../widgets/app_card.dart';
import '../widgets/barcode_scanner_sheet.dart';
import '../widgets/empty_state.dart';
import '../widgets/error_state.dart';
import '../widgets/food_tile.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/stat_tile.dart';
import '../widgets/status_banner.dart';
import 'food_search/food_form_sheet.dart';
import 'food_search/food_stock_sheet.dart';

/// Which segment of the screen is visible.
enum _FoodTab { search, favorites }

/// Recherche d’aliments (§16.4).
///
/// Single field (name / brand / EAN) + scan button, debounced
/// `GET /foods/search?off=1` with a request-id guard, favourites segment and a
/// detail card with « Ajouter à un repas », « Ajouter au stock » et
/// « Modifier ». No direct Open Food Facts call: everything goes through the
/// API via [FoodService].
class FoodSearchScreen extends StatefulWidget {
  const FoodSearchScreen({super.key, this.onNavigate});

  /// Navigate to another section (slug from `AppSections`).
  final ValueChanged<String>? onNavigate;

  @override
  State<FoodSearchScreen> createState() => _FoodSearchScreenState();
}

class _FoodSearchScreenState extends State<FoodSearchScreen> {
  static const Duration _debounceDelay = Duration(milliseconds: 350);

  final _service = FoodService();
  final _controller = TextEditingController();
  final _focus = FocusNode();

  Timer? _debounce;
  int _requestId = 0;

  _FoodTab _tab = _FoodTab.search;

  // Search state
  List<Food> _results = const [];
  PageMeta _meta = const PageMeta();
  String _lastQuery = '';
  bool _searching = false;
  bool _loadingMore = false;
  String? _searchError;
  bool _hasSearched = false;

  // Favourites state
  List<Food>? _favorites;
  bool _favoritesLoading = false;
  String? _favoritesError;

  // Detail state
  Food? _selected;
  bool _detailLoading = false;
  final Set<int> _favoritePending = <int>{};

  @override
  void dispose() {
    _debounce?.cancel();
    _controller.dispose();
    _focus.dispose();
    super.dispose();
  }

  // ----- Helpers -------------------------------------------------------------

  bool _isBarcode(String value) => RegExp(r'^\d{8,14}$').hasMatch(value);

  void _snack(String message) {
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(message)));
  }

  bool _canEdit(Food food) => food.isOwner || (food.isFromOpenFoodFacts && food.createdByUserId == null);

  /// Quantity/unit used when opening the add-to-meal sheet for [food].
  (double, String) _defaultPortion(Food food) {
    if (food.servingSizeG != null) return (1, 'portion');
    return (100, food.isLiquid ? 'ml' : 'g');
  }

  // ----- Search --------------------------------------------------------------

  void _onQueryChanged(String value) {
    _debounce?.cancel();
    final query = value.trim();
    if (query.isEmpty) {
      _requestId++;
      setState(() {
        _results = const [];
        _meta = const PageMeta();
        _lastQuery = '';
        _searching = false;
        _searchError = null;
        _hasSearched = false;
      });
      return;
    }
    if (query.length < 2 && !_isBarcode(query)) return;
    _debounce = Timer(_debounceDelay, () => _search(query));
  }

  Future<void> _search(String query, {bool showSpinner = true}) async {
    final trimmed = query.trim();
    if (trimmed.isEmpty) return;
    final id = ++_requestId;
    setState(() {
      _lastQuery = trimmed;
      _searching = showSpinner;
      _searchError = null;
      _hasSearched = true;
    });
    try {
      if (_isBarcode(trimmed)) {
        final food = await _service.tryByBarcode(trimmed);
        if (!mounted || id != _requestId) return;
        setState(() {
          _results = food == null ? const [] : [food];
          _meta = const PageMeta();
          _searching = false;
        });
        if (food != null) _openDetail(food);
        return;
      }
      final paged = await _service.search(trimmed, off: true);
      if (!mounted || id != _requestId) return;
      setState(() {
        _results = paged.items;
        _meta = paged.meta;
        _searching = false;
      });
    } on ApiException catch (e) {
      if (!mounted || id != _requestId) return;
      setState(() {
        _searching = false;
        _searchError = e.message;
      });
    }
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_meta.hasMore || _lastQuery.isEmpty) return;
    final id = _requestId;
    setState(() => _loadingMore = true);
    try {
      final paged = await _service.search(_lastQuery, off: true, page: _meta.currentPage + 1);
      if (!mounted || id != _requestId) return;
      setState(() {
        _results = [..._results, ...paged.items];
        _meta = paged.meta;
        _loadingMore = false;
      });
    } on ApiException catch (e) {
      if (!mounted || id != _requestId) return;
      setState(() => _loadingMore = false);
      _snack(e.message);
    }
  }

  Future<void> _scan() async {
    final code = await BarcodeScannerSheet.show(context);
    if (code == null || !mounted) return;
    _debounce?.cancel();
    _controller.text = code;
    setState(() => _tab = _FoodTab.search);
    await _search(code);
  }

  // ----- Favourites ----------------------------------------------------------

  Future<void> _loadFavorites({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _favoritesLoading = _favorites == null;
        _favoritesError = null;
      });
    }
    try {
      final list = await _service.favorites();
      if (!mounted) return;
      setState(() {
        _favorites = list;
        _favoritesLoading = false;
        _favoritesError = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _favoritesLoading = false;
        if (_favorites == null) {
          _favoritesError = e.message;
        } else if (!silent) {
          _snack(e.message);
        }
      });
    }
  }

  void _applyFavorite(int foodId, bool value) {
    _results = [
      for (final food in _results) food.id == foodId ? food.copyWith(isFavorite: value) : food,
    ];
    final favorites = _favorites;
    if (favorites != null) {
      final existing = favorites.where((f) => f.id == foodId).toList();
      if (value) {
        if (existing.isEmpty) {
          final source = _selected?.id == foodId
              ? _selected!
              : _results.where((f) => f.id == foodId).firstOrNull;
          if (source != null) _favorites = [source.copyWith(isFavorite: true), ...favorites];
        }
      } else {
        _favorites = favorites.where((f) => f.id != foodId).toList();
      }
    }
    if (_selected?.id == foodId) _selected = _selected!.copyWith(isFavorite: value);
  }

  Future<void> _toggleFavorite(Food food) async {
    if (_favoritePending.contains(food.id)) return;
    final next = !food.isFavorite;
    setState(() {
      _favoritePending.add(food.id);
      _applyFavorite(food.id, next);
    });
    try {
      if (next) {
        await _service.addFavorite(food.id);
      } else {
        await _service.removeFavorite(food.id);
      }
      if (!mounted) return;
      setState(() => _favoritePending.remove(food.id));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _favoritePending.remove(food.id);
        _applyFavorite(food.id, !next);
      });
      _snack(e.message);
    }
  }

  // ----- Detail --------------------------------------------------------------

  void _openDetail(Food food) {
    setState(() => _selected = food);
    _refreshDetail(food.id, silent: true);
  }

  Future<void> _refreshDetail(int id, {bool silent = false}) async {
    if (!silent) setState(() => _detailLoading = true);
    try {
      final food = await _service.get(id);
      if (!mounted || _selected?.id != id) return;
      setState(() {
        _selected = food;
        _detailLoading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _detailLoading = false);
      if (!silent) _snack(e.message);
    }
  }

  void _closeDetail() => setState(() => _selected = null);

  Future<void> _addToMeal(Food food) async {
    final (quantity, unit) = _defaultPortion(food);
    final result = await AddToMealSheet.show(
      context,
      preset: AddToMealPreset.food(food, quantity: quantity, unit: unit),
      mode: AddToMealMode.portionOnly,
    );
    if (result != null && mounted) setState(() {});
  }

  Future<void> _addToStock(Food food) async {
    final item = await FoodStockSheet.show(context, food: food);
    if (item == null || !mounted) return;
    _snack('${food.name} ajouté au stock (${item.stockName}).');
  }

  Future<void> _openForm({Food? food, String? barcode, String? name}) async {
    final result = await FoodFormSheet.show(context, food: food, initialBarcode: barcode, initialName: name);
    if (result == null || !mounted) return;
    setState(() {
      _selected = result.food;
      _results = [
        for (final item in _results) item.id == result.food.id ? result.food : item,
      ];
    });
    _snack(result.message ?? (result.alreadyExisted ? 'Produit déjà présent dans la base.' : 'Aliment enregistré.'));
  }

  // ----- Build ---------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    if (_selected != null) return _detailView(_selected!);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _header(),
        Expanded(child: _tab == _FoodTab.favorites ? _favoritesBody() : _searchBody()),
      ],
    );
  }

  Widget _header() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SegmentedButton<_FoodTab>(
            segments: const [
              ButtonSegment<_FoodTab>(
                value: _FoodTab.search,
                label: Text('Recherche'),
                icon: Icon(Icons.search_rounded),
              ),
              ButtonSegment<_FoodTab>(
                value: _FoodTab.favorites,
                label: Text('Favoris'),
                icon: Icon(Icons.favorite_rounded),
              ),
            ],
            selected: {_tab},
            onSelectionChanged: (values) {
              final tab = values.first;
              setState(() => _tab = tab);
              if (tab == _FoodTab.favorites && _favorites == null && !_favoritesLoading) {
                _loadFavorites();
              }
            },
          ),
          if (_tab == _FoodTab.search) ...[
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _controller,
                    focusNode: _focus,
                    textInputAction: TextInputAction.search,
                    decoration: InputDecoration(
                      hintText: 'Nom, marque ou code-barres',
                      prefixIcon: const Icon(Icons.search_rounded),
                      suffixIcon: _controller.text.isEmpty
                          ? null
                          : IconButton(
                              tooltip: 'Effacer la recherche',
                              icon: const Icon(Icons.close_rounded),
                              onPressed: () {
                                _controller.clear();
                                _onQueryChanged('');
                              },
                            ),
                    ),
                    onChanged: (value) {
                      setState(() {});
                      _onQueryChanged(value);
                    },
                    onSubmitted: (value) {
                      _debounce?.cancel();
                      _search(value);
                    },
                  ),
                ),
                const SizedBox(width: 10),
                IconButton.filledTonal(
                  tooltip: 'Scanner un code-barres',
                  onPressed: _scan,
                  icon: const Icon(Icons.qr_code_scanner_rounded),
                  style: IconButton.styleFrom(minimumSize: const Size(52, 52)),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  // ----- Search body ---------------------------------------------------------

  Widget _searchBody() {
    if (_searching && _results.isEmpty) return const LoadingState(skeleton: true, skeletonCount: 4);
    if (_searchError != null && _results.isEmpty) {
      return ErrorState(message: _searchError!, onRetry: () => _search(_lastQuery));
    }

    return RefreshIndicator(
      onRefresh: () async {
        if (_lastQuery.isEmpty) return;
        await _search(_lastQuery, showSpinner: false);
      },
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 96),
        children: [
          if (!_hasSearched)
            EmptyState(
              icon: Icons.travel_explore_rounded,
              title: 'Cherche un aliment',
              message:
                  'Tape un nom, une marque ou un code-barres, ou scanne un produit. Les résultats viennent de Mavi’oh et d’Open Food Facts.',
              ctaLabel: 'Créer un aliment',
              onCta: () => _openForm(),
              tone: MaviohColors.emerald,
            )
          else if (_results.isEmpty)
            EmptyState(
              icon: Icons.no_food_outlined,
              title: 'Aucun résultat',
              message: 'Rien ne correspond à « $_lastQuery ». Tu peux créer cet aliment toi-même.',
              ctaLabel: 'Créer un aliment',
              onCta: () => _openForm(
                barcode: _isBarcode(_lastQuery) ? _lastQuery : null,
                name: _isBarcode(_lastQuery) ? null : _lastQuery,
              ),
              tone: MaviohColors.emerald,
            )
          else ...[
            if (_meta.offQueried)
              const StatusBanner.info(
                'Résultats complétés par Open Food Facts',
                margin: EdgeInsets.only(bottom: 12),
              ),
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(
                _meta.total > 0
                    ? '${fmtInt(_meta.total)} résultat${_meta.total > 1 ? 's' : ''}'
                    : '${_results.length} résultat${_results.length > 1 ? 's' : ''}',
                style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, fontWeight: FontWeight.w600),
              ),
            ),
            for (final food in _results) ...[
              FoodTile(
                food: food,
                onTap: () => _openDetail(food),
                trailing: _favoriteButton(food),
              ),
              const SizedBox(height: 10),
            ],
            if (_meta.hasMore)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: OutlinedButton.icon(
                  onPressed: _loadingMore ? null : _loadMore,
                  icon: _loadingMore
                      ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.expand_more_rounded),
                  label: Text(_loadingMore ? 'Chargement…' : 'Voir plus'),
                  style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                ),
              ),
            const SizedBox(height: 16),
            TextButton.icon(
              onPressed: () => _openForm(name: _isBarcode(_lastQuery) ? null : _lastQuery),
              icon: const Icon(Icons.add_circle_outline_rounded),
              label: const Text('Aliment manquant ? Créer un aliment'),
              style: TextButton.styleFrom(minimumSize: const Size.fromHeight(48)),
            ),
          ],
        ],
      ),
    );
  }

  Widget _favoriteButton(Food food) {
    return IconButton(
      tooltip: food.isFavorite ? 'Retirer des favoris' : 'Ajouter aux favoris',
      onPressed: () => _toggleFavorite(food),
      icon: Icon(
        food.isFavorite ? Icons.favorite_rounded : Icons.favorite_border_rounded,
        color: food.isFavorite ? MaviohColors.rose : MaviohColors.muted,
      ),
      style: IconButton.styleFrom(minimumSize: const Size(48, 48)),
    );
  }

  // ----- Favourites body -----------------------------------------------------

  Widget _favoritesBody() {
    if (_favoritesLoading && _favorites == null) return const LoadingState(skeleton: true, skeletonCount: 3);
    if (_favoritesError != null && _favorites == null) {
      return ErrorState(message: _favoritesError!, onRetry: _loadFavorites);
    }
    final favorites = _favorites ?? const <Food>[];

    return RefreshIndicator(
      onRefresh: () => _loadFavorites(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 96),
        children: [
          if (favorites.isEmpty)
            EmptyState(
              icon: Icons.favorite_border_rounded,
              title: 'Aucun favori',
              message: 'Ajoute un aliment en favori depuis la recherche pour le retrouver ici en un geste.',
              ctaLabel: 'Chercher un aliment',
              onCta: () {
                setState(() => _tab = _FoodTab.search);
                _focus.requestFocus();
              },
              tone: MaviohColors.rose,
            )
          else
            for (final food in favorites) ...[
              FoodTile(
                food: food,
                onTap: () => _openDetail(food),
                trailing: _favoriteButton(food),
              ),
              const SizedBox(height: 10),
            ],
        ],
      ),
    );
  }

  // ----- Detail --------------------------------------------------------------

  Widget _detailView(Food food) {
    final per = food.isLiquid ? 'pour 100 ml' : 'pour 100 g';
    final micro = [
      if (food.fiber != null) ('Fibres', fmtGrams(food.fiber)),
      if (food.sugar != null) ('Sucres', fmtGrams(food.sugar)),
      if (food.salt != null) ('Sel', fmtGrams(food.salt)),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 6, 12, 2),
          child: Row(
            children: [
              IconButton(
                tooltip: 'Retour aux résultats',
                onPressed: _closeDetail,
                icon: const Icon(Icons.arrow_back_rounded),
              ),
              const Expanded(
                child: Text(
                  'Fiche produit',
                  style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                ),
              ),
              if (_detailLoading)
                const Padding(
                  padding: EdgeInsets.only(right: 8),
                  child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
                ),
              _favoriteButton(food),
            ],
          ),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () => _refreshDetail(food.id, silent: true),
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 96),
              children: [
                AppCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          FoodThumb(imageUrl: food.imageUrl, size: 72),
                          const SizedBox(width: 14),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  food.name,
                                  style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800, color: MaviohColors.text),
                                ),
                                if (food.brand != null) ...[
                                  const SizedBox(height: 3),
                                  Text(food.brand!, style: const TextStyle(fontSize: 13.5, color: MaviohColors.muted)),
                                ],
                                const SizedBox(height: 8),
                                Wrap(
                                  spacing: 6,
                                  runSpacing: 6,
                                  children: [
                                    TonePill(
                                      label: food.sourceLabel,
                                      tone: food.isVerified
                                          ? MaviohColors.success
                                          : (food.isFromOpenFoodFacts ? MaviohColors.sky : MaviohColors.slate),
                                    ),
                                    if (food.isEstimate) const EstimatePill(),
                                  ],
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      if (food.barcode != null) ...[
                        const SizedBox(height: 12),
                        Row(
                          children: [
                            const Icon(Icons.qr_code_rounded, size: 16, color: MaviohColors.muted),
                            const SizedBox(width: 6),
                            Text(
                              food.barcode!,
                              style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, letterSpacing: 0.4),
                            ),
                          ],
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  'Valeurs nutritionnelles $per',
                  style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(
                      child: StatTile(
                        label: AppStrings.calories,
                        value: food.calories == null ? '—' : fmtKcal(food.calories),
                        icon: Icons.local_fire_department_outlined,
                        tone: MaviohColors.emerald,
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: StatTile(
                        label: AppStrings.proteins,
                        value: fmtGrams(food.proteins),
                        icon: Icons.egg_alt_outlined,
                        tone: MaviohColors.proteins,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(
                      child: StatTile(
                        label: AppStrings.carbs,
                        value: fmtGrams(food.carbs),
                        icon: Icons.bakery_dining_outlined,
                        tone: MaviohColors.carbs,
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: StatTile(
                        label: AppStrings.fat,
                        value: fmtGrams(food.fat),
                        icon: Icons.water_drop_outlined,
                        tone: MaviohColors.fat,
                      ),
                    ),
                  ],
                ),
                if (micro.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      for (var i = 0; i < micro.length; i++) ...[
                        if (i > 0) const SizedBox(width: 10),
                        Expanded(
                          child: StatTile(
                            label: micro[i].$1,
                            value: micro[i].$2,
                            tone: MaviohColors.slate,
                          ),
                        ),
                      ],
                    ],
                  ),
                ],
                if (food.servingSizeG != null || food.category != null) ...[
                  const SizedBox(height: 16),
                  AppCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (food.servingSizeG != null)
                          _infoRow(
                            Icons.restaurant_outlined,
                            'Portion',
                            food.servingLabel == null
                                ? fmtGrams(food.servingSizeG)
                                : '${fmtGrams(food.servingSizeG)} · ${food.servingLabel}',
                          ),
                        if (food.servingSizeG != null && food.category != null) const SizedBox(height: 10),
                        if (food.category != null)
                          _infoRow(Icons.category_outlined, 'Catégorie', capitalize(food.category!)),
                      ],
                    ),
                  ),
                ],
                if (food.allergens.isNotEmpty) ...[
                  const SizedBox(height: 16),
                  const Text(
                    'Allergènes',
                    style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
                  ),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    children: [
                      for (final allergen in food.allergens)
                        TonePill(label: capitalize(allergen.replaceFirst(RegExp(r'^[a-z]{2}:'), '')), tone: MaviohColors.amber),
                    ],
                  ),
                ],
                const SizedBox(height: 20),
                FilledButton.icon(
                  onPressed: () => _addToMeal(food),
                  icon: const Icon(Icons.add_rounded),
                  label: const Text('Ajouter à un repas'),
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                ),
                const SizedBox(height: 10),
                OutlinedButton.icon(
                  onPressed: () => _addToStock(food),
                  icon: const Icon(Icons.kitchen_outlined),
                  label: const Text('Ajouter au stock'),
                  style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                ),
                if (_canEdit(food)) ...[
                  const SizedBox(height: 10),
                  TextButton.icon(
                    onPressed: () => _openForm(food: food),
                    icon: const Icon(Icons.edit_outlined),
                    label: const Text('Modifier'),
                    style: TextButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                  ),
                ],
                const SizedBox(height: 16),
                const Text(
                  AppStrings.offAttribution,
                  style: TextStyle(fontSize: 11.5, color: MaviohColors.muted, height: 1.4),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _infoRow(IconData icon, String label, String value) {
    return Row(
      children: [
        Icon(icon, size: 18, color: MaviohColors.muted),
        const SizedBox(width: 10),
        Text(label, style: const TextStyle(fontSize: 13.5, color: MaviohColors.muted)),
        const Spacer(),
        Flexible(
          child: Text(
            value,
            textAlign: TextAlign.end,
            style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: MaviohColors.text),
          ),
        ),
      ],
    );
  }
}
