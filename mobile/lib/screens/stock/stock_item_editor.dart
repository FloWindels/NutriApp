import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../models/portion.dart';
import '../../models/stock.dart';
import '../../services/stock_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/confirm_dialog.dart';
import '../../widgets/status_banner.dart';
import '../stock_screen.dart' show invalidateStockCaches;

/// Inline expandable editor of a stock item (§6.2 + §16.4).
///
/// Owns its own controllers so the caret never jumps: quantity (comma accepted),
/// unit chips, expiry date + DLC/DDM, `min_quantity`, `opened_at` and a move to
/// another location. « Mettre à jour » and « Supprimer » (with confirmation) both
/// keep the editor open until the server answers 2xx.
class StockItemEditor extends StatefulWidget {
  const StockItemEditor({
    super.key,
    required this.item,
    required this.locations,
    required this.onChanged,
    this.service,
  });

  final StockItem item;
  final List<StockLocation> locations;

  /// Called after a successful update or delete so the screen reloads.
  final VoidCallback onChanged;

  /// Injectable for tests.
  final StockService? service;

  @override
  State<StockItemEditor> createState() => _StockItemEditorState();
}

class _StockItemEditorState extends State<StockItemEditor> {
  late final StockService _service = widget.service ?? StockService();

  late final TextEditingController _quantity;
  late final TextEditingController _minQuantity;

  late String _unit;
  late int _stockId;
  late String _expiryKind;
  DateTime? _expiresAt;
  DateTime? _openedAt;
  bool _showAllUnits = false;

  bool _saving = false;
  bool _deleting = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  @override
  void initState() {
    super.initState();
    final item = widget.item;
    _quantity = TextEditingController(text: _numberText(item.quantity));
    _minQuantity = TextEditingController(text: item.minQuantity == null ? '' : _numberText(item.minQuantity!));
    _unit = item.unit;
    _stockId = item.stockId;
    _expiryKind = item.expiryKind == 'ddm' ? 'ddm' : 'dlc';
    _expiresAt = item.expiresAt;
    _openedAt = item.openedAt;
    Session.instance.loadPortions();
  }

  @override
  void dispose() {
    _quantity.dispose();
    _minQuantity.dispose();
    super.dispose();
  }

  static String _numberText(double value) {
    if (value == value.roundToDouble()) return value.round().toString();
    return fmtDecimal(value, decimals: 2).replaceAll(nnbsp, '');
  }

  List<String> get _unitOptions {
    final portions = Session.instance.portions;
    final source = portions.isEmpty ? Portion.defaults : portions;
    const order = ['g', 'ml', 'piece', 'portion', 'tranche', 'cas', 'cac', 'verre', 'bol', 'poignee', 'assiette'];
    final available = source.map((p) => p.unit).toSet();
    final units = <String>[
      // The item's own unit always comes first (§16.4).
      widget.item.unit,
      for (final unit in order)
        if (available.contains(unit) && unit != widget.item.unit) unit,
    ];
    return units;
  }

  Future<void> _pickDate({required bool opened}) async {
    final now = today();
    final initial = opened ? (_openedAt ?? now) : (_expiresAt ?? now);
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(now.year - 2),
      lastDate: DateTime(now.year + 10),
      locale: const Locale('fr', 'FR'),
      helpText: opened ? 'Date d’ouverture' : 'Date de péremption',
      cancelText: AppStrings.cancel,
      confirmText: 'Valider',
    );
    if (picked == null || !mounted) return;
    final date = DateTime(picked.year, picked.month, picked.day);
    setState(() {
      if (opened) {
        _openedAt = date;
      } else {
        _expiresAt = date;
      }
    });
  }

  Future<void> _save() async {
    if (_saving || _deleting) return;
    FocusScope.of(context).unfocus();
    final quantity = parseDecimal(_quantity.text);
    if (quantity == null || quantity < 0) {
      setState(() {
        _fieldErrors = {'quantity': 'Indique une quantité (0 ou plus).'};
        _error = 'Vérifie les champs signalés.';
      });
      return;
    }
    final minQuantityText = _minQuantity.text.trim();
    final minQuantity = minQuantityText.isEmpty ? null : parseDecimal(minQuantityText);
    if (minQuantityText.isNotEmpty && minQuantity == null) {
      setState(() {
        _fieldErrors = {'min_quantity': 'Valeur invalide.'};
        _error = 'Vérifie les champs signalés.';
      });
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
      _fieldErrors = const {};
    });
    try {
      await _service.updateItem(widget.item.id, <String, dynamic>{
        'quantity': quantity,
        'unit': _unit,
        'stock_id': _stockId,
        'expires_at': _expiresAt == null ? null : isoDate(_expiresAt!),
        'expiry_kind': _expiryKind,
        'min_quantity': minQuantity,
        'opened_at': _openedAt == null ? null : isoDate(_openedAt!),
      });
      if (!mounted) return;
      invalidateStockCaches();
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        const SnackBar(content: Text('Article mis à jour.')),
      );
      widget.onChanged();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
        _fieldErrors = e.fieldErrors.map((key, value) => MapEntry(key, value.first));
      });
    }
  }

  Future<void> _delete() async {
    if (_saving || _deleting) return;
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'Supprimer « ${widget.item.foodName} » ?',
      message: 'Cet article sera retiré de ton stock. Cette action est définitive.',
      confirmLabel: 'Supprimer',
      destructive: true,
      icon: Icons.delete_outline_rounded,
    );
    if (!confirmed || !mounted) return;
    setState(() {
      _deleting = true;
      _error = null;
    });
    try {
      await _service.deleteItem(widget.item.id);
      if (!mounted) return;
      invalidateStockCaches();
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        const SnackBar(content: Text('Article supprimé du stock.')),
      );
      widget.onChanged();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _deleting = false;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final units = _unitOptions;
    final visibleUnits = _showAllUnits || units.length <= 4 ? units : units.take(4).toList();
    final busy = _saving || _deleting;

    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Divider(height: 18),
          if (_error != null)
            StatusBanner.error(
              _error!,
              margin: const EdgeInsets.only(bottom: 12),
              onClose: () => setState(() => _error = null),
            ),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                flex: 3,
                child: TextField(
                  key: ValueKey('stock-quantity-${widget.item.id}'),
                  controller: _quantity,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: InputDecoration(
                    labelText: 'Quantité',
                    hintText: 'Ex. : 1,5',
                    errorText: _fieldErrors['quantity'],
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                flex: 3,
                child: TextField(
                  key: ValueKey('stock-min-${widget.item.id}'),
                  controller: _minQuantity,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: InputDecoration(
                    labelText: 'Seuil bas',
                    hintText: 'facultatif',
                    errorText: _fieldErrors['min_quantity'],
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final unit in visibleUnits)
                ChoiceChip(
                  label: Text(unitLabelShort(unit)),
                  selected: _unit == unit,
                  onSelected: busy ? null : (_) => setState(() => _unit = unit),
                ),
              if (!_showAllUnits && units.length > 4)
                ActionChip(
                  label: const Text('Plus…'),
                  avatar: const Icon(Icons.expand_more_rounded, size: 16),
                  onPressed: () => setState(() => _showAllUnits = true),
                ),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: busy ? null : () => _pickDate(opened: false),
                  icon: const Icon(Icons.event_outlined, size: 18),
                  label: Text(
                    _expiresAt == null ? 'Péremption' : fmtDateFr(_expiresAt),
                    overflow: TextOverflow.ellipsis,
                  ),
                  style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                ),
              ),
              if (_expiresAt != null)
                IconButton(
                  tooltip: 'Retirer la date de péremption',
                  onPressed: busy ? null : () => setState(() => _expiresAt = null),
                  icon: const Icon(Icons.close_rounded),
                ),
              const SizedBox(width: 6),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: busy ? null : () => _pickDate(opened: true),
                  icon: const Icon(Icons.lock_open_rounded, size: 18),
                  label: Text(
                    _openedAt == null ? 'Ouvert le…' : fmtDateFr(_openedAt),
                    overflow: TextOverflow.ellipsis,
                  ),
                  style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                ),
              ),
              if (_openedAt != null)
                IconButton(
                  tooltip: 'Retirer la date d’ouverture',
                  onPressed: busy ? null : () => setState(() => _openedAt = null),
                  icon: const Icon(Icons.close_rounded),
                ),
            ],
          ),
          const SizedBox(height: 12),
          SegmentedButton<String>(
            segments: const [
              ButtonSegment<String>(value: 'dlc', label: Text('DLC'), tooltip: 'Date limite de consommation'),
              ButtonSegment<String>(value: 'ddm', label: Text('DDM'), tooltip: 'Date de durabilité minimale'),
            ],
            selected: {_expiryKind},
            onSelectionChanged: busy ? null : (values) => setState(() => _expiryKind = values.first),
          ),
          if (widget.locations.length > 1) ...[
            const SizedBox(height: 14),
            DropdownButtonFormField<int>(
              initialValue: widget.locations.any((l) => l.id == _stockId) ? _stockId : null,
              decoration: InputDecoration(labelText: 'Lieu', errorText: _fieldErrors['stock_id']),
              items: [
                for (final location in widget.locations)
                  DropdownMenuItem<int>(value: location.id, child: Text(location.name)),
              ],
              onChanged: busy ? null : (value) => setState(() => _stockId = value ?? _stockId),
            ),
          ],
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: FilledButton.icon(
                  onPressed: busy ? null : _save,
                  icon: _saving
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                        )
                      : const Icon(Icons.check_rounded, size: 18),
                  label: const Text('Mettre à jour'),
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                ),
              ),
              const SizedBox(width: 10),
              OutlinedButton.icon(
                onPressed: busy ? null : _delete,
                icon: const Icon(Icons.delete_outline_rounded, size: 18),
                label: const Text('Supprimer'),
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size(48, 48),
                  foregroundColor: MaviohColors.error,
                  side: const BorderSide(color: MaviohColors.error),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
