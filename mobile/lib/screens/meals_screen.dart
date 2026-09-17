import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/meal.dart';
import '../services/meal_service.dart';
import '../theme/app_theme.dart';
import '../widgets/add_to_meal_sheet.dart';
import '../widgets/app_card.dart';
import '../widgets/budget_card.dart';
import '../widgets/confirm_dialog.dart';
import '../widgets/date_strip.dart';
import '../widgets/error_state.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/quantity_unit_picker.dart';
import '../widgets/status_banner.dart';
import 'history_screen.dart';

/// Share of the daily budget per meal type (`App\Services\MealBudget`, §4.2).
const Map<String, double> kMealShares = {
  'petit_dejeuner': 0.25,
  'dejeuner': 0.35,
  'diner': 0.30,
  'collation': 0.10,
};

/// Icon per meal type.
const Map<String, IconData> _mealIcons = {
  'petit_dejeuner': Icons.free_breakfast_outlined,
  'dejeuner': Icons.lunch_dining_outlined,
  'diner': Icons.dinner_dining_outlined,
  'collation': Icons.cookie_outlined,
};

/// Repas du jour (§16.3) — rendered inside the « Repas » tab, so **no Scaffold**
/// and no FAB here (HomeScreen owns both).
///
/// Tap budget (§16.3): add from a meal card → 2 taps + picker · frequent → 2 ·
/// scan → 3 + scan · copy yesterday → 2.
class MealsScreen extends StatefulWidget {
  const MealsScreen({super.key, this.initialDate, this.onNavigate});

  /// Jour affiché à l’ouverture (aujourd’hui par défaut).
  final DateTime? initialDate;

  /// Navigation rapide vers une autre section (slug).
  final ValueChanged<String>? onNavigate;

  @override
  State<MealsScreen> createState() => _MealsScreenState();
}

class _MealsScreenState extends State<MealsScreen> {
  final MealService _service = MealService();

  late DateTime _date;
  DaySummary? _day;
  bool _loading = true;
  String? _error;
  String? _refreshError;
  int _loadToken = 0;

  String _cacheKeyFor(DateTime date) => 'meals:${isoDate(date)}';

  @override
  void initState() {
    super.initState();
    final initial = widget.initialDate;
    _date = initial == null ? today() : DateTime(initial.year, initial.month, initial.day);
    final cached = Session.instance.cached(_cacheKeyFor(_date));
    if (cached != null) {
      _day = DaySummary.fromJson(cached.data);
      _loading = false;
      if (cached.isStale()) _load(silent: true);
    } else {
      _load();
    }
  }

  // ----- Data ---------------------------------------------------------------

  Future<void> _load({bool silent = false}) async {
    final token = ++_loadToken;
    final date = _date;
    if (!silent) {
      setState(() {
        _loading = _day == null;
        _error = null;
        _refreshError = null;
      });
    }
    try {
      final raw = await _service.dayRaw(date);
      if (!mounted || token != _loadToken) return;
      Session.instance.put(_cacheKeyFor(date), raw);
      setState(() {
        _day = DaySummary.fromJson(raw);
        _loading = false;
        _error = null;
        _refreshError = null;
      });
    } on ApiException catch (e) {
      if (!mounted || token != _loadToken) return;
      final hasData = _day != null;
      setState(() {
        _loading = false;
        if (hasData) {
          _refreshError = e.message;
        } else {
          _error = e.message;
        }
      });
      if (hasData && !silent) {
        ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  void _setDate(DateTime date) {
    final next = DateTime(date.year, date.month, date.day);
    if (next == _date) return;
    final cached = Session.instance.cached(_cacheKeyFor(next));
    setState(() {
      _date = next;
      _error = null;
      _refreshError = null;
      _day = cached == null ? null : DaySummary.fromJson(cached.data);
      _loading = cached == null;
    });
    if (cached == null || cached.isStale()) _load(silent: cached != null);
  }

  /// Every mutation invalidates the caches shared with the other tabs.
  void _invalidateCaches() {
    Session.instance.invalidatePrefix('meals');
    Session.instance.invalidate('dashboard');
    Session.instance.invalidate('recommendations');
    Session.instance.invalidate('stock');
  }

  void _applyDay(DaySummary? day) {
    _invalidateCaches();
    if (!mounted) return;
    if (day == null) {
      _load(silent: true);
      return;
    }
    setState(() {
      _day = day;
      _loading = false;
      _error = null;
    });
  }

  // ----- Actions ------------------------------------------------------------

  Future<void> _add(String mealType) async {
    final result = await AddToMealSheet.show(context, date: _date, mealType: mealType);
    if (!mounted || result == null) return;
    _applyDay(result.day);
  }

  MealItemInput _inputFor(MealItem item) {
    if (item.recipeId != null) {
      return MealItemInput(recipeId: item.recipeId, quantity: item.quantity, unit: item.unit);
    }
    if (item.foodId != null) {
      return MealItemInput(foodId: item.foodId, quantity: item.quantity, unit: item.unit);
    }
    return MealItemInput(
      custom: CustomItemInput(
        label: item.label,
        calories: item.calories,
        proteins: item.proteins,
        carbs: item.carbs,
        fat: item.fat,
      ),
      quantity: item.quantity,
      unit: item.unit,
    );
  }

  DaySummary? _withoutItem(DaySummary? day, int mealId, int itemId) {
    if (day == null) return null;
    return DaySummary(
      date: day.date,
      meals: [
        for (final meal in day.meals)
          if (meal.id != mealId)
            meal
          else
            Meal(
              id: meal.id,
              date: meal.date,
              type: meal.type,
              name: meal.name,
              consumedAt: meal.consumedAt,
              notes: meal.notes,
              items: meal.items.where((i) => i.id != itemId).toList(),
              totals: meal.totals,
            ),
      ],
      totals: day.totals,
      targets: day.targets,
      remaining: day.remaining,
      sport: day.sport,
      nextMealType: day.nextMealType,
      plancherKcal: day.plancherKcal,
    );
  }

  Future<void> _deleteItem(Meal meal, MealItem item) async {
    final messenger = ScaffoldMessenger.maybeOf(context);
    final previous = _day;
    // The Dismissible already left the tree: drop the row from the model too.
    setState(() => _day = _withoutItem(_day, meal.id, item.id));
    try {
      final day = await _service.deleteItem(meal.id, item.id);
      if (!mounted) return;
      _applyDay(day);
      messenger
        ?..hideCurrentSnackBar()
        ..showSnackBar(
          SnackBar(
            content: Text('${item.label} supprimé ${AppStrings.mealTypeDative(meal.type)}'),
            action: SnackBarAction(label: AppStrings.undo, onPressed: () => _undoDelete(meal.type, item)),
          ),
        );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _day = previous);
      messenger?.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _undoDelete(String mealType, MealItem item) async {
    final messenger = ScaffoldMessenger.maybeOf(context);
    try {
      final result = await _service.createOrAppend(date: _date, type: mealType, items: [_inputFor(item)]);
      if (!mounted) return;
      _applyDay(result.day);
    } on ApiException catch (e) {
      if (!mounted) return;
      messenger?.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _editItem(Meal meal, MealItem item) async {
    final result = await showModalBottomSheet<_EditSheetResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _EditItemSheet(mealId: meal.id, item: item, service: _service),
    );
    if (!mounted || result == null) return;
    _applyDay(result.day);
  }

  Future<void> _copyYesterday() async {
    final messenger = ScaffoldMessenger.maybeOf(context);
    final from = _date.subtract(const Duration(days: 1));
    try {
      final day = await _service.copy(from: from, to: _date);
      if (!mounted) return;
      _applyDay(day);
      messenger?.showSnackBar(SnackBar(content: Text('Repas du ${fmtDateFr(from)} copiés.')));
    } on ApiException catch (e) {
      if (!mounted) return;
      messenger?.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _openHistory() async {
    final picked = await Navigator.of(context).push<DateTime>(
      MaterialPageRoute<DateTime>(builder: (_) => HistoryScreen(initialDate: _date)),
    );
    if (!mounted || picked == null) return;
    _setDate(picked);
  }

  void _explainSportBonus(SportBonus sport) {
    final message = sport.explication ??
        '${fmtKcal(sport.caloriesBurned)} brûlées aujourd’hui (estimation MET, hors métabolisme de base) : '
            '${sport.coefPct} % sont ajoutées à ton budget, soit +${fmtInt(sport.caloriesBonus)} kcal à consommer.';
    ConfirmDialog.info(context, title: 'Bonus sport', message: message, icon: Icons.fitness_center_rounded);
  }

  // ----- Build --------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    if (_loading && _day == null) return const LoadingState(skeleton: true, skeletonCount: 4);
    if (_error != null && _day == null) {
      return ErrorState(message: _error!, onRetry: _load);
    }
    final day = _day!;
    final sport = day.sport;

    return RefreshIndicator(
      onRefresh: () => _load(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 96),
        children: [
          Row(
            children: [
              Expanded(child: DateStrip(date: _date, onChanged: _setDate)),
              const SizedBox(width: 6),
              PopupMenuButton<String>(
                tooltip: 'Autres actions',
                icon: const Icon(Icons.more_vert_rounded),
                onSelected: (value) {
                  if (value == 'copy') {
                    _copyYesterday();
                  } else if (value == 'history') {
                    _openHistory();
                  }
                },
                itemBuilder: (_) => const [
                  PopupMenuItem<String>(
                    value: 'copy',
                    child: ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(Icons.copy_all_outlined),
                      title: Text('Copier le repas d’hier'),
                    ),
                  ),
                  PopupMenuItem<String>(
                    value: 'history',
                    child: ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(Icons.history_rounded),
                      title: Text(AppStrings.sectionHistory),
                    ),
                  ),
                ],
              ),
            ],
          ),
          if (_refreshError != null)
            StatusBanner.warning(
              'Données peut-être obsolètes : $_refreshError',
              margin: const EdgeInsets.only(top: 12),
              onClose: () => setState(() => _refreshError = null),
            ),
          const SizedBox(height: 12),
          BudgetCard.fromDay(day),
          if (sport.caloriesBonus > 0)
            Padding(
              padding: const EdgeInsets.fromLTRB(4, 8, 0, 0),
              child: Row(
                children: [
                  const Icon(Icons.fitness_center_rounded, size: 16, color: MaviohColors.amber),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      '+${fmtInt(sport.caloriesBonus)} kcal ajoutés à ton budget (${sport.coefPct}$nbsp%)',
                      style: const TextStyle(fontSize: 12.5, color: MaviohColors.textTertiary, fontWeight: FontWeight.w600),
                    ),
                  ),
                  IconButton(
                    tooltip: 'Comment est calculé le bonus sport',
                    onPressed: () => _explainSportBonus(sport),
                    icon: const Icon(Icons.info_outline_rounded, size: 18),
                  ),
                ],
              ),
            ),
          const SizedBox(height: 6),
          for (final type in AppStrings.mealTypeOrder) ...[
            const SizedBox(height: 12),
            _MealCard(
              type: type,
              meal: day.mealOfType(type),
              onAdd: () => _add(type),
              onEditItem: _editItem,
              onDeleteItem: _deleteItem,
            ),
          ],
          const SizedBox(height: 18),
          Text(
            AppStrings.disclaimer,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted, height: 1.4),
          ),
        ],
      ),
    );
  }
}

/// One meal card: header (kcal + share of the budget), « + », items or empty row.
class _MealCard extends StatelessWidget {
  final String type;
  final Meal? meal;
  final VoidCallback onAdd;
  final void Function(Meal meal, MealItem item) onEditItem;
  final void Function(Meal meal, MealItem item) onDeleteItem;

  const _MealCard({
    required this.type,
    required this.meal,
    required this.onAdd,
    required this.onEditItem,
    required this.onDeleteItem,
  });

  @override
  Widget build(BuildContext context) {
    final current = meal;
    final items = current?.items ?? const <MealItem>[];
    final calories = current?.totals.calories ?? 0;
    final sharePct = ((kMealShares[type] ?? 0) * 100).round();

    return AppCard(
      padding: const EdgeInsets.fromLTRB(16, 12, 10, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 38,
                height: 38,
                decoration: BoxDecoration(
                  color: MaviohColors.tint(MaviohColors.primary),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(_mealIcons[type] ?? Icons.restaurant_outlined, size: 20, color: MaviohColors.primary),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      AppStrings.mealType(type),
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      '~$sharePct$nbsp% de ton budget',
                      style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted, fontWeight: FontWeight.w600),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 6),
              Text(
                fmtKcal(calories),
                style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w800, color: MaviohColors.textSecondary),
              ),
              IconButton(
                tooltip: 'Ajouter ${AppStrings.mealTypeDative(type)}',
                onPressed: onAdd,
                icon: const Icon(Icons.add_circle_outline_rounded),
                color: MaviohColors.primary,
              ),
            ],
          ),
          if (current == null || items.isEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(0, 4, 6, 0),
              child: Row(
                children: [
                  const Expanded(
                    child: Text(
                      AppStrings.nothingLogged,
                      style: TextStyle(color: MaviohColors.muted, fontWeight: FontWeight.w600),
                    ),
                  ),
                  TextButton.icon(
                    onPressed: onAdd,
                    icon: const Icon(Icons.add_rounded, size: 18),
                    label: const Text(AppStrings.add),
                  ),
                ],
              ),
            )
          else
            for (final item in items)
              _MealItemRow(
                key: ValueKey<int>(item.id),
                item: item,
                onTap: () => onEditItem(current, item),
                onDismissed: () => onDeleteItem(current, item),
              ),
        ],
      ),
    );
  }
}

/// Swipe-to-delete row: label, quantity + unit, kcal, estimate pill.
class _MealItemRow extends StatelessWidget {
  final MealItem item;
  final VoidCallback onTap;
  final VoidCallback onDismissed;

  const _MealItemRow({super.key, required this.item, required this.onTap, required this.onDismissed});

  @override
  Widget build(BuildContext context) {
    return Dismissible(
      key: ValueKey<String>('meal-item-${item.id}'),
      direction: DismissDirection.endToStart,
      onDismissed: (_) => onDismissed(),
      background: Container(
        alignment: Alignment.centerRight,
        margin: const EdgeInsets.symmetric(vertical: 2),
        padding: const EdgeInsets.symmetric(horizontal: 14),
        decoration: BoxDecoration(color: MaviohColors.errorBg, borderRadius: BorderRadius.circular(14)),
        child: const Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              AppStrings.delete,
              style: TextStyle(color: MaviohColors.error, fontWeight: FontWeight.w700, fontSize: 12.5),
            ),
            SizedBox(width: 6),
            Icon(Icons.delete_outline_rounded, color: MaviohColors.error, size: 20),
          ],
        ),
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: Container(
            constraints: const BoxConstraints(minHeight: 48),
            padding: const EdgeInsets.fromLTRB(2, 8, 8, 8),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(
                        item.label,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
                      ),
                      const SizedBox(height: 3),
                      Row(
                        children: [
                          Flexible(
                            child: Text(
                              fmtQty(item.quantity, item.unit),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, fontWeight: FontWeight.w600),
                            ),
                          ),
                          if (item.isEstimate) ...[
                            const SizedBox(width: 6),
                            const EstimatePill(),
                          ],
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 10),
                Text(
                  fmtKcal(item.calories),
                  style: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Result of the edit sheet (null day → the caller refetches).
class _EditSheetResult {
  final DaySummary? day;

  const _EditSheetResult(this.day);
}

/// Quantity/unit sheet for an existing item → `PUT /meals/{meal}/items/{item}`.
/// Never pops before a 2xx; failures show a [StatusBanner.error] in place.
class _EditItemSheet extends StatefulWidget {
  final int mealId;
  final MealItem item;
  final MealService service;

  const _EditItemSheet({required this.mealId, required this.item, required this.service});

  @override
  State<_EditItemSheet> createState() => _EditItemSheetState();
}

class _EditItemSheetState extends State<_EditItemSheet> {
  QuantitySelection? _selection;
  bool _saving = false;
  String? _error;

  bool get _isRecipe => widget.item.recipeId != null;

  /// Rebuilds the reference values (per serving for a recipe, per 100 g otherwise)
  /// from the item snapshot so the live preview stays consistent.
  double? _ref(double value) {
    if (_isRecipe) {
      final quantity = widget.item.quantity;
      return quantity > 0 ? value / quantity : null;
    }
    final grams = widget.item.gramsEquivalent;
    if (grams != null && grams > 0) return value / grams * 100;
    return null;
  }

  Future<void> _save() async {
    final selection = _selection;
    if (selection == null || !selection.isValid) {
      setState(() => _error = 'Indique une quantité supérieure à 0.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final result = await widget.service.updateItem(
        widget.mealId,
        widget.item.id,
        quantity: selection.quantity,
        unit: selection.unit,
      );
      if (!mounted) return;
      Navigator.of(context).pop(_EditSheetResult(result.day));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.fieldError('quantity') ?? e.fieldError('unit') ?? e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final inset = MediaQuery.viewInsetsOf(context).bottom;
    final item = widget.item;
    return SingleChildScrollView(
      child: Padding(
        padding: EdgeInsets.fromLTRB(20, 4, 20, 20 + inset),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              item.label,
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
            ),
            const SizedBox(height: 4),
            const Text(
              'Ajuste la quantité : les calories et macros sont recalculées.',
              style: TextStyle(color: MaviohColors.muted, height: 1.4),
            ),
            const SizedBox(height: 16),
            QuantityUnitPicker(
              mode: _isRecipe ? PickerMode.recipe : PickerMode.food,
              refCalories: _ref(item.calories),
              refProteins: _ref(item.proteins),
              refCarbs: _ref(item.carbs),
              refFat: _ref(item.fat),
              initialQuantity: item.quantity,
              initialUnit: item.unit,
              onChanged: (selection) => _selection = selection,
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              StatusBanner.error(_error!),
            ],
            const SizedBox(height: 18),
            FilledButton(
              onPressed: _saving ? null : _save,
              child: _saving
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.4))
                  : const Text(AppStrings.save),
            ),
            const SizedBox(height: 4),
            TextButton(
              onPressed: _saving ? null : () => Navigator.of(context).pop(),
              child: const Text(AppStrings.cancel),
            ),
          ],
        ),
      ),
    );
  }
}
