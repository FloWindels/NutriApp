import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../models/food.dart';
import '../../models/stock.dart';
import '../../services/stock_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/error_state.dart';
import '../../widgets/loading_state.dart';
import '../../widgets/quantity_unit_picker.dart';
import '../../widgets/status_banner.dart';

/// « Ajouter au stock » sheet opened from the food detail card (§16.4).
///
/// Location dropdown from `GET /stocks`, quantity/unit picker in stock mode,
/// expiry date (FR) and `expiry_kind` (DLC/DDM). The sheet never pops before a
/// 2xx: failures are shown inline with a [StatusBanner].
class FoodStockSheet extends StatefulWidget {
  const FoodStockSheet._({required this.food});

  final Food food;

  /// Opens the sheet; returns the created [StockItem] or null when cancelled.
  static Future<StockItem?> show(BuildContext context, {required Food food}) {
    return showModalBottomSheet<StockItem>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      useRootNavigator: true,
      builder: (_) => FoodStockSheet._(food: food),
    );
  }

  @override
  State<FoodStockSheet> createState() => _FoodStockSheetState();
}

class _FoodStockSheetState extends State<FoodStockSheet> {
  final _service = StockService();

  List<StockLocation> _locations = const [];
  int? _locationId;
  bool _loading = true;
  String? _loadError;
  bool _saving = false;
  String? _error;

  DateTime? _expiresAt;
  String _expiryKind = 'dlc';
  late QuantitySelection _selection;

  @override
  void initState() {
    super.initState();
    final food = widget.food;
    final hasServing = food.servingSizeG != null;
    _selection = QuantitySelection(
      quantity: hasServing ? 1 : 100,
      unit: hasServing ? 'portion' : (food.isLiquid ? 'ml' : 'g'),
    );
    _loadLocations();
  }

  Future<void> _loadLocations() async {
    setState(() {
      _loading = true;
      _loadError = null;
    });
    try {
      final payload = await _service.list();
      if (!mounted) return;
      setState(() {
        _locations = payload.locations;
        _locationId = payload.locations.isEmpty ? null : payload.locations.first.id;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _loadError = e.message;
      });
    }
  }

  Future<void> _pickDate() async {
    final now = today();
    final picked = await showDatePicker(
      context: context,
      initialDate: _expiresAt ?? now,
      firstDate: DateTime(now.year - 1),
      lastDate: DateTime(now.year + 10),
      locale: const Locale('fr', 'FR'),
      helpText: 'Date de péremption',
      cancelText: AppStrings.cancel,
      confirmText: 'Valider',
    );
    if (picked == null || !mounted) return;
    setState(() => _expiresAt = DateTime(picked.year, picked.month, picked.day));
  }

  Future<void> _submit() async {
    final locationId = _locationId;
    if (locationId == null) {
      setState(() => _error = 'Crée d’abord un lieu de stockage dans l’onglet Stock.');
      return;
    }
    if (!_selection.isValid) {
      setState(() => _error = 'Indique une quantité supérieure à 0.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    final food = widget.food;
    final expires = _expiresAt == null ? null : isoDate(_expiresAt!);
    try {
      final item = await _service.createItem({
        'stock_id': locationId,
        'food_id': food.id,
        'food_name': food.name,
        'food_barcode': ?food.barcode,
        'food_brand': ?food.brand,
        'quantity': _selection.quantity,
        'unit': _selection.unit,
        'expires_at': ?expires,
        'expiry_kind': _expiryKind,
      });
      if (!mounted) return;
      Session.instance.invalidate('stock');
      Session.instance.invalidate('dashboard');
      Navigator.of(context).pop(item);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final food = widget.food;
    final height = MediaQuery.sizeOf(context).height * 0.88;

    return SizedBox(
      height: height,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 4, 12, 8),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Ajouter au stock',
                        style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        food.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 13, color: MaviohColors.muted),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  tooltip: AppStrings.close,
                  onPressed: _saving ? null : () => Navigator.of(context).pop(),
                  icon: const Icon(Icons.close_rounded),
                ),
              ],
            ),
          ),
          Expanded(child: _body()),
        ],
      ),
    );
  }

  Widget _body() {
    if (_loading) return const LoadingState(message: 'Chargement des lieux…');
    if (_loadError != null) {
      return ErrorState(message: _loadError!, onRetry: _loadLocations);
    }
    final food = widget.food;
    final bottomInset = MediaQuery.viewInsetsOf(context).bottom;

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: EdgeInsets.fromLTRB(20, 4, 20, 24 + bottomInset),
      children: [
        if (_error != null)
          StatusBanner.error(
            _error!,
            margin: const EdgeInsets.only(bottom: 14),
            onClose: () => setState(() => _error = null),
          ),
        if (_locations.isEmpty)
          const StatusBanner.info(
            'Aucun lieu de stockage pour le moment. Crée un lieu (Frigo, Congélateur, Placard) depuis l’onglet Stock.',
            margin: EdgeInsets.only(bottom: 14),
          )
        else
          DropdownButtonFormField<int>(
            initialValue: _locationId,
            decoration: const InputDecoration(labelText: 'Lieu de stockage'),
            items: [
              for (final location in _locations)
                DropdownMenuItem<int>(value: location.id, child: Text(location.name)),
            ],
            onChanged: _saving ? null : (value) => setState(() => _locationId = value),
          ),
        const SizedBox(height: 18),
        QuantityUnitPicker(
          mode: PickerMode.stock,
          refCalories: food.calories,
          refProteins: food.proteins,
          refCarbs: food.carbs,
          refFat: food.fat,
          servingSizeG: food.servingSizeG,
          category: food.category,
          isLiquid: food.isLiquid,
          initialQuantity: _selection.quantity,
          initialUnit: _selection.unit,
          onChanged: (selection) => _selection = selection,
        ),
        const SizedBox(height: 18),
        const Text(
          'Date de péremption',
          style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _saving ? null : _pickDate,
                icon: const Icon(Icons.event_outlined),
                label: Text(_expiresAt == null ? 'Choisir une date' : fmtDateFr(_expiresAt)),
                style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
              ),
            ),
            if (_expiresAt != null) ...[
              const SizedBox(width: 8),
              IconButton(
                tooltip: 'Retirer la date',
                onPressed: _saving ? null : () => setState(() => _expiresAt = null),
                icon: const Icon(Icons.close_rounded),
              ),
            ],
          ],
        ),
        const SizedBox(height: 12),
        SegmentedButton<String>(
          segments: const [
            ButtonSegment<String>(value: 'dlc', label: Text('DLC'), tooltip: 'Date limite de consommation'),
            ButtonSegment<String>(value: 'ddm', label: Text('DDM'), tooltip: 'Date de durabilité minimale'),
          ],
          selected: {_expiryKind},
          onSelectionChanged: _saving ? null : (values) => setState(() => _expiryKind = values.first),
        ),
        const SizedBox(height: 6),
        Text(
          _expiryKind == 'dlc'
              ? 'DLC : à consommer avant la date (produits frais).'
              : 'DDM : qualité optimale avant la date, le produit reste consommable.',
          style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.4),
        ),
        const SizedBox(height: 22),
        FilledButton.icon(
          onPressed: _saving || _locations.isEmpty ? null : _submit,
          icon: _saving
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                )
              : const Icon(Icons.add_rounded),
          label: Text(_saving ? 'Ajout en cours…' : 'Ajouter au stock'),
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
        ),
      ],
    );
  }
}
