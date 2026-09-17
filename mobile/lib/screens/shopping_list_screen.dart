import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/household.dart';
import '../models/shopping.dart';
import '../services/household_service.dart';
import '../services/shopping_service.dart';
import '../theme/app_theme.dart';
import '../widgets/app_card.dart';
import '../widgets/confirm_dialog.dart';
import '../widgets/empty_state.dart';
import '../widgets/error_state.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/section_header.dart';
import '../widgets/status_banner.dart';
import 'shopping/to_stock_sheet.dart';
import 'shopping/unit_chips.dart';

/// Couleur de la pastille de provenance (§11 `ShoppingSource`).
const Map<String, Color> _sourceTones = {
  'manuel': MaviohColors.slate,
  'auto_stock': MaviohColors.amber,
  'planificateur': MaviohColors.sky,
  'recommandation': MaviohColors.violet,
};

/// Liste de courses (§11 + §16.4).
///
/// Rendue dans l’onglet « Plus » : pas de `Scaffold` ni d’`AppBar` ici.
class ShoppingListScreen extends StatefulWidget {
  const ShoppingListScreen({super.key, this.onNavigate, this.service, this.householdService});

  /// Navigation rapide vers une autre section (slug).
  final ValueChanged<String>? onNavigate;

  /// Injectables pour les tests.
  final ShoppingService? service;
  final HouseholdService? householdService;

  @override
  State<ShoppingListScreen> createState() => _ShoppingListScreenState();
}

class _ShoppingListScreenState extends State<ShoppingListScreen> {
  static const String _cacheKey = 'shopping';
  static const String _householdCacheKey = 'household';

  late final ShoppingService _service = widget.service ?? ShoppingService();
  late final HouseholdService _households = widget.householdService ?? HouseholdService();

  final TextEditingController _label = TextEditingController();
  final TextEditingController _quantity = TextEditingController();

  List<ShoppingItem>? _items;
  Household? _household;

  bool _loading = true;
  bool _adding = false;
  bool _generating = false;
  bool _clearing = false;
  String? _error;
  String? _refreshError;
  String? _labelError;
  String? _quantityError;
  String? _unit;
  int _loadToken = 0;

  @override
  void initState() {
    super.initState();
    final cached = Session.instance.cached(_cacheKey);
    if (cached != null) {
      _items = ShoppingList.fromJson(cached.data).items;
      _loading = false;
      if (cached.isStale()) _load(silent: true);
    } else {
      _load();
    }
    final household = Session.instance.cached(_householdCacheKey);
    if (household != null) _household = HouseholdService.parse(household.data);
    _loadHousehold();
  }

  @override
  void dispose() {
    _label.dispose();
    _quantity.dispose();
    super.dispose();
  }

  // ----- Données -------------------------------------------------------------

  Future<void> _load({bool silent = false}) async {
    final token = ++_loadToken;
    if (!silent) {
      setState(() {
        _loading = _items == null;
        _error = null;
        _refreshError = null;
      });
    }
    try {
      final raw = await _service.listRaw();
      if (!mounted || token != _loadToken) return;
      Session.instance.put(_cacheKey, raw);
      setState(() {
        _items = ShoppingList.fromJson(raw).items;
        _loading = false;
        _error = null;
        _refreshError = null;
      });
    } on ApiException catch (e) {
      if (!mounted || token != _loadToken) return;
      final hasData = _items != null;
      setState(() {
        _loading = false;
        if (hasData) {
          _refreshError = e.message;
        } else {
          _error = e.message;
        }
      });
      if (hasData && !silent) _snack(e.message);
    }
  }

  /// Le foyer n’est qu’un en-tête : un échec ne bloque jamais la liste.
  Future<void> _loadHousehold() async {
    try {
      final raw = await _households.raw();
      if (!mounted) return;
      Session.instance.put(_householdCacheKey, raw);
      setState(() => _household = HouseholdService.parse(raw));
    } on ApiException {
      // En-tête facultatif : on garde la valeur en cache.
    }
  }

  void _snack(String message, {SnackBarAction? action}) {
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(message), action: action));
  }

  void _invalidate() => Session.instance.invalidate(_cacheKey);

  List<ShoppingItem> get _unchecked => (_items ?? const []).where((item) => !item.checked).toList();

  List<ShoppingItem> get _checked => (_items ?? const []).where((item) => item.checked).toList();

  void _replace(ShoppingItem item) {
    final items = _items;
    if (items == null) return;
    final index = items.indexWhere((i) => i.id == item.id);
    if (index < 0) return;
    setState(() => _items = [...items]..[index] = item);
  }

  void _removeLocal(int id) {
    final items = _items;
    if (items == null) return;
    setState(() => _items = items.where((i) => i.id != id).toList());
  }

  // ----- Actions -------------------------------------------------------------

  Future<void> _add() async {
    final label = _label.text.trim();
    if (label.isEmpty) {
      setState(() => _labelError = 'Indique un article à acheter.');
      return;
    }
    final rawQuantity = _quantity.text.trim();
    final quantity = rawQuantity.isEmpty ? null : parseDecimal(rawQuantity);
    if (rawQuantity.isNotEmpty && (quantity == null || quantity <= 0)) {
      setState(() => _quantityError = 'Indique une quantité supérieure à 0.');
      return;
    }
    setState(() {
      _adding = true;
      _labelError = null;
      _quantityError = null;
    });
    try {
      final created = await _service.add(label: label, quantity: quantity, unit: _unit);
      if (!mounted) return;
      _invalidate();
      setState(() {
        _items = [created, ...?_items];
        _adding = false;
        _label.clear();
        _quantity.clear();
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _adding = false;
        _labelError = e.fieldError('label');
        _quantityError = e.fieldError('quantity') ?? e.fieldError('unit');
      });
      if (_labelError == null && _quantityError == null) _snack(e.message);
    }
  }

  Future<void> _toggle(ShoppingItem item, bool checked) async {
    final previous = item;
    _replace(item.copyWith(checked: checked));
    try {
      final updated = await _service.update(item.id, checked: checked);
      if (!mounted) return;
      _invalidate();
      _replace(updated);
    } on ApiException catch (e) {
      if (!mounted) return;
      _replace(previous);
      _snack(e.message);
    }
  }

  Future<void> _delete(ShoppingItem item) async {
    _removeLocal(item.id);
    try {
      await _service.delete(item.id);
      if (!mounted) return;
      _invalidate();
      _snack(
        '${item.label} supprimé',
        action: SnackBarAction(label: AppStrings.undo, onPressed: () => _undoDelete(item)),
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.message);
      await _load(silent: true);
    }
  }

  Future<void> _undoDelete(ShoppingItem item) async {
    try {
      final created = await _service.add(
        label: item.label,
        quantity: item.quantity,
        unit: item.unit,
        foodId: item.foodId,
      );
      if (!mounted) return;
      _invalidate();
      setState(() => _items = [created, ...?_items]);
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.message);
    }
  }

  Future<void> _clearChecked() async {
    final count = _checked.length;
    if (count == 0) return;
    final ok = await ConfirmDialog.show(
      context,
      title: 'Effacer les articles cochés ?',
      message: count > 1
          ? '$count articles cochés seront retirés de la liste.'
          : '1 article coché sera retiré de la liste.',
      confirmLabel: 'Effacer',
      destructive: true,
      icon: Icons.playlist_remove_rounded,
    );
    if (!ok || !mounted) return;
    setState(() => _clearing = true);
    try {
      await _service.clearChecked();
      if (!mounted) return;
      _invalidate();
      setState(() {
        _clearing = false;
        _items = _unchecked;
      });
      _snack(count > 1 ? '$count articles effacés' : '1 article effacé');
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _clearing = false);
      _snack(e.message);
    }
  }

  Future<void> _generate() async {
    setState(() => _generating = true);
    try {
      final result = await _service.generate();
      if (!mounted) return;
      _invalidate();
      setState(() {
        _generating = false;
        _items = result.list.items;
      });
      final added = result.addedCount;
      _snack(
        added == 0
            ? 'Rien à ajouter : tout est déjà dans ta liste ou dans ton stock.'
            : (added > 1 ? '$added articles ajoutés depuis le planning' : '1 article ajouté depuis le planning'),
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _generating = false);
      _snack(e.message);
    }
  }

  Future<void> _toStock(ShoppingItem item) async {
    final created = await ToStockSheet.show(context, item: item, shopping: _service);
    if (!mounted || created == null) return;
    _invalidate();
    Session.instance.invalidate('stock');
    _removeLocal(item.id);
    _snack(
      '${item.label} rangé dans ${created.stockName}',
      action: SnackBarAction(label: 'Voir le stock', onPressed: () => widget.onNavigate?.call('stock')),
    );
  }

  // ----- Rendu ---------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    if (_loading && _items == null) return const LoadingState(skeleton: true, skeletonCount: 4);
    final error = _error;
    if (error != null && _items == null) return ErrorState(message: error, onRetry: _load);

    final unchecked = _unchecked;
    final checked = _checked;
    final total = (_items ?? const []).length;

    return RefreshIndicator(
      onRefresh: () => _load(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 96),
        children: [
          SectionHeader(
            eyebrow: AppStrings.sectionShopping,
            title: _household == null ? 'Ma liste' : 'Liste du foyer',
            subtitle: _household == null
                ? _countsLabel(total, checked.length)
                : '${_household!.name} · ${_countsLabel(total, checked.length)}',
          ),
          if (_refreshError != null)
            StatusBanner.warning(
              'Données peut-être obsolètes : $_refreshError',
              margin: const EdgeInsets.only(bottom: 12),
              onClose: () => setState(() => _refreshError = null),
            ),
          _addCard(),
          const SizedBox(height: 12),
          _actionsRow(checked.isNotEmpty),
          const SizedBox(height: 14),
          if (total == 0)
            EmptyState(
              icon: Icons.shopping_cart_outlined,
              title: 'Ta liste est vide',
              message: 'Ajoute un article ci-dessus ou remplis la liste à partir de ton planning de la semaine.',
              ctaLabel: 'Générer depuis le planning',
              onCta: _generating ? null : _generate,
            )
          else ...[
            if (unchecked.isNotEmpty) ...[
              _groupTitle('À acheter', unchecked.length),
              for (final item in unchecked) _row(item),
            ],
            if (checked.isNotEmpty) ...[
              const SizedBox(height: 16),
              _groupTitle('Déjà pris', checked.length),
              for (final item in checked) _row(item),
            ],
            if (unchecked.isEmpty)
              const Padding(
                padding: EdgeInsets.only(top: 14),
                child: EmptyState(
                  icon: Icons.task_alt_rounded,
                  title: 'Tout est coché',
                  message: 'Tu peux effacer les articles cochés pour repartir d’une liste propre.',
                  compact: true,
                ),
              ),
          ],
        ],
      ),
    );
  }

  String _countsLabel(int total, int checked) {
    final left = total == 0
        ? 'aucun article'
        : (total > 1 ? '$total articles' : '1 article');
    return '$left · $checked coché${checked > 1 ? 's' : ''}';
  }

  Widget _groupTitle(String title, int count) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 4, 4, 6),
      child: Text(
        '$title ($count)',
        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w800, color: MaviohColors.textTertiary),
      ),
    );
  }

  Widget _addCard() {
    return AppCard(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            controller: _label,
            textInputAction: TextInputAction.done,
            decoration: InputDecoration(
              labelText: 'Article',
              hintText: 'Ex. : tomates cerises',
              errorText: _labelError,
              prefixIcon: const Icon(Icons.add_shopping_cart_outlined),
            ),
            onSubmitted: (_) => _adding ? null : _add(),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _quantity,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(
              labelText: 'Quantité (facultatif)',
              hintText: 'Ex. : 1,5',
              errorText: _quantityError,
              prefixIcon: const Icon(Icons.scale_outlined),
            ),
          ),
          const SizedBox(height: 12),
          UnitChips(selected: _unit, onChanged: (unit) => setState(() => _unit = unit)),
          const SizedBox(height: 14),
          FilledButton.icon(
            onPressed: _adding ? null : _add,
            icon: _adding
                ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2.2))
                : const Icon(Icons.add_rounded),
            label: const Text('Ajouter à la liste'),
          ),
        ],
      ),
    );
  }

  Widget _actionsRow(bool hasChecked) {
    return Row(
      children: [
        Expanded(
          child: OutlinedButton.icon(
            onPressed: _generating ? null : _generate,
            icon: _generating
                ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2.2))
                : const Icon(Icons.auto_awesome_outlined, size: 18),
            label: const Text('Générer depuis le planning'),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: OutlinedButton.icon(
            onPressed: !hasChecked || _clearing ? null : _clearChecked,
            icon: const Icon(Icons.playlist_remove_rounded, size: 18),
            label: const Text('Effacer les cochés'),
          ),
        ),
      ],
    );
  }

  Widget _row(ShoppingItem item) {
    final tone = _sourceTones[item.source] ?? MaviohColors.slate;
    final sourceLabel = AppStrings.shoppingSourceLabels[item.source] ?? item.source;
    final quantityLabel = item.quantity == null
        ? null
        : fmtQty(item.quantity, item.unit);

    return Dismissible(
      key: ValueKey<String>('shopping-item-${item.id}'),
      direction: DismissDirection.endToStart,
      onDismissed: (_) => _delete(item),
      background: Container(
        alignment: Alignment.centerRight,
        margin: const EdgeInsets.symmetric(vertical: 3),
        padding: const EdgeInsets.symmetric(horizontal: 16),
        decoration: BoxDecoration(color: MaviohColors.errorBg, borderRadius: BorderRadius.circular(16)),
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
      child: AppCard(
        margin: const EdgeInsets.symmetric(vertical: 3),
        padding: const EdgeInsets.fromLTRB(4, 2, 4, 2),
        radius: 16,
        child: CheckboxListTile(
          value: item.checked,
          onChanged: (value) => _toggle(item, value ?? false),
          controlAffinity: ListTileControlAffinity.leading,
          contentPadding: const EdgeInsets.fromLTRB(6, 4, 4, 4),
          title: Text(
            item.label,
            style: TextStyle(
              fontWeight: FontWeight.w700,
              color: item.checked ? MaviohColors.muted : MaviohColors.text,
              decoration: item.checked ? TextDecoration.lineThrough : null,
            ),
          ),
          subtitle: Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Wrap(
              spacing: 8,
              runSpacing: 6,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                if (quantityLabel != null)
                  Text(
                    quantityLabel,
                    style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, fontWeight: FontWeight.w600),
                  ),
                TonePill(label: sourceLabel, tone: tone),
              ],
            ),
          ),
          secondary: PopupMenuButton<String>(
            tooltip: 'Actions sur ${item.label}',
            icon: const Icon(Icons.more_vert_rounded),
            onSelected: (value) {
              if (value == 'stock') {
                _toStock(item);
              } else if (value == 'delete') {
                _delete(item);
              }
            },
            itemBuilder: (_) => const [
              PopupMenuItem<String>(
                value: 'stock',
                child: ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(Icons.kitchen_outlined),
                  title: Text('Mettre au stock'),
                ),
              ),
              PopupMenuItem<String>(
                value: 'delete',
                child: ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(Icons.delete_outline_rounded),
                  title: Text(AppStrings.delete),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
