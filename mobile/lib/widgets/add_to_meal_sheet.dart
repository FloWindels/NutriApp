import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/food.dart';
import '../models/meal.dart';
import '../models/portion.dart';
import '../models/recipe.dart';
import '../models/stock.dart';
import '../services/food_service.dart';
import '../services/meal_service.dart';
import '../services/recipe_service.dart';
import '../services/stock_service.dart';
import '../theme/app_theme.dart';
import 'barcode_scanner_sheet.dart';
import 'empty_state.dart';
import 'food_tile.dart';
import 'loading_state.dart';
import 'macro_pill.dart';
import 'quantity_unit_picker.dart';
import 'recipe_tile.dart';
import 'status_banner.dart';

/// `search` shows the full flow; `portionOnly` shows type + picker + CTA.
enum AddToMealMode { search, portionOnly }

/// What the sheet is preloaded with.
///
/// Tap budget (documented per §16.3): dashboard FAB → 3 taps + typing ·
/// frequent → 2 · food card → 2 · scan → 3 + scan · stock consume → 2 ·
/// recipe → 2 · recommendation → 2.
class AddToMealPreset {
  final Food? food;
  final Recipe? recipe;
  final StockItem? stockItem;
  final int? foodId;
  final int? recipeId;
  final double? quantity;
  final String? unit;

  const AddToMealPreset._({
    this.food,
    this.recipe,
    this.stockItem,
    this.foodId,
    this.recipeId,
    this.quantity,
    this.unit,
  });

  const AddToMealPreset.food(Food food, {double? quantity, String? unit})
      : this._(food: food, quantity: quantity, unit: unit);

  const AddToMealPreset.recipe(Recipe recipe, {double? quantity}) : this._(recipe: recipe, quantity: quantity, unit: 'portion');

  const AddToMealPreset.stockItem(StockItem item) : this._(stockItem: item);

  /// From a recommendation action: the food/recipe is fetched by id.
  const AddToMealPreset.ids({int? foodId, int? recipeId, double? quantity, String? unit})
      : this._(foodId: foodId, recipeId: recipeId, quantity: quantity, unit: unit);

  bool get isEmpty => food == null && recipe == null && stockItem == null && foodId == null && recipeId == null;
}

/// Returned when an item was added.
class AddToMealResult {
  final MealItem item;
  final int mealId;
  final String mealType;
  final DateTime date;
  final DaySummary? day;
  final StockDecrement? stockDecrement;
  final double? caloriesAdded;

  const AddToMealResult({
    required this.item,
    required this.mealId,
    required this.mealType,
    required this.date,
    this.day,
    this.stockDecrement,
    this.caloriesAdded,
  });
}

/// The single « ajouter à un repas » flow (§16.3).
class AddToMealSheet {
  AddToMealSheet._();

  /// Opens the sheet. Shows a SnackBar « Ajouté au déjeuner · 210 kcal » with
  /// « Annuler » on the caller's messenger after a successful add.
  static Future<AddToMealResult?> show(
    BuildContext context, {
    DateTime? date,
    String? mealType,
    AddToMealPreset? preset,
    AddToMealMode mode = AddToMealMode.search,
    double? maxQuantity,
    bool showUndoSnackBar = true,
  }) async {
    final messenger = ScaffoldMessenger.maybeOf(context);
    final height = MediaQuery.sizeOf(context).height * 0.92;
    final result = await showModalBottomSheet<AddToMealResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      useRootNavigator: true,
      builder: (_) => SizedBox(
        height: height,
        child: _AddToMealBody(
          date: date ?? today(),
          initialMealType: mealType ?? _defaultMealType(),
          preset: preset,
          mode: preset?.isEmpty == false && mode == AddToMealMode.search && preset?.stockItem != null
              ? AddToMealMode.portionOnly
              : mode,
          maxQuantity: maxQuantity,
        ),
      ),
    );
    if (result != null && showUndoSnackBar && messenger != null) {
      _showUndoSnackBar(messenger, result);
    }
    return result;
  }

  static String _defaultMealType() {
    final hour = DateTime.now().hour;
    if (hour < 11) return 'petit_dejeuner';
    if (hour < 15) return 'dejeuner';
    if (hour < 18) return 'collation';
    return 'diner';
  }

  static void _showUndoSnackBar(ScaffoldMessengerState messenger, AddToMealResult result) {
    final kcal = result.caloriesAdded ?? result.item.calories;
    final text = 'Ajouté ${AppStrings.mealTypeDative(result.mealType)} · ${fmtKcal(kcal)}';
    messenger.hideCurrentSnackBar();
    messenger.showSnackBar(
      SnackBar(
        content: Text(text),
        duration: const Duration(seconds: 5),
        action: SnackBarAction(
          label: AppStrings.undo,
          onPressed: () => _undo(messenger, result),
        ),
      ),
    );
  }

  static Future<void> _undo(ScaffoldMessengerState messenger, AddToMealResult result) async {
    try {
      if (result.item.id > 0) {
        await MealService().deleteItem(result.mealId, result.item.id);
      }
      final decrement = result.stockDecrement;
      if (decrement != null) {
        await StockService().updateItem(decrement.stockItemId, {
          'quantity': decrement.previousQuantity,
          'unit': decrement.unit,
        });
      }
      Session.instance.invalidatePrefix('meals');
      Session.instance.invalidate('dashboard');
      Session.instance.invalidate('stock');
      messenger.showSnackBar(const SnackBar(content: Text('Ajout annulé.')));
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text('Impossible d’annuler : ${e.message}')));
    }
  }
}

enum _Stage { search, picker, createFood, custom, loadingPreset }

enum _SearchTab { frequent, results, recipes }

class _AddToMealBody extends StatefulWidget {
  final DateTime date;
  final String initialMealType;
  final AddToMealPreset? preset;
  final AddToMealMode mode;
  final double? maxQuantity;

  const _AddToMealBody({
    required this.date,
    required this.initialMealType,
    this.preset,
    required this.mode,
    this.maxQuantity,
  });

  @override
  State<_AddToMealBody> createState() => _AddToMealBodyState();
}

class _AddToMealBodyState extends State<_AddToMealBody> {
  final _foodService = FoodService();
  final _recipeService = RecipeService();
  final _mealService = MealService();
  final _stockService = StockService();

  final _searchController = TextEditingController();
  final _searchFocus = FocusNode();

  late String _mealType;
  _Stage _stage = _Stage.search;
  _SearchTab _tab = _SearchTab.frequent;

  // Search state
  Timer? _debounce;
  int _requestId = 0;
  CancelToken? _cancelToken;
  bool _searching = false;
  List<Food> _results = const [];
  bool _offQueried = false;
  String? _searchError;

  // Frequent
  bool _frequentLoading = false;
  List<FrequentItem> _frequent = const [];

  // Recipes
  bool _recipesLoading = false;
  List<Recipe> _recipes = const [];
  String? _recipesError;

  // Selection
  Food? _selectedFood;
  Recipe? _selectedRecipe;
  StockItem? _stockItem;
  QuantitySelection? _selection;
  bool _decrementStock = true;

  // Create food form
  final _createName = TextEditingController();
  final _createBrand = TextEditingController();
  final _createKcal = TextEditingController();
  final _createP = TextEditingController();
  final _createG = TextEditingController();
  final _createL = TextEditingController();
  String? _createBarcode;
  Map<String, String> _createErrors = {};

  // Custom item form
  final _customLabel = TextEditingController();
  final _customKcal = TextEditingController();
  final _customP = TextEditingController();
  final _customG = TextEditingController();
  final _customL = TextEditingController();
  Map<String, String> _customErrors = {};

  bool _submitting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _mealType = widget.initialMealType;
    Session.instance.loadPortions();
    final preset = widget.preset;
    if (preset != null && !preset.isEmpty) {
      _applyPreset(preset);
    } else {
      _loadFrequent();
    }
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _cancelToken?.cancel();
    _searchController.dispose();
    _searchFocus.dispose();
    for (final c in [_createName, _createBrand, _createKcal, _createP, _createG, _createL, _customLabel, _customKcal, _customP, _customG, _customL]) {
      c.dispose();
    }
    super.dispose();
  }

  // ----- Preset -------------------------------------------------------------

  Future<void> _applyPreset(AddToMealPreset preset) async {
    if (preset.food != null) {
      _selectFood(preset.food!, quantity: preset.quantity, unit: preset.unit);
      return;
    }
    if (preset.recipe != null) {
      _selectRecipe(preset.recipe!, quantity: preset.quantity);
      return;
    }
    if (preset.stockItem != null) {
      _stockItem = preset.stockItem;
      _decrementStock = true;
      setState(() => _stage = _Stage.picker);
      return;
    }
    setState(() => _stage = _Stage.loadingPreset);
    try {
      if (preset.foodId != null) {
        final food = await _foodService.get(preset.foodId!);
        if (!mounted) return;
        _selectFood(food, quantity: preset.quantity, unit: preset.unit);
      } else if (preset.recipeId != null) {
        final recipe = await _recipeService.get(preset.recipeId!);
        if (!mounted) return;
        _selectRecipe(recipe, quantity: preset.quantity);
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _stage = _Stage.search;
      });
      _loadFrequent();
    }
  }

  // ----- Loading helpers ----------------------------------------------------

  Future<void> _loadFrequent() async {
    if (_frequent.isNotEmpty) return;
    setState(() => _frequentLoading = true);
    try {
      final list = await _mealService.frequent();
      if (!mounted) return;
      setState(() => _frequent = list);
    } on ApiException {
      // Silent: the search field remains usable.
    } finally {
      if (mounted) setState(() => _frequentLoading = false);
    }
  }

  Future<void> _loadRecipes([String query = '']) async {
    setState(() {
      _recipesLoading = true;
      _recipesError = null;
    });
    try {
      final page = await _recipeService.list(q: query.isEmpty ? null : query, perPage: 50);
      if (!mounted) return;
      setState(() => _recipes = page.items);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _recipesError = e.message);
    } finally {
      if (mounted) setState(() => _recipesLoading = false);
    }
  }

  void _onQueryChanged(String value) {
    _debounce?.cancel();
    final query = value.trim();
    if (_tab == _SearchTab.recipes) {
      _debounce = Timer(const Duration(milliseconds: 350), () => _loadRecipes(query));
      return;
    }
    if (query.isEmpty) {
      _cancelToken?.cancel();
      setState(() {
        _tab = _SearchTab.frequent;
        _results = const [];
        _searching = false;
        _searchError = null;
      });
      return;
    }
    setState(() => _tab = _SearchTab.results);
    _debounce = Timer(const Duration(milliseconds: 350), () => _runSearch(query));
  }

  Future<void> _runSearch(String query) async {
    final id = ++_requestId;
    _cancelToken?.cancel();
    final token = CancelToken();
    _cancelToken = token;
    setState(() {
      _searching = true;
      _searchError = null;
    });
    try {
      final page = await _foodService.search(query, off: true, cancelToken: token);
      if (!mounted || id != _requestId) return;
      setState(() {
        _results = page.items;
        _offQueried = page.meta.offQueried;
      });
    } on ApiException catch (e) {
      if (!mounted || id != _requestId) return;
      if (e.message == 'Requête annulée.') return;
      setState(() => _searchError = e.message);
    } finally {
      if (mounted && id == _requestId) setState(() => _searching = false);
    }
  }

  Future<void> _scan() async {
    final code = await BarcodeScannerSheet.show(context);
    if (code == null || !mounted) return;
    setState(() {
      _searching = true;
      _searchError = null;
      _tab = _SearchTab.results;
    });
    try {
      final food = await _foodService.tryByBarcode(code);
      if (!mounted) return;
      if (food != null) {
        _selectFood(food, quantity: food.servingSizeG != null ? 1 : 100, unit: food.servingSizeG != null ? 'portion' : 'g');
      } else {
        _createBarcode = code;
        _createErrors = {};
        setState(() => _stage = _Stage.createFood);
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _searchError = e.message);
    } finally {
      if (mounted) setState(() => _searching = false);
    }
  }

  // ----- Selection ----------------------------------------------------------

  void _selectFood(Food food, {double? quantity, String? unit}) {
    setState(() {
      _selectedFood = food;
      _selectedRecipe = null;
      _selection = null;
      _presetQuantity = quantity;
      _presetUnit = unit;
      _stage = _Stage.picker;
      _error = null;
    });
  }

  void _selectRecipe(Recipe recipe, {double? quantity}) {
    setState(() {
      _selectedRecipe = recipe;
      _selectedFood = null;
      _selection = null;
      _presetQuantity = quantity ?? 1;
      _presetUnit = 'portion';
      _stage = _Stage.picker;
      _error = null;
    });
  }

  double? _presetQuantity;
  String? _presetUnit;

  void _backToSearch() {
    setState(() {
      _stage = _Stage.search;
      _selectedFood = null;
      _selectedRecipe = null;
      _selection = null;
      _error = null;
    });
    if (_tab == _SearchTab.frequent) _loadFrequent();
  }

  // ----- Submit -------------------------------------------------------------

  Future<void> _submitItem(MealItemInput input, {double? caloriesHint}) async {
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      final result = await _mealService.createOrAppend(date: widget.date, type: _mealType, items: [input]);
      if (!mounted) return;
      final item = result.meal.items.isNotEmpty ? result.meal.items.last : null;
      _invalidateCaches();
      Navigator.of(context).pop(
        AddToMealResult(
          item: item ??
              MealItem(id: 0, mealId: result.meal.id, label: input.custom?.label ?? '', quantity: input.quantity, unit: input.unit),
          mealId: result.meal.id,
          mealType: _mealType,
          date: widget.date,
          day: result.day,
          stockDecrement: result.stockDecrements.isEmpty ? null : result.stockDecrements.first,
          caloriesAdded: item?.calories ?? caloriesHint,
        ),
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.fieldErrors.isNotEmpty ? e.fieldErrors.values.first.first : e.message;
        _submitting = false;
      });
    }
  }

  void _invalidateCaches() {
    Session.instance.invalidatePrefix('meals');
    Session.instance.invalidate('dashboard');
    Session.instance.invalidate('recommendations');
    Session.instance.invalidate('stock');
  }

  Future<void> _submitFrequent(FrequentItem item) async {
    final input = item.isRecipe
        ? MealItemInput(recipeId: item.id, quantity: item.lastQuantity, unit: 'portion')
        : MealItemInput(foodId: item.id, quantity: item.lastQuantity, unit: item.lastUnit);
    await _submitItem(input);
  }

  Future<void> _submitPicker() async {
    final sel = _selection;
    if (sel == null || !sel.isValid) {
      setState(() => _error = 'Indique une quantité supérieure à zéro.');
      return;
    }
    if (_stockItem != null) {
      await _submitStock(sel);
      return;
    }
    if (_selectedRecipe != null) {
      await _submitItem(
        MealItemInput(recipeId: _selectedRecipe!.id, quantity: sel.quantity, unit: 'portion'),
        caloriesHint: sel.calories,
      );
      return;
    }
    if (_selectedFood != null) {
      await _submitItem(
        MealItemInput(foodId: _selectedFood!.id, quantity: sel.quantity, unit: sel.unit),
        caloriesHint: sel.calories,
      );
    }
  }

  Future<void> _submitStock(QuantitySelection sel) async {
    final item = _stockItem!;
    if (!_decrementStock) {
      if (item.foodId == null) {
        setState(() => _error = 'Cet article n’est pas relié à un aliment : coche « Retirer du stock » pour réduire la quantité.');
        return;
      }
      await _submitItem(MealItemInput(foodId: item.foodId, quantity: sel.quantity, unit: sel.unit, stockItemId: item.id), caloriesHint: sel.calories);
      return;
    }
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      final result = await _stockService.consume(
        item.id,
        quantity: sel.quantity,
        unit: sel.unit,
        mealType: _mealType,
        date: widget.date,
        addToMeal: item.foodId != null,
      );
      if (!mounted) return;
      _invalidateCaches();
      Navigator.of(context).pop(
        AddToMealResult(
          item: MealItem(
            id: result.mealItemId ?? 0,
            mealId: result.mealId ?? 0,
            label: item.foodName,
            quantity: sel.quantity,
            unit: sel.unit,
            calories: result.mealItemCalories ?? sel.calories ?? 0,
            foodId: item.foodId,
            stockItemId: item.id,
          ),
          mealId: result.mealId ?? 0,
          mealType: _mealType,
          date: widget.date,
          stockDecrement: result.stockItem,
          caloriesAdded: result.mealItemCalories ?? sel.calories,
        ),
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.fieldErrors.isNotEmpty ? e.fieldErrors.values.first.first : e.message;
        _submitting = false;
      });
    }
  }

  Future<void> _submitCreateFood() async {
    final errors = <String, String>{};
    final name = _createName.text.trim();
    final kcal = parseDecimal(_createKcal.text);
    if (name.isEmpty) errors['name'] = 'Indique un nom.';
    if (kcal == null) errors['calories'] = 'Indique les calories pour 100 g.';
    if (errors.isNotEmpty) {
      setState(() => _createErrors = errors);
      return;
    }
    setState(() {
      _submitting = true;
      _error = null;
      _createErrors = {};
    });
    try {
      final created = await _foodService.create({
        'barcode': ?_createBarcode,
        'name': name,
        if (_createBrand.text.trim().isNotEmpty) 'brand': _createBrand.text.trim(),
        'calories': kcal,
        'proteins': parseDecimal(_createP.text) ?? 0,
        'carbs': parseDecimal(_createG.text) ?? 0,
        'fat': parseDecimal(_createL.text) ?? 0,
        'source_type': 'manual',
      });
      if (!mounted) return;
      setState(() => _submitting = false);
      _selectFood(created.food, quantity: 100, unit: 'g');
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _createErrors = {for (final entry in e.fieldErrors.entries) entry.key: entry.value.first};
        _error = e.fieldErrors.isEmpty ? e.message : null;
      });
    }
  }

  Future<void> _submitCustom() async {
    final errors = <String, String>{};
    final label = _customLabel.text.trim();
    final kcal = parseDecimal(_customKcal.text);
    if (label.isEmpty) errors['label'] = 'Indique un nom.';
    if (kcal == null) errors['calories'] = 'Indique les calories.';
    if (errors.isNotEmpty) {
      setState(() => _customErrors = errors);
      return;
    }
    setState(() => _customErrors = {});
    await _submitItem(
      MealItemInput(
        custom: CustomItemInput(
          label: label,
          per100g: false,
          calories: kcal!,
          proteins: parseDecimal(_customP.text) ?? 0,
          carbs: parseDecimal(_customG.text) ?? 0,
          fat: parseDecimal(_customL.text) ?? 0,
        ),
        quantity: 1,
        unit: 'portion',
      ),
      caloriesHint: kcal,
    );
  }

  // ----- UI -----------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.viewInsetsOf(context).bottom;
    return Padding(
      padding: EdgeInsets.only(bottom: bottomInset),
      child: Column(
        children: [
          _header(),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
            child: _MealTypeSelector(value: _mealType, onChanged: (v) => setState(() => _mealType = v)),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
              child: StatusBanner.error(_error!, onClose: () => setState(() => _error = null)),
            ),
          Expanded(child: _body()),
        ],
      ),
    );
  }

  Widget _header() {
    final canBack = widget.mode == AddToMealMode.search && _stage != _Stage.search && _stage != _Stage.loadingPreset;
    String title;
    switch (_stage) {
      case _Stage.picker:
        title = _stockItem != null ? 'Consommer' : 'Quantité';
        break;
      case _Stage.createFood:
        title = 'Créer l’aliment';
        break;
      case _Stage.custom:
        title = 'Aliment personnalisé';
        break;
      default:
        title = 'Ajouter à un repas';
    }
    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 0, 8, 0),
      child: Row(
        children: [
          if (canBack)
            IconButton(tooltip: 'Retour', onPressed: _submitting ? null : _backToSearch, icon: const Icon(Icons.arrow_back_rounded))
          else
            const SizedBox(width: 48),
          Expanded(
            child: Text(
              title,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: MaviohColors.text),
            ),
          ),
          IconButton(
            tooltip: 'Fermer',
            onPressed: _submitting ? null : () => Navigator.of(context).pop(),
            icon: const Icon(Icons.close_rounded),
          ),
        ],
      ),
    );
  }

  Widget _body() {
    switch (_stage) {
      case _Stage.loadingPreset:
        return const LoadingState(message: 'Chargement…');
      case _Stage.picker:
        return _pickerStage();
      case _Stage.createFood:
        return _createFoodStage();
      case _Stage.custom:
        return _customStage();
      case _Stage.search:
        return _searchStage();
    }
  }

  // ----- Search stage -------------------------------------------------------

  Widget _searchStage() {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: TextField(
            controller: _searchController,
            focusNode: _searchFocus,
            autofocus: widget.preset == null,
            textInputAction: TextInputAction.search,
            decoration: InputDecoration(
              hintText: _tab == _SearchTab.recipes ? 'Nom de la recette' : 'Nom, marque ou code-barres',
              prefixIcon: const Icon(Icons.search_rounded),
              suffixIcon: _searchController.text.isEmpty
                  ? null
                  : IconButton(
                      tooltip: 'Effacer',
                      onPressed: () {
                        _searchController.clear();
                        _onQueryChanged('');
                      },
                      icon: const Icon(Icons.clear_rounded),
                    ),
            ),
            onChanged: _onQueryChanged,
          ),
        ),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Row(
            children: [
              ActionChip(
                avatar: const Icon(Icons.qr_code_scanner_rounded, size: 18),
                label: const Text('Scanner'),
                onPressed: _scan,
              ),
              const SizedBox(width: 8),
              ChoiceChip(
                avatar: const Icon(Icons.history_rounded, size: 18),
                label: const Text('Fréquents'),
                selected: _tab == _SearchTab.frequent,
                onSelected: (_) {
                  _searchController.clear();
                  setState(() => _tab = _SearchTab.frequent);
                  _loadFrequent();
                },
              ),
              const SizedBox(width: 8),
              ChoiceChip(
                avatar: const Icon(Icons.menu_book_outlined, size: 18),
                label: const Text('Recettes'),
                selected: _tab == _SearchTab.recipes,
                onSelected: (_) {
                  setState(() => _tab = _SearchTab.recipes);
                  _loadRecipes(_searchController.text.trim());
                },
              ),
              const SizedBox(width: 8),
              ActionChip(
                avatar: const Icon(Icons.edit_note_rounded, size: 18),
                label: const Text('Personnalisé'),
                onPressed: () => setState(() {
                  _customErrors = {};
                  _stage = _Stage.custom;
                }),
              ),
            ],
          ),
        ),
        const SizedBox(height: 8),
        Expanded(child: _searchList()),
      ],
    );
  }

  Widget _searchList() {
    switch (_tab) {
      case _SearchTab.frequent:
        if (_frequentLoading) return const LoadingState();
        if (_frequent.isEmpty) {
          return const EmptyState(
            icon: Icons.history_rounded,
            title: 'Pas encore d’habitudes',
            message: 'Tes aliments les plus fréquents apparaîtront ici. Tape un nom ou scanne un code pour commencer.',
            compact: true,
          );
        }
        return ListView.separated(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
          itemCount: _frequent.length,
          separatorBuilder: (_, _) => const SizedBox(height: 8),
          itemBuilder: (context, index) {
            final item = _frequent[index];
            final kcal = item.isRecipe
                ? (item.caloriesPerServing == null ? null : item.caloriesPerServing! * item.lastQuantity)
                : (item.caloriesPer100g == null
                    ? null
                    : (Portion.toGrams(Session.instance.portions, item.lastQuantity, item.lastUnit) ?? item.lastQuantity) /
                        100 *
                        item.caloriesPer100g!);
            return _FrequentRow(
              item: item,
              caloriesPreview: kcal,
              enabled: !_submitting,
              onAdd: () => _submitFrequent(item),
              onPick: () async {
                setState(() => _submitting = true);
                try {
                  if (item.isRecipe) {
                    final recipe = await _recipeService.get(item.id);
                    if (mounted) _selectRecipe(recipe, quantity: item.lastQuantity);
                  } else {
                    final food = await _foodService.get(item.id);
                    if (mounted) _selectFood(food, quantity: item.lastQuantity, unit: item.lastUnit);
                  }
                } on ApiException catch (e) {
                  if (mounted) setState(() => _error = e.message);
                } finally {
                  if (mounted) setState(() => _submitting = false);
                }
              },
            );
          },
        );
      case _SearchTab.results:
        if (_searching && _results.isEmpty) return const LoadingState(message: 'Recherche…');
        if (_searchError != null) {
          return EmptyState(
            icon: Icons.cloud_off_rounded,
            title: 'Recherche impossible',
            message: _searchError,
            ctaLabel: 'Réessayer',
            onCta: () => _runSearch(_searchController.text.trim()),
            tone: MaviohColors.error,
            compact: true,
          );
        }
        if (_results.isEmpty) {
          return EmptyState(
            icon: Icons.search_off_rounded,
            title: 'Aucun aliment trouvé',
            message: _offQueried
                ? 'Rien non plus sur Open Food Facts. Tu peux créer l’aliment ou saisir un aliment personnalisé.'
                : 'Essaie un autre nom, scanne le code-barres ou crée l’aliment.',
            ctaLabel: 'Créer l’aliment',
            onCta: () => setState(() {
              _createBarcode = null;
              _createName.text = _searchController.text.trim();
              _createErrors = {};
              _stage = _Stage.createFood;
            }),
            compact: true,
          );
        }
        return ListView.separated(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
          itemCount: _results.length + 1,
          separatorBuilder: (_, _) => const SizedBox(height: 8),
          itemBuilder: (context, index) {
            if (index == _results.length) {
              return Padding(
                padding: const EdgeInsets.only(top: 6),
                child: OutlinedButton.icon(
                  onPressed: () => setState(() {
                    _createBarcode = null;
                    _createName.text = _searchController.text.trim();
                    _createErrors = {};
                    _stage = _Stage.createFood;
                  }),
                  icon: const Icon(Icons.add_rounded),
                  label: const Text('Je ne trouve pas : créer l’aliment'),
                ),
              );
            }
            final food = _results[index];
            return FoodTile(
              food: food,
              onTap: () => _selectFood(
                food,
                quantity: food.servingSizeG != null ? 1 : 100,
                unit: food.servingSizeG != null ? 'portion' : 'g',
              ),
            );
          },
        );
      case _SearchTab.recipes:
        if (_recipesLoading) return const LoadingState(message: 'Chargement des recettes…');
        if (_recipesError != null) {
          return EmptyState(
            icon: Icons.cloud_off_rounded,
            title: 'Recettes indisponibles',
            message: _recipesError,
            ctaLabel: 'Réessayer',
            onCta: () => _loadRecipes(_searchController.text.trim()),
            tone: MaviohColors.error,
            compact: true,
          );
        }
        if (_recipes.isEmpty) {
          return const EmptyState(
            icon: Icons.menu_book_outlined,
            title: 'Aucune recette',
            message: 'Aucune recette ne correspond. Crée-en une depuis la section Recettes.',
            compact: true,
          );
        }
        return ListView.separated(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
          itemCount: _recipes.length,
          separatorBuilder: (_, _) => const SizedBox(height: 8),
          itemBuilder: (context, index) {
            final recipe = _recipes[index];
            return RecipeTile(recipe: recipe, onTap: () => _selectRecipe(recipe, quantity: 1));
          },
        );
    }
  }

  // ----- Picker stage -------------------------------------------------------

  Widget _pickerStage() {
    final food = _selectedFood;
    final recipe = _selectedRecipe;
    final stock = _stockItem;

    Widget header;
    Widget picker;

    if (stock != null) {
      final stockFood = stock.food;
      header = _SelectedHeader(
        title: stock.foodName,
        subtitle: [
          if (stock.foodBrand != null) stock.foodBrand!,
          '${fmtQty(stock.quantity, stock.unit)} en stock · ${stock.stockName}',
        ].join(' · '),
        imageUrl: stockFood?.imageUrl,
      );
      picker = QuantityUnitPicker(
        mode: PickerMode.stock,
        refCalories: stockFood?.calories,
        refProteins: stockFood?.proteins,
        refCarbs: stockFood?.carbs,
        refFat: stockFood?.fat,
        servingSizeG: stockFood?.servingSizeG,
        initialQuantity: (widget.maxQuantity ?? stock.quantity) < 1 ? (widget.maxQuantity ?? stock.quantity) : 1,
        initialUnit: stock.unit,
        maxQuantity: widget.maxQuantity ?? stock.quantity,
        stockUnit: stock.unit,
        onChanged: (s) => setState(() => _selection = s),
      );
    } else if (recipe != null) {
      header = _SelectedHeader(
        title: recipe.title,
        subtitle: recipe.perServing.calories == null
            ? 'Calories par portion inconnues'
            : '${fmtKcal(recipe.perServing.calories)} / portion · ${fmtDecimal(recipe.servings, decimals: 1)} portion${recipe.servings > 1 ? 's' : ''} par recette',
        imageUrl: recipe.imageUrl,
        isRecipe: true,
      );
      picker = QuantityUnitPicker(
        mode: PickerMode.recipe,
        refCalories: recipe.perServing.calories,
        refProteins: recipe.perServing.proteins,
        refCarbs: recipe.perServing.carbs,
        refFat: recipe.perServing.fat,
        initialQuantity: _presetQuantity ?? 1,
        initialUnit: 'portion',
        onChanged: (s) => setState(() => _selection = s),
      );
    } else if (food != null) {
      header = _SelectedHeader(
        title: food.name,
        subtitle: [
          if (food.brand != null) food.brand!,
          if (food.calories != null) '${fmtInt(food.calories)}${nbsp}kcal / 100$nbsp${food.isLiquid ? 'ml' : 'g'}',
          if (food.servingLabel != null) 'portion : ${food.servingLabel}',
        ].join(' · '),
        imageUrl: food.imageUrl,
      );
      picker = QuantityUnitPicker(
        mode: PickerMode.food,
        refCalories: food.calories,
        refProteins: food.proteins,
        refCarbs: food.carbs,
        refFat: food.fat,
        servingSizeG: food.servingSizeG,
        category: food.category,
        isLiquid: food.isLiquid,
        initialQuantity: _presetQuantity ?? (food.servingSizeG != null ? 1 : 100),
        initialUnit: _presetUnit ?? (food.servingSizeG != null ? 'portion' : 'g'),
        onChanged: (s) => setState(() => _selection = s),
      );
    } else {
      return const EmptyState(title: 'Rien à ajouter', compact: true);
    }

    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
            children: [
              header,
              const SizedBox(height: 16),
              picker,
              if (stock != null) ...[
                const SizedBox(height: 10),
                CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  value: _decrementStock,
                  onChanged: _submitting ? null : (v) => setState(() => _decrementStock = v ?? true),
                  title: const Text('Retirer du stock', style: TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: stock.foodId == null
                      ? const Text(
                          'Article non relié à un aliment : le stock sera réduit, rien ne sera ajouté au repas.',
                          style: TextStyle(color: MaviohColors.warning, fontSize: 12.5),
                        )
                      : const Text('La quantité consommée est déduite de ton stock.', style: TextStyle(fontSize: 12.5)),
                ),
              ],
            ],
          ),
        ),
        _stickyCta(
          label: stock != null
              ? (_decrementStock && stock.foodId == null ? 'Retirer du stock' : 'Consommer')
              : 'Ajouter ${AppStrings.mealTypeDative(_mealType)}',
          onPressed: _submitPicker,
        ),
      ],
    );
  }

  // ----- Create food stage --------------------------------------------------

  Widget _createFoodStage() {
    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
            children: [
              if (_createBarcode != null)
                StatusBanner.info(
                  'Code $_createBarcode introuvable, même sur Open Food Facts. Renseigne les valeurs pour 100 g depuis l’emballage.',
                  margin: const EdgeInsets.only(bottom: 12),
                ),
              TextField(
                controller: _createName,
                textCapitalization: TextCapitalization.sentences,
                decoration: InputDecoration(labelText: 'Nom *', errorText: _createErrors['name']),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _createBrand,
                textCapitalization: TextCapitalization.words,
                decoration: InputDecoration(labelText: 'Marque', errorText: _createErrors['brand']),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _createKcal,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(labelText: 'Calories pour 100 g *', suffixText: 'kcal', errorText: _createErrors['calories']),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(child: _macroField(_createP, 'Protéines', _createErrors['proteins'])),
                  const SizedBox(width: 8),
                  Expanded(child: _macroField(_createG, 'Glucides', _createErrors['carbs'])),
                  const SizedBox(width: 8),
                  Expanded(child: _macroField(_createL, 'Lipides', _createErrors['fat'])),
                ],
              ),
              const SizedBox(height: 8),
              const Text('Valeurs pour 100 g. L’aliment sera partagé avec la communauté.', style: TextStyle(fontSize: 12, color: MaviohColors.muted)),
            ],
          ),
        ),
        _stickyCta(label: 'Créer et ajouter', onPressed: _submitCreateFood),
      ],
    );
  }

  // ----- Custom stage -------------------------------------------------------

  Widget _customStage() {
    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
            children: [
              const Text(
                'Saisis les valeurs de ce que tu as mangé (totales, pas pour 100 g).',
                style: TextStyle(color: MaviohColors.muted, fontSize: 13),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _customLabel,
                autofocus: true,
                textCapitalization: TextCapitalization.sentences,
                decoration: InputDecoration(labelText: 'Nom *', hintText: 'Ex. : part de quiche', errorText: _customErrors['label']),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _customKcal,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(labelText: 'Calories *', suffixText: 'kcal', errorText: _customErrors['calories']),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(child: _macroField(_customP, 'Protéines', null)),
                  const SizedBox(width: 8),
                  Expanded(child: _macroField(_customG, 'Glucides', null)),
                  const SizedBox(width: 8),
                  Expanded(child: _macroField(_customL, 'Lipides', null)),
                ],
              ),
            ],
          ),
        ),
        _stickyCta(label: 'Ajouter ${AppStrings.mealTypeDative(_mealType)}', onPressed: _submitCustom),
      ],
    );
  }

  Widget _macroField(TextEditingController controller, String label, String? error) {
    return TextField(
      controller: controller,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      decoration: InputDecoration(labelText: label, suffixText: 'g', errorText: error),
    );
  }

  Widget _stickyCta({required String label, required VoidCallback onPressed}) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 16),
      decoration: const BoxDecoration(
        color: MaviohColors.background,
        border: Border(top: BorderSide(color: MaviohColors.border)),
      ),
      child: SizedBox(
        width: double.infinity,
        height: 52,
        child: FilledButton(
          onPressed: _submitting ? null : onPressed,
          child: _submitting
              ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
              : Text(label),
        ),
      ),
    );
  }
}

class _MealTypeSelector extends StatelessWidget {
  final String value;
  final ValueChanged<String> onChanged;

  const _MealTypeSelector({required this.value, required this.onChanged});

  static const _short = {
    'petit_dejeuner': 'Petit-déj',
    'dejeuner': 'Déjeuner',
    'diner': 'Dîner',
    'collation': 'Collation',
  };

  @override
  Widget build(BuildContext context) {
    return SegmentedButton<String>(
      showSelectedIcon: false,
      segments: [
        for (final type in AppStrings.mealTypeOrder)
          ButtonSegment<String>(
            value: type,
            label: Text(_short[type]!, maxLines: 1, overflow: TextOverflow.ellipsis),
            tooltip: AppStrings.mealType(type),
          ),
      ],
      selected: {value},
      onSelectionChanged: (set) => onChanged(set.first),
      style: SegmentedButton.styleFrom(
        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 10),
        textStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
      ),
    );
  }
}

class _SelectedHeader extends StatelessWidget {
  final String title;
  final String subtitle;
  final String? imageUrl;
  final bool isRecipe;

  const _SelectedHeader({required this.title, required this.subtitle, this.imageUrl, this.isRecipe = false});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: MaviohColors.border),
      ),
      child: Row(
        children: [
          isRecipe ? RecipeThumb(imageUrl: imageUrl, size: 52) : FoodThumb(imageUrl: imageUrl, size: 52),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text)),
                if (subtitle.isNotEmpty) ...[
                  const SizedBox(height: 3),
                  Text(subtitle, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted)),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _FrequentRow extends StatelessWidget {
  final FrequentItem item;
  final double? caloriesPreview;
  final bool enabled;
  final VoidCallback onAdd;
  final VoidCallback onPick;

  const _FrequentRow({
    required this.item,
    required this.caloriesPreview,
    required this.enabled,
    required this.onAdd,
    required this.onPick,
  });

  @override
  Widget build(BuildContext context) {
    final subtitle = [
      if (item.brand != null) item.brand!,
      fmtQty(item.lastQuantity, item.lastUnit),
      if (caloriesPreview != null) fmtKcal(caloriesPreview),
    ].join(' · ');
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: enabled ? onPick : null,
        child: Container(
          padding: const EdgeInsets.fromLTRB(12, 8, 6, 8),
          decoration: BoxDecoration(borderRadius: BorderRadius.circular(16), border: Border.all(color: MaviohColors.border)),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: (item.isRecipe ? MaviohColors.teal : MaviohColors.primary).withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(
                  item.isRecipe ? Icons.menu_book_outlined : Icons.history_rounded,
                  color: item.isRecipe ? MaviohColors.teal : MaviohColors.primary,
                  size: 20,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(item.label, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w700, color: MaviohColors.text)),
                    const SizedBox(height: 2),
                    Text(subtitle, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted)),
                  ],
                ),
              ),
              if (item.count > 1)
                Padding(
                  padding: const EdgeInsets.only(right: 4),
                  child: TonePill(label: '×${item.count}', tone: MaviohColors.slate),
                ),
              IconButton.filledTonal(
                tooltip: 'Ajouter ${fmtQty(item.lastQuantity, item.lastUnit)}',
                onPressed: enabled ? onAdd : null,
                icon: const Icon(Icons.add_rounded),
                style: IconButton.styleFrom(
                  backgroundColor: MaviohColors.tint(MaviohColors.primary, 0.12),
                  foregroundColor: MaviohColors.primary,
                  minimumSize: const Size(48, 48),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
