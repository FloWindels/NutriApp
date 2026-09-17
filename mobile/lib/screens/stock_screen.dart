import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../models/stock.dart';
import '../services/household_service.dart';
import '../services/stock_service.dart';
import '../theme/app_theme.dart';
import '../widgets/add_to_meal_sheet.dart';
import '../widgets/app_card.dart';
import '../widgets/confirm_dialog.dart';
import '../widgets/empty_state.dart';
import '../widgets/error_state.dart';
import '../widgets/expiry_badge.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/status_banner.dart';
import 'stock/add_stock_sheet.dart';
import 'stock/stock_item_editor.dart';

/// Alert filter applied on top of the location filter.
enum StockFilter { tous, bientot, perimes, bas }

/// Invalidates every cache impacted by a stock mutation (§16.1).
void invalidateStockCaches() {
  final session = Session.instance;
  session.invalidate('stock');
  session.invalidate('stock:depleted');
  session.invalidate('dashboard');
  session.invalidate('recommendations');
  session.invalidatePrefix('meals');
}

/// Stock (§6 + §16.4). Rendered inside the « Stock » tab: no Scaffold, no AppBar.
class StockScreen extends StatefulWidget {
  const StockScreen({super.key, this.onNavigate});

  /// Section slug callback provided by [HomeScreen] (« liste-course » shortcut).
  final ValueChanged<String>? onNavigate;

  @override
  State<StockScreen> createState() => _StockScreenState();
}

class _StockScreenState extends State<StockScreen> {
  final _service = StockService();

  StockPayload? _data;
  bool _loading = true;
  bool _includeDepleted = false;
  String? _error;
  String? _refreshError;
  String? _householdName;

  int? _locationId;
  StockFilter _filter = StockFilter.tous;
  int? _expandedId;

  String get _cacheKey => _includeDepleted ? 'stock:depleted' : 'stock';

  @override
  void initState() {
    super.initState();
    final cached = Session.instance.cached(_cacheKey);
    if (cached != null) {
      _data = StockPayload.fromJson(cached.data);
      _loading = false;
      _resolveHousehold();
      if (cached.isStale()) _load(silent: true);
    } else {
      _load();
    }
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = _data == null;
        _error = null;
        _refreshError = null;
      });
    }
    try {
      final raw = await _service.raw(includeDepleted: _includeDepleted);
      if (!mounted) return;
      Session.instance.put(_cacheKey, raw);
      setState(() {
        _data = StockPayload.fromJson(raw);
        _loading = false;
        _error = null;
        _refreshError = null;
        if (_locationId != null && !_data!.locations.any((l) => l.id == _locationId)) {
          _locationId = null;
        }
      });
      _resolveHousehold();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (_data == null) {
          _error = e.message;
        } else {
          _refreshError = e.message;
          if (!silent) {
            ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(e.message)));
          }
        }
      });
    }
  }

  /// The payload only carries `household_id`; the name comes from `GET /household`.
  Future<void> _resolveHousehold() async {
    if (_data?.householdId == null || _householdName != null) return;
    try {
      final household = await HouseholdService().get();
      if (!mounted || household == null) return;
      setState(() => _householdName = household.name);
    } on ApiException {
      // Silent: the banner falls back to a generic label.
    }
  }

  Future<void> _toggleDepleted(bool value) async {
    setState(() {
      _includeDepleted = value;
      _expandedId = null;
      final cached = Session.instance.cached(_cacheKey);
      _data = cached == null ? null : StockPayload.fromJson(cached.data);
      _loading = _data == null;
    });
    await _load(silent: _data != null);
  }

  void _setFilter(StockFilter filter) {
    setState(() {
      _filter = _filter == filter ? StockFilter.tous : filter;
      _expandedId = null;
    });
  }

  List<StockItem> get _visibleItems {
    final items = _data?.items ?? const <StockItem>[];
    return items.where((item) {
      if (_locationId != null && item.stockId != _locationId) return false;
      switch (_filter) {
        case StockFilter.tous:
          return true;
        case StockFilter.bientot:
          return item.expiryStatus == 'bientot' || item.expiryStatus == 'aujourdhui';
        case StockFilter.perimes:
          return item.expiryStatus == 'perime' || item.expiryStatus == 'ddm_depassee';
        case StockFilter.bas:
          return item.isLow || item.isDepleted;
      }
    }).toList();
  }

  Future<void> _openAddSheet() async {
    final data = _data;
    if (data == null) return;
    final created = await AddStockSheet.show(
      context,
      locations: data.locations,
      initialLocationId: _locationId ?? (data.locations.isNotEmpty ? data.locations.first.id : null),
    );
    if (created == true && mounted) {
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        const SnackBar(content: Text('Article ajouté au stock.')),
      );
      await _load(silent: true);
    }
  }

  Future<void> _consume(StockItem item) async {
    final result = await AddToMealSheet.show(
      context,
      preset: AddToMealPreset.stockItem(item),
      maxQuantity: item.quantity,
    );
    if (result != null && mounted) await _load(silent: true);
  }

  Future<void> _createLocation() async {
    final name = await _promptLocationName(title: 'Nouveau lieu');
    if (name == null || !mounted) return;
    try {
      await _service.createLocation(name);
      invalidateStockCaches();
      if (!mounted) return;
      await _load(silent: true);
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _renameLocation(StockLocation location) async {
    final name = await _promptLocationName(title: 'Renommer le lieu', initial: location.name);
    if (name == null || !mounted) return;
    try {
      await _service.renameLocation(location.id, name);
      invalidateStockCaches();
      if (!mounted) return;
      await _load(silent: true);
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _deleteLocation(StockLocation location) async {
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'Supprimer « ${location.name} » ?',
      message: 'Le lieu doit être vide. Les articles ne sont pas supprimés.',
      confirmLabel: 'Supprimer',
      destructive: true,
      icon: Icons.delete_outline_rounded,
    );
    if (!confirmed || !mounted) return;
    try {
      await _service.deleteLocation(location.id);
      invalidateStockCaches();
      if (!mounted) return;
      if (_locationId == location.id) _locationId = null;
      await _load(silent: true);
    } on ApiException catch (e) {
      if (!mounted) return;
      // 422 « Vide ce lieu avant de le supprimer. » is shown as-is.
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<String?> _promptLocationName({required String title, String? initial}) {
    final controller = TextEditingController(text: initial ?? '');
    return showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: TextField(
          controller: controller,
          autofocus: true,
          textCapitalization: TextCapitalization.sentences,
          decoration: const InputDecoration(labelText: 'Nom du lieu', hintText: 'Frigo, Congélateur, Placard…'),
          onSubmitted: (value) => Navigator.of(ctx).pop(value.trim().isEmpty ? null : value.trim()),
        ),
        actionsPadding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Annuler')),
          FilledButton(
            onPressed: () {
              final value = controller.text.trim();
              Navigator.of(ctx).pop(value.isEmpty ? null : value);
            },
            child: const Text('Enregistrer'),
          ),
        ],
      ),
    );
  }

  Future<void> _locationMenu(StockLocation location) async {
    final action = await showModalBottomSheet<String>(
      context: context,
      useSafeArea: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              title: Text(location.name, style: const TextStyle(fontWeight: FontWeight.w800)),
              subtitle: Text('${location.itemsCount} article${location.itemsCount > 1 ? 's' : ''}'),
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.drive_file_rename_outline_rounded),
              title: const Text('Renommer'),
              onTap: () => Navigator.of(ctx).pop('rename'),
            ),
            ListTile(
              leading: const Icon(Icons.delete_outline_rounded, color: MaviohColors.error),
              title: const Text('Supprimer', style: TextStyle(color: MaviohColors.error)),
              onTap: () => Navigator.of(ctx).pop('delete'),
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
    if (!mounted || action == null) return;
    if (action == 'rename') {
      await _renameLocation(location);
    } else if (action == 'delete') {
      await _deleteLocation(location);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _data == null) return const LoadingState(skeleton: true, skeletonCount: 4);
    if (_error != null && _data == null) return ErrorState(message: _error!, onRetry: _load);

    final data = _data!;
    final items = _visibleItems;

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
              if (data.householdId != null)
                StatusBanner.info(
                  'Stock partagé avec ${_householdName ?? 'ton foyer'}',
                  margin: const EdgeInsets.only(bottom: 12),
                ),
              _AlertsHeader(
                alerts: data.alerts,
                filter: _filter,
                onFilter: _setFilter,
                onShopping: widget.onNavigate == null ? null : () => widget.onNavigate!('liste-course'),
              ),
              const SizedBox(height: 14),
              _LocationChips(
                locations: data.locations,
                selectedId: _locationId,
                totalCount: data.items.length,
                onSelect: (id) => setState(() {
                  _locationId = _locationId == id ? null : id;
                  _expandedId = null;
                }),
                onLongPress: _locationMenu,
                onCreate: _createLocation,
              ),
              const SizedBox(height: 6),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      '${items.length} article${items.length > 1 ? 's' : ''}',
                      style: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.textTertiary),
                    ),
                  ),
                  const Text('Afficher les épuisés', style: TextStyle(fontSize: 13, color: MaviohColors.muted)),
                  Switch(value: _includeDepleted, onChanged: _toggleDepleted),
                ],
              ),
              const SizedBox(height: 4),
              if (items.isEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 24),
                  child: EmptyState(
                    icon: Icons.kitchen_outlined,
                    title: _filter == StockFilter.tous && _locationId == null
                        ? 'Ton stock est vide'
                        : 'Aucun article dans cette sélection',
                    message: _filter == StockFilter.tous && _locationId == null
                        ? 'Ajoute ce que tu as dans le frigo, le congélateur ou le placard pour suivre les dates et générer ta liste de courses.'
                        : 'Change de lieu ou retire le filtre pour voir le reste de ton stock.',
                    ctaLabel: 'Ajouter un article',
                    onCta: _openAddSheet,
                  ),
                )
              else
                for (final item in items)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: _StockItemCard(
                      key: ValueKey('stock-item-${item.id}'),
                      item: item,
                      locations: data.locations,
                      expanded: _expandedId == item.id,
                      onToggle: () => setState(() => _expandedId = _expandedId == item.id ? null : item.id),
                      onConsume: () => _consume(item),
                      onChanged: () {
                        setState(() => _expandedId = null);
                        _load(silent: true);
                      },
                    ),
                  ),
            ],
          ),
        ),
        Positioned(
          right: 16,
          bottom: 16,
          child: FloatingActionButton.extended(
            heroTag: 'stock-add-fab',
            onPressed: _openAddSheet,
            tooltip: 'Ajouter un article au stock',
            icon: const Icon(Icons.add_rounded),
            label: const Text('Ajouter'),
          ),
        ),
      ],
    );
  }
}

/// Alerts summary: three tappable chips + « Liste de courses » shortcut.
class _AlertsHeader extends StatelessWidget {
  const _AlertsHeader({required this.alerts, required this.filter, required this.onFilter, this.onShopping});

  final StockAlerts alerts;
  final StockFilter filter;
  final ValueChanged<StockFilter> onFilter;
  final VoidCallback? onShopping;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Expanded(
                child: Text(
                  'Ce qu’il faut surveiller',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: MaviohColors.text),
                ),
              ),
              if (onShopping != null)
                TextButton.icon(
                  onPressed: onShopping,
                  icon: const Icon(Icons.shopping_cart_outlined, size: 18),
                  label: const Text('Liste de courses'),
                ),
            ],
          ),
          const SizedBox(height: 6),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _AlertChip(
                label: '${alerts.expiringCount} bientôt périmé${alerts.expiringCount > 1 ? 's' : ''}',
                icon: Icons.timelapse_rounded,
                tone: MaviohColors.warning,
                selected: filter == StockFilter.bientot,
                onTap: () => onFilter(StockFilter.bientot),
              ),
              _AlertChip(
                label: '${alerts.expiredCount} périmé${alerts.expiredCount > 1 ? 's' : ''}',
                icon: Icons.dangerous_outlined,
                tone: MaviohColors.error,
                selected: filter == StockFilter.perimes,
                onTap: () => onFilter(StockFilter.perimes),
              ),
              _AlertChip(
                label: '${alerts.lowCount} à racheter',
                icon: Icons.production_quantity_limits_rounded,
                tone: MaviohColors.primary,
                selected: filter == StockFilter.bas,
                onTap: () => onFilter(StockFilter.bas),
              ),
            ],
          ),
          if (alerts.isEmpty) ...[
            const SizedBox(height: 8),
            const Text(
              'Rien ne périme dans les prochains jours. Continue comme ça !',
              style: TextStyle(fontSize: 12.5, color: MaviohColors.muted),
            ),
          ],
        ],
      ),
    );
  }
}

class _AlertChip extends StatelessWidget {
  const _AlertChip({
    required this.label,
    required this.icon,
    required this.tone,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final IconData icon;
  final Color tone;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ConstrainedBox(
      constraints: const BoxConstraints(minHeight: 48),
      child: FilterChip(
        selected: selected,
        onSelected: (_) => onTap(),
        avatar: Icon(icon, size: 16, color: selected ? Colors.white : tone),
        label: Text(label),
        labelStyle: TextStyle(
          fontWeight: FontWeight.w700,
          fontSize: 12.5,
          color: selected ? Colors.white : MaviohColors.textSecondary,
        ),
        selectedColor: tone,
        showCheckmark: false,
        backgroundColor: MaviohColors.tint(tone, 0.08),
        side: BorderSide(color: selected ? tone : MaviohColors.border),
      ),
    );
  }
}

/// Location chips with item counts, « Nouveau lieu », long-press → rename/delete.
class _LocationChips extends StatelessWidget {
  const _LocationChips({
    required this.locations,
    required this.selectedId,
    required this.totalCount,
    required this.onSelect,
    required this.onLongPress,
    required this.onCreate,
  });

  final List<StockLocation> locations;
  final int? selectedId;
  final int totalCount;
  final ValueChanged<int> onSelect;
  final ValueChanged<StockLocation> onLongPress;
  final VoidCallback onCreate;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 48,
      child: ListView(
        scrollDirection: Axis.horizontal,
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: ChoiceChip(
              selected: selectedId == null,
              onSelected: (_) {
                if (selectedId != null) onSelect(selectedId!);
              },
              label: Text('Tout ($totalCount)'),
              labelStyle: TextStyle(
                fontWeight: FontWeight.w700,
                color: selectedId == null ? Colors.white : MaviohColors.textSecondary,
              ),
              selectedColor: MaviohColors.primary,
              showCheckmark: false,
            ),
          ),
          for (final location in locations)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: GestureDetector(
                onLongPress: () => onLongPress(location),
                child: ChoiceChip(
                  selected: selectedId == location.id,
                  onSelected: (_) => onSelect(location.id),
                  label: Text('${location.name} (${location.itemsCount})'),
                  labelStyle: TextStyle(
                    fontWeight: FontWeight.w700,
                    color: selectedId == location.id ? Colors.white : MaviohColors.textSecondary,
                  ),
                  selectedColor: MaviohColors.primary,
                  showCheckmark: false,
                  tooltip: 'Appui long : renommer ou supprimer',
                ),
              ),
            ),
          ActionChip(
            onPressed: onCreate,
            avatar: const Icon(Icons.add_rounded, size: 18, color: MaviohColors.primary),
            label: const Text('Nouveau lieu'),
            labelStyle: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.primary),
            backgroundColor: MaviohColors.tint(MaviohColors.lime, 0.16),
            side: const BorderSide(color: MaviohColors.border),
          ),
        ],
      ),
    );
  }
}

/// One stock item, with its inline expandable editor.
class _StockItemCard extends StatelessWidget {
  const _StockItemCard({
    super.key,
    required this.item,
    required this.locations,
    required this.expanded,
    required this.onToggle,
    required this.onConsume,
    required this.onChanged,
  });

  final StockItem item;
  final List<StockLocation> locations;
  final bool expanded;
  final VoidCallback onToggle;
  final VoidCallback onConsume;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final food = item.food;
    final depleted = item.isDepleted;
    final titleColor = depleted ? MaviohColors.muted : MaviohColors.text;

    return AppCard(
      padding: const EdgeInsets.fromLTRB(16, 14, 12, 10),
      borderColor: expanded ? MaviohColors.primary : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.foodName,
                      style: TextStyle(fontSize: 15.5, fontWeight: FontWeight.w800, color: titleColor),
                    ),
                    if (item.foodBrand != null && item.foodBrand!.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text(
                          item.foodBrand!,
                          style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                        ),
                      ),
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 8,
                      runSpacing: 6,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text(
                          fmtQty(item.quantity, item.unit),
                          style: TextStyle(
                            fontSize: 14,
                            fontWeight: FontWeight.w700,
                            color: depleted ? MaviohColors.muted : MaviohColors.textSecondary,
                          ),
                        ),
                        Text(
                          item.stockName,
                          style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                        ),
                        if (depleted) const TonePill(label: 'Épuisé', tone: MaviohColors.slate, icon: Icons.remove_circle_outline),
                        if (!depleted && item.isLow)
                          const TonePill(label: 'Stock bas', tone: MaviohColors.amber, icon: Icons.trending_down_rounded),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  ExpiryBadge(status: item.expiryStatus, daysLeft: item.daysLeft, expiryKind: item.expiryKind),
                  if (item.expiresAt != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Text(
                        fmtDateFr(item.expiresAt),
                        style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted),
                      ),
                    ),
                ],
              ),
            ],
          ),
          if (food != null && food.calories != null) ...[
            const SizedBox(height: 10),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(
                  '100 g : ${fmtKcal(food.calories)}',
                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: MaviohColors.textTertiary),
                ),
                MacroPill.proteins(value: food.proteins, compact: true),
                MacroPill.carbs(value: food.carbs, compact: true),
                MacroPill.fat(value: food.fat, compact: true),
              ],
            ),
          ],
          const SizedBox(height: 4),
          Row(
            children: [
              TextButton.icon(
                onPressed: depleted ? null : onConsume,
                icon: const Icon(Icons.restaurant_rounded, size: 18),
                label: const Text('Consommer'),
              ),
              const Spacer(),
              IconButton(
                onPressed: onToggle,
                tooltip: expanded ? 'Fermer la modification' : 'Modifier l’article',
                icon: Icon(expanded ? Icons.expand_less_rounded : Icons.tune_rounded),
              ),
            ],
          ),
          if (expanded)
            StockItemEditor(
              key: ValueKey('stock-editor-${item.id}'),
              item: item,
              locations: locations,
              onChanged: onChanged,
            ),
        ],
      ),
    );
  }
}
