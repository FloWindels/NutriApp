import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../models/shopping.dart';
import '../../models/stock.dart';
import '../../services/shopping_service.dart';
import '../../services/stock_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/error_state.dart';
import '../../widgets/loading_state.dart';
import '../../widgets/status_banner.dart';
import 'unit_chips.dart';

/// « Mettre au stock » (§11) : lieu de rangement, quantité, unité et date de
/// péremption → `POST /shopping-list/items/{id}/to-stock`.
///
/// La feuille ne se ferme jamais avant un 2xx : les échecs restent affichés
/// dans un [StatusBanner.error], les 422 sur les champs.
class ToStockSheet extends StatefulWidget {
  const ToStockSheet({super.key, required this.item, this.shopping, this.stock});

  final ShoppingItem item;

  /// Injectables pour les tests.
  final ShoppingService? shopping;
  final StockService? stock;

  /// Ouvre la feuille et renvoie l’article de stock créé (null si annulé).
  static Future<StockItem?> show(
    BuildContext context, {
    required ShoppingItem item,
    ShoppingService? shopping,
    StockService? stock,
  }) {
    return showModalBottomSheet<StockItem>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => ToStockSheet(item: item, shopping: shopping, stock: stock),
    );
  }

  @override
  State<ToStockSheet> createState() => _ToStockSheetState();
}

class _ToStockSheetState extends State<ToStockSheet> {
  late final ShoppingService _shopping = widget.shopping ?? ShoppingService();
  late final StockService _stock = widget.stock ?? StockService();

  final TextEditingController _quantity = TextEditingController();

  List<StockLocation> _locations = const [];
  int? _stockId;
  String? _unit;
  DateTime? _expiresAt;

  bool _loading = true;
  bool _saving = false;
  String? _loadError;
  String? _error;
  String? _quantityError;

  @override
  void initState() {
    super.initState();
    final quantity = widget.item.quantity;
    if (quantity != null && quantity > 0) _quantity.text = fmtDecimal(quantity, decimals: 2);
    _unit = widget.item.unit;
    _loadLocations();
  }

  @override
  void dispose() {
    _quantity.dispose();
    super.dispose();
  }

  Future<void> _loadLocations() async {
    setState(() {
      _loading = true;
      _loadError = null;
    });
    try {
      final payload = await _stock.list();
      if (!mounted) return;
      setState(() {
        _locations = payload.locations;
        _stockId = payload.locations.isEmpty ? null : payload.locations.first.id;
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
      initialDate: _expiresAt ?? now.add(const Duration(days: 7)),
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

  Future<void> _save() async {
    final quantity = parseDecimal(_quantity.text);
    if (_quantity.text.trim().isNotEmpty && (quantity == null || quantity <= 0)) {
      setState(() => _quantityError = 'Indique une quantité supérieure à 0.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
      _quantityError = null;
    });
    try {
      final created = await _shopping.toStock(
        widget.item.id,
        stockId: _stockId,
        expiresAt: _expiresAt,
        quantity: quantity,
        unit: _unit,
      );
      if (!mounted) return;
      Session.instance.invalidate('stock');
      Session.instance.invalidate('shopping');
      Navigator.of(context).pop(created);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _quantityError = e.fieldError('quantity');
        _error = e.fieldError('stock_id') ?? e.fieldError('unit') ?? e.fieldError('expires_at') ?? e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final inset = MediaQuery.viewInsetsOf(context).bottom;
    return SingleChildScrollView(
      child: Padding(
        padding: EdgeInsets.fromLTRB(20, 4, 20, 20 + inset),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Mettre au stock',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
            ),
            const SizedBox(height: 4),
            Text(
              '${widget.item.label} sera retiré de la liste et rangé dans ton stock.',
              style: const TextStyle(color: MaviohColors.muted, height: 1.4),
            ),
            const SizedBox(height: 16),
            if (_loading)
              const Padding(padding: EdgeInsets.symmetric(vertical: 24), child: LoadingState())
            else if (_loadError != null)
              ErrorState(message: _loadError!, onRetry: _loadLocations, compact: true)
            else ...[
              const _FieldLabel('Lieu de rangement'),
              DropdownButtonFormField<int>(
                initialValue: _stockId,
                items: [
                  for (final location in _locations)
                    DropdownMenuItem<int>(value: location.id, child: Text(location.name)),
                ],
                onChanged: _saving ? null : (value) => setState(() => _stockId = value),
                decoration: const InputDecoration(prefixIcon: Icon(Icons.kitchen_outlined)),
              ),
              const SizedBox(height: 16),
              const _FieldLabel('Quantité'),
              TextField(
                controller: _quantity,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(
                  hintText: 'Ex. : 1,5',
                  errorText: _quantityError,
                  prefixIcon: const Icon(Icons.scale_outlined),
                ),
              ),
              const SizedBox(height: 12),
              const _FieldLabel('Unité'),
              UnitChips(selected: _unit, onChanged: (unit) => setState(() => _unit = unit)),
              const SizedBox(height: 16),
              const _FieldLabel('Date de péremption'),
              OutlinedButton.icon(
                onPressed: _saving ? null : _pickDate,
                icon: const Icon(Icons.event_outlined),
                label: Text(_expiresAt == null ? 'Choisir une date (facultatif)' : fmtDateFr(_expiresAt)),
              ),
              if (_expiresAt != null)
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton(
                    onPressed: _saving ? null : () => setState(() => _expiresAt = null),
                    child: const Text('Retirer la date'),
                  ),
                ),
            ],
            if (_error != null) ...[
              const SizedBox(height: 12),
              StatusBanner.error(_error!),
            ],
            const SizedBox(height: 18),
            FilledButton(
              onPressed: _saving || _loading || _loadError != null ? null : _save,
              child: _saving
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.4))
                  : const Text('Mettre au stock'),
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

class _FieldLabel extends StatelessWidget {
  const _FieldLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6, left: 2),
      child: Text(
        text,
        style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: MaviohColors.textTertiary),
      ),
    );
  }
}
