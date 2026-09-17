import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../core/strings.dart';
import '../../models/food.dart';
import '../../models/stock.dart';
import '../../services/food_service.dart';
import '../../services/stock_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/app_card.dart';
import '../../widgets/barcode_scanner_sheet.dart';
import '../../widgets/food_tile.dart';
import '../../widgets/quantity_unit_picker.dart';
import '../../widgets/status_banner.dart';
import '../stock_screen.dart' show invalidateStockCaches;

/// « Ajouter un article » sheet of the Stock screen (§6.2 + §16.4).
///
/// Product picking goes through the API only (`GET /foods/search?off=1`,
/// `GET /foods/barcode/{ean}`): no direct Open Food Facts call. When the product
/// is unknown the mini form feeds `food_name`, `food_barcode`, `food_brand` and
/// the macros to `POST /stocks/items` so nutrition is never lost.
///
/// The sheet never pops before a 2xx: failures stay inline in a [StatusBanner].
class AddStockSheet extends StatefulWidget {
  const AddStockSheet._({required this.locations, this.initialLocationId});

  final List<StockLocation> locations;
  final int? initialLocationId;

  /// Opens the sheet. Resolves with `true` when an item was created.
  static Future<bool?> show(
    BuildContext context, {
    required List<StockLocation> locations,
    int? initialLocationId,
  }) {
    final height = MediaQuery.sizeOf(context).height * 0.92;
    return showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      useRootNavigator: true,
      builder: (_) => SizedBox(
        height: height,
        child: AddStockSheet._(locations: locations, initialLocationId: initialLocationId),
      ),
    );
  }

  @override
  State<AddStockSheet> createState() => _AddStockSheetState();
}

class _AddStockSheetState extends State<AddStockSheet> {
  static const Duration _debounceDelay = Duration(milliseconds: 350);

  final _foods = FoodService();
  final _stock = StockService();

  final _searchController = TextEditingController();
  final _nameController = TextEditingController();
  final _brandController = TextEditingController();
  final _barcodeController = TextEditingController();
  final _caloriesController = TextEditingController();
  final _proteinsController = TextEditingController();
  final _carbsController = TextEditingController();
  final _fatController = TextEditingController();
  final _newLocationController = TextEditingController();

  Timer? _debounce;
  int _searchRun = 0;

  List<Food> _results = const [];
  bool _searching = false;
  String? _searchError;
  bool _offQueried = false;
  bool _searched = false;

  late List<StockLocation> _locations = List<StockLocation>.of(widget.locations);
  int? _locationId;
  bool _addingLocation = false;
  bool _savingLocation = false;

  /// Product picked in the catalogue (null in manual mode).
  Food? _selected;

  /// True once the user chose to describe the product by hand.
  bool _manual = false;

  late QuantitySelection _selection = const QuantitySelection(quantity: 1, unit: 'piece');
  DateTime? _expiresAt;
  String _expiryKind = 'dlc';

  bool _saving = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  bool get _hasProduct => _selected != null || _manual;

  @override
  void initState() {
    super.initState();
    _locationId = widget.initialLocationId ?? (_locations.isEmpty ? null : _locations.first.id);
  }

  @override
  void dispose() {
    _debounce?.cancel();
    for (final controller in [
      _searchController,
      _nameController,
      _brandController,
      _barcodeController,
      _caloriesController,
      _proteinsController,
      _carbsController,
      _fatController,
      _newLocationController,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  // ----- Search -------------------------------------------------------------

  void _onQueryChanged(String value) {
    _debounce?.cancel();
    final query = value.trim();
    if (query.length < 2) {
      setState(() {
        _results = const [];
        _searching = false;
        _searchError = null;
        _offQueried = false;
        _searched = false;
      });
      return;
    }
    _debounce = Timer(_debounceDelay, () => _search(query));
  }

  Future<void> _search(String query) async {
    final run = ++_searchRun;
    setState(() {
      _searching = true;
      _searchError = null;
    });
    try {
      final paged = await _foods.search(query, off: true);
      if (!mounted || run != _searchRun) return;
      setState(() {
        _results = paged.items;
        _offQueried = paged.meta.offQueried;
        _searching = false;
        _searched = true;
      });
    } on ApiException catch (e) {
      if (!mounted || run != _searchRun) return;
      setState(() {
        _searching = false;
        _searched = true;
        _searchError = e.message;
      });
    }
  }

  Future<void> _scan() async {
    final code = await BarcodeScannerSheet.show(context);
    if (code == null || !mounted) return;
    setState(() {
      _searching = true;
      _searchError = null;
      _searched = true;
    });
    final run = ++_searchRun;
    try {
      final food = await _foods.tryByBarcode(code);
      if (!mounted || run != _searchRun) return;
      if (food == null) {
        // 404 → mini create form prefilled with the scanned code (§16.4).
        setState(() {
          _searching = false;
          _results = const [];
        });
        _startManual(barcode: code);
        setState(() => _error = 'Produit inconnu : décris-le en quelques champs, il sera enregistré avec ton stock.');
        return;
      }
      setState(() => _searching = false);
      _selectFood(food);
    } on ApiException catch (e) {
      if (!mounted || run != _searchRun) return;
      setState(() {
        _searching = false;
        _searchError = e.message;
      });
    }
  }

  void _selectFood(Food food) {
    setState(() {
      _selected = food;
      _manual = false;
      _error = null;
      _fieldErrors = const {};
      _expiryKind = 'dlc';
      _selection = QuantitySelection(
        quantity: food.servingSizeG != null ? 1 : 100,
        unit: food.servingSizeG != null ? 'portion' : (food.isLiquid ? 'ml' : 'g'),
      );
    });
  }

  void _startManual({String? barcode, String? name}) {
    _barcodeController.text = barcode ?? '';
    _nameController.text = name ?? _searchController.text.trim();
    setState(() {
      _manual = true;
      _selected = null;
      _fieldErrors = const {};
      _selection = const QuantitySelection(quantity: 1, unit: 'piece');
    });
  }

  void _resetProduct() {
    setState(() {
      _selected = null;
      _manual = false;
      _error = null;
      _fieldErrors = const {};
    });
  }

  // ----- Locations ----------------------------------------------------------

  Future<void> _createLocation() async {
    final name = _newLocationController.text.trim();
    if (name.isEmpty) {
      setState(() => _error = 'Donne un nom au nouveau lieu.');
      return;
    }
    setState(() {
      _savingLocation = true;
      _error = null;
    });
    try {
      final location = await _stock.createLocation(name);
      if (!mounted) return;
      invalidateStockCaches();
      setState(() {
        _locations = [..._locations, location];
        _locationId = location.id;
        _addingLocation = false;
        _savingLocation = false;
        _newLocationController.clear();
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _savingLocation = false;
        _error = e.message;
      });
    }
  }

  // ----- Expiry -------------------------------------------------------------

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

  // ----- Save ---------------------------------------------------------------

  Future<void> _submit() async {
    if (_saving) return;
    FocusScope.of(context).unfocus();
    final locationId = _locationId;
    final errors = <String, String>{};
    if (locationId == null) errors['stock_id'] = 'Choisis un lieu de stockage.';
    final manualName = _nameController.text.trim();
    if (_selected == null && manualName.isEmpty) errors['food_name'] = 'Le nom du produit est requis.';
    if (!_selection.isValid) errors['quantity'] = 'Indique une quantité supérieure à 0.';
    if (errors.isNotEmpty) {
      setState(() {
        _fieldErrors = errors;
        _error = 'Vérifie les champs signalés.';
      });
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
      _fieldErrors = const {};
    });

    final food = _selected;
    final expires = _expiresAt == null ? null : isoDate(_expiresAt!);
    final barcode = food?.barcode ?? _nullable(_barcodeController.text);
    final brand = food?.brand ?? _nullable(_brandController.text);
    final body = <String, dynamic>{
      'stock_id': locationId,
      'food_id': ?food?.id,
      'food_name': food?.name ?? manualName,
      'food_barcode': ?barcode,
      'food_brand': ?brand,
      'quantity': _selection.quantity,
      'unit': _selection.unit,
      'expires_at': ?expires,
      'expiry_kind': _expiryKind,
    };
    if (food == null) {
      body.addAll(<String, dynamic>{
        'calories': ?parseDecimal(_caloriesController.text),
        'proteins': ?parseDecimal(_proteinsController.text),
        'carbs': ?parseDecimal(_carbsController.text),
        'fat': ?parseDecimal(_fatController.text),
      });
    }

    try {
      await _stock.createItem(body);
      if (!mounted) return;
      invalidateStockCaches();
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
        _fieldErrors = e.fieldErrors.map((key, value) => MapEntry(key, value.first));
      });
    }
  }

  static String? _nullable(String value) {
    final trimmed = value.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  // ----- UI -----------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final inset = MediaQuery.viewInsetsOf(context).bottom;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 4, 10, 6),
          child: Row(
            children: [
              const Expanded(
                child: Text(
                  'Ajouter au stock',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
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
        Expanded(
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: EdgeInsets.fromLTRB(20, 4, 20, 24 + inset),
            children: [
              if (_error != null)
                StatusBanner.error(
                  _error!,
                  margin: const EdgeInsets.only(bottom: 14),
                  onClose: () => setState(() => _error = null),
                ),
              if (!_hasProduct) ..._searchSection(),
              if (_hasProduct) ..._formSection(),
            ],
          ),
        ),
      ],
    );
  }

  List<Widget> _searchSection() {
    return [
      Row(
        children: [
          Expanded(
            child: TextField(
              controller: _searchController,
              autofocus: true,
              textInputAction: TextInputAction.search,
              onChanged: _onQueryChanged,
              onSubmitted: (value) => _search(value.trim()),
              decoration: const InputDecoration(
                labelText: 'Produit',
                hintText: 'Nom, marque ou code-barres',
                prefixIcon: Icon(Icons.search_rounded),
              ),
            ),
          ),
          const SizedBox(width: 8),
          IconButton.filledTonal(
            tooltip: 'Scanner un code-barres',
            onPressed: _scan,
            iconSize: 24,
            constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
            icon: const Icon(Icons.qr_code_scanner_rounded),
          ),
        ],
      ),
      const SizedBox(height: 12),
      if (_offQueried)
        const StatusBanner.info(
          'Résultats complétés par Open Food Facts via Mavi’oh.',
          margin: EdgeInsets.only(bottom: 12),
        ),
      if (_searchError != null)
        StatusBanner.error(_searchError!, margin: const EdgeInsets.only(bottom: 12)),
      if (_searching)
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 24),
          child: Center(child: CircularProgressIndicator()),
        ),
      if (!_searching)
        for (final food in _results)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: FoodTile(food: food, onTap: () => _selectFood(food)),
          ),
      if (!_searching && _searched && _results.isEmpty && _searchError == null)
        const Padding(
          padding: EdgeInsets.only(top: 8, bottom: 8),
          child: Text(
            'Aucun produit trouvé. Tu peux l’ajouter à la main.',
            style: TextStyle(fontSize: 13, color: MaviohColors.muted),
          ),
        ),
      const SizedBox(height: 4),
      OutlinedButton.icon(
        onPressed: () => _startManual(),
        icon: const Icon(Icons.edit_note_rounded),
        label: const Text('Saisir le produit à la main'),
        style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
      ),
    ];
  }

  List<Widget> _formSection() {
    final food = _selected;
    return [
      AppCard(
        padding: const EdgeInsets.fromLTRB(14, 12, 10, 12),
        radius: 20,
        color: MaviohColors.surface,
        shadow: false,
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    food?.name ?? (_nameController.text.trim().isEmpty ? 'Nouveau produit' : _nameController.text.trim()),
                    style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    food == null
                        ? 'Produit saisi à la main'
                        : [food.brand, if (food.calories != null) '${fmtKcal(food.calories)} / 100 g']
                            .whereType<String>()
                            .join(' · '),
                    style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                  ),
                ],
              ),
            ),
            TextButton(
              onPressed: _saving ? null : _resetProduct,
              child: const Text('Changer'),
            ),
          ],
        ),
      ),
      const SizedBox(height: 16),
      if (food == null) ..._manualFields(),
      _locationField(),
      const SizedBox(height: 18),
      QuantityUnitPicker(
        key: ValueKey('stock-add-picker-${food?.id ?? 'manuel'}'),
        mode: PickerMode.stock,
        refCalories: food?.calories ?? parseDecimal(_caloriesController.text),
        refProteins: food?.proteins ?? parseDecimal(_proteinsController.text),
        refCarbs: food?.carbs ?? parseDecimal(_carbsController.text),
        refFat: food?.fat ?? parseDecimal(_fatController.text),
        servingSizeG: food?.servingSizeG,
        category: food?.category,
        isLiquid: food?.isLiquid ?? false,
        initialQuantity: _selection.quantity,
        initialUnit: _selection.unit,
        onChanged: (selection) => _selection = selection,
      ),
      if (_fieldErrors['quantity'] != null)
        Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Text(_fieldErrors['quantity']!, style: const TextStyle(color: MaviohColors.error, fontSize: 12.5)),
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
        onPressed: _saving ? null : _submit,
        icon: _saving
            ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
            : const Icon(Icons.add_rounded),
        label: Text(_saving ? 'Ajout en cours…' : 'Ajouter au stock'),
        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
      ),
    ];
  }

  List<Widget> _manualFields() {
    return [
      TextField(
        controller: _nameController,
        textCapitalization: TextCapitalization.sentences,
        onChanged: (_) => setState(() {}),
        decoration: InputDecoration(
          labelText: 'Nom du produit',
          hintText: 'Ex. : yaourt nature',
          errorText: _fieldErrors['food_name'],
        ),
      ),
      const SizedBox(height: 12),
      Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: TextField(
              controller: _brandController,
              textCapitalization: TextCapitalization.sentences,
              decoration: InputDecoration(labelText: 'Marque', errorText: _fieldErrors['food_brand']),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: TextField(
              controller: _barcodeController,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: InputDecoration(labelText: 'Code-barres', errorText: _fieldErrors['food_barcode']),
            ),
          ),
        ],
      ),
      const SizedBox(height: 12),
      TextField(
        controller: _caloriesController,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        onChanged: (_) => setState(() {}),
        decoration: InputDecoration(
          labelText: 'Calories pour 100 g',
          suffixText: 'kcal',
          errorText: _fieldErrors['calories'],
        ),
      ),
      const SizedBox(height: 12),
      Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(child: _macroField('Protéines', _proteinsController, 'proteins')),
          const SizedBox(width: 10),
          Expanded(child: _macroField('Glucides', _carbsController, 'carbs')),
          const SizedBox(width: 10),
          Expanded(child: _macroField('Lipides', _fatController, 'fat')),
        ],
      ),
      const SizedBox(height: 18),
    ];
  }

  Widget _macroField(String label, TextEditingController controller, String field) {
    return TextField(
      controller: controller,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      onChanged: (_) => setState(() {}),
      decoration: InputDecoration(labelText: label, suffixText: 'g', errorText: _fieldErrors[field]),
    );
  }

  Widget _locationField() {
    if (_addingLocation) {
      return Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: TextField(
              controller: _newLocationController,
              autofocus: true,
              textCapitalization: TextCapitalization.sentences,
              decoration: const InputDecoration(
                labelText: 'Nouveau lieu',
                hintText: 'Frigo, Congélateur, Placard…',
              ),
              onSubmitted: (_) => _createLocation(),
            ),
          ),
          const SizedBox(width: 8),
          IconButton.filledTonal(
            tooltip: 'Créer le lieu',
            onPressed: _savingLocation ? null : _createLocation,
            constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
            icon: _savingLocation
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.check_rounded),
          ),
          IconButton(
            tooltip: 'Annuler',
            onPressed: _savingLocation ? null : () => setState(() => _addingLocation = false),
            icon: const Icon(Icons.close_rounded),
          ),
        ],
      );
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: DropdownButtonFormField<int>(
            initialValue: _locations.any((l) => l.id == _locationId) ? _locationId : null,
            decoration: InputDecoration(
              labelText: 'Lieu de stockage',
              errorText: _fieldErrors['stock_id'],
            ),
            items: [
              for (final location in _locations)
                DropdownMenuItem<int>(value: location.id, child: Text(location.name)),
            ],
            onChanged: _saving ? null : (value) => setState(() => _locationId = value),
          ),
        ),
        const SizedBox(width: 8),
        IconButton.filledTonal(
          tooltip: 'Nouveau lieu',
          onPressed: _saving ? null : () => setState(() => _addingLocation = true),
          constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
          icon: const Icon(Icons.add_rounded),
        ),
      ],
    );
  }
}
