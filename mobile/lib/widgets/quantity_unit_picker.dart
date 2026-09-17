import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/formatters.dart';
import '../core/session.dart';
import '../models/portion.dart';
import '../theme/app_theme.dart';
import 'macro_pill.dart';

/// What the picker refers to.
enum PickerMode { food, recipe, stock }

/// Current selection of the picker with a client-side nutrition preview.
class QuantitySelection {
  final double quantity;
  final String unit;
  final double? grams;
  final bool isEstimate;
  final double? calories;
  final double? proteins;
  final double? carbs;
  final double? fat;

  const QuantitySelection({
    required this.quantity,
    required this.unit,
    this.grams,
    this.isEstimate = false,
    this.calories,
    this.proteins,
    this.carbs,
    this.fat,
  });

  bool get isValid => quantity > 0;
}

/// Unit chips + numeric field with −/+ + quick chips + live preview (§16.1).
///
/// Reference values are **per 100 g** (food/stock) or **per serving** (recipe).
class QuantityUnitPicker extends StatefulWidget {
  final PickerMode mode;
  final double? refCalories;
  final double? refProteins;
  final double? refCarbs;
  final double? refFat;
  final double? servingSizeG;
  final String? category;
  final bool isLiquid;
  final double? densityGPerMl;
  final double initialQuantity;
  final String initialUnit;
  final double? maxQuantity;
  final String? stockUnit;
  final ValueChanged<QuantitySelection> onChanged;
  final bool dense;

  const QuantityUnitPicker({
    super.key,
    this.mode = PickerMode.food,
    this.refCalories,
    this.refProteins,
    this.refCarbs,
    this.refFat,
    this.servingSizeG,
    this.category,
    this.isLiquid = false,
    this.densityGPerMl,
    this.initialQuantity = 100,
    this.initialUnit = 'g',
    this.maxQuantity,
    this.stockUnit,
    required this.onChanged,
    this.dense = false,
  });

  @override
  State<QuantityUnitPicker> createState() => _QuantityUnitPickerState();
}

class _QuantityUnitPickerState extends State<QuantityUnitPicker> {
  late final TextEditingController _controller;
  late String _unit;
  late double _quantity;
  bool _showAll = false;

  static const List<String> _foodOrder = [
    'g',
    'portion',
    'piece',
    'tranche',
    'cas',
    'cac',
    'verre',
    'bol',
    'assiette',
    'poignee',
    'ml',
  ];

  @override
  void initState() {
    super.initState();
    _unit = _normalizeUnit(widget.initialUnit);
    _quantity = widget.initialQuantity;
    _controller = TextEditingController(text: fmtDecimal(_quantity, decimals: 2));
    WidgetsBinding.instance.addPostFrameCallback((_) => _emit());
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  String _normalizeUnit(String unit) {
    switch (unit) {
      case 'unite':
      case 'unité':
      case 'pièce':
      case 'pc':
        return 'piece';
      default:
        return unit;
    }
  }

  List<Portion> get _portions => Session.instance.portions.isEmpty ? Portion.defaults : Session.instance.portions;

  Portion? _portion(String unit) => Portion.find(_portions, unit);

  double _step(String unit) {
    if (unit == 'g' || unit == 'ml') return 10;
    if (unit == 'piece' || unit == 'portion' || unit == 'tranche') return 0.5;
    return _portion(unit)?.step ?? 1;
  }

  /// Units available given the mode.
  List<String> get _allUnits {
    switch (widget.mode) {
      case PickerMode.recipe:
        return const ['portion'];
      case PickerMode.stock:
        final stockUnit = widget.stockUnit == null ? null : _normalizeUnit(widget.stockUnit!);
        final rest = _foodOrder.where((u) => u != stockUnit && (u != 'portion' || widget.servingSizeG != null)).toList();
        if (stockUnit == null) return rest;
        return [stockUnit, ...rest.where((u) => u != stockUnit)];
      case PickerMode.food:
        final units = _foodOrder.where((u) => u != 'portion' || widget.servingSizeG != null).toList();
        if (widget.isLiquid) {
          units.remove('ml');
          units.insert(1, 'ml');
        }
        return units;
    }
  }

  List<String> get _visibleUnits {
    final all = _allUnits;
    if (_showAll || all.length <= 4) return all;
    final visible = all.take(4).toList();
    if (!visible.contains(_unit) && all.contains(_unit)) visible[3] = _unit;
    return visible;
  }

  String _unitChipLabel(String unit) {
    if (unit == 'portion' && widget.mode != PickerMode.recipe && widget.servingSizeG != null) {
      return 'portion (${fmtDecimal(widget.servingSizeG, decimals: 0)}${nbsp}g)';
    }
    if (widget.mode == PickerMode.recipe) return 'portion';
    return _portion(unit)?.labelShort ?? unitLabelShort(unit);
  }

  double? get _grams {
    if (widget.mode == PickerMode.recipe) return null;
    return Portion.toGrams(
      _portions,
      _quantity,
      _unit,
      servingSizeG: widget.servingSizeG,
      category: widget.category,
      densityGPerMl: widget.densityGPerMl,
    );
  }

  bool get _isEstimate {
    if (widget.mode == PickerMode.recipe) return false;
    if (_unit == 'g') return false;
    if (_unit == 'ml') return widget.densityGPerMl == null && !widget.isLiquid;
    return true;
  }

  QuantitySelection _selection() {
    double? factor;
    if (widget.mode == PickerMode.recipe) {
      factor = _quantity;
    } else {
      final g = _grams;
      factor = g == null ? null : g / 100;
    }
    double? scale(double? ref) => (ref == null || factor == null) ? null : ref * factor;
    return QuantitySelection(
      quantity: _quantity,
      unit: _unit,
      grams: _grams,
      isEstimate: _isEstimate,
      calories: scale(widget.refCalories),
      proteins: scale(widget.refProteins),
      carbs: scale(widget.refCarbs),
      fat: scale(widget.refFat),
    );
  }

  void _emit() => widget.onChanged(_selection());

  void _setQuantity(double value, {bool updateField = true}) {
    var q = value;
    if (q < 0) q = 0;
    if (widget.maxQuantity != null && _unit == (widget.stockUnit == null ? _unit : _normalizeUnit(widget.stockUnit!)) && q > widget.maxQuantity!) {
      q = widget.maxQuantity!;
    }
    q = double.parse(q.toStringAsFixed(2));
    setState(() => _quantity = q);
    if (updateField) {
      final text = fmtDecimal(q, decimals: 2);
      _controller.value = TextEditingValue(text: text, selection: TextSelection.collapsed(offset: text.length));
    }
    _emit();
  }

  void _setUnit(String unit) {
    if (unit == _unit) return;
    setState(() => _unit = unit);
    // Sensible default quantity when switching unit families.
    if (unit == 'g' || unit == 'ml') {
      if (_quantity < 5) _setQuantity(100);
    } else if (_quantity > 20) {
      _setQuantity(1);
    }
    _emit();
  }

  List<double> get _quickValues {
    if (_unit == 'g' || _unit == 'ml') return const [50, 100, 150, 200];
    if (_unit == 'portion' || _unit == 'piece') return const [0.5, 1, 2];
    return const [];
  }

  String _quickLabel(double v) => v == 0.5 ? '½' : fmtDecimal(v, decimals: 0);

  @override
  Widget build(BuildContext context) {
    final selection = _selection();
    final preview = <String>[
      if (selection.grams != null && _unit != 'g') '≈ ${fmtGrams(selection.grams, decimals: 0)}',
      if (selection.calories != null) fmtKcal(selection.calories),
      if (selection.proteins != null) 'P ${fmtInt(selection.proteins)}${nbsp}g',
      if (selection.carbs != null) 'G ${fmtInt(selection.carbs)}${nbsp}g',
      if (selection.fat != null) 'L ${fmtInt(selection.fat)}${nbsp}g',
    ];
    final all = _allUnits;
    final showMore = all.length > 4 && !_showAll;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (all.length > 1) ...[
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final unit in _visibleUnits)
                ChoiceChip(
                  label: Text(_unitChipLabel(unit)),
                  selected: _unit == unit,
                  onSelected: (_) => _setUnit(unit),
                ),
              if (showMore)
                ActionChip(
                  label: const Text('Plus…'),
                  avatar: const Icon(Icons.expand_more_rounded, size: 16),
                  onPressed: () => setState(() => _showAll = true),
                ),
            ],
          ),
          const SizedBox(height: 12),
        ],
        Row(
          children: [
            _StepButton(
              icon: Icons.remove_rounded,
              tooltip: 'Diminuer',
              onPressed: _quantity <= 0 ? null : () => _setQuantity(_quantity - _step(_unit)),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: TextField(
                controller: _controller,
                textAlign: TextAlign.center,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
                style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: MaviohColors.text),
                decoration: InputDecoration(
                  suffixText: _unitChipLabel(_unit),
                  suffixStyle: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.muted),
                  contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                ),
                onChanged: (text) {
                  final parsed = parseDecimal(text);
                  if (parsed != null) _setQuantity(parsed, updateField: false);
                },
              ),
            ),
            const SizedBox(width: 10),
            _StepButton(
              icon: Icons.add_rounded,
              tooltip: 'Augmenter',
              onPressed: () => _setQuantity(_quantity + _step(_unit)),
            ),
          ],
        ),
        if (_quickValues.isNotEmpty) ...[
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final v in _quickValues)
                ActionChip(
                  label: Text('${_quickLabel(v)}${_unit == 'g' || _unit == 'ml' ? '$nbsp$_unit' : ''}'),
                  onPressed: () => _setQuantity(v),
                ),
              if (widget.maxQuantity != null)
                ActionChip(
                  avatar: const Icon(Icons.inventory_2_outlined, size: 16),
                  label: Text('Tout (${fmtDecimal(widget.maxQuantity, decimals: 2)})'),
                  onPressed: () => _setQuantity(widget.maxQuantity!),
                ),
            ],
          ),
        ],
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(
              child: Text(
                preview.isEmpty ? 'Valeurs nutritionnelles indisponibles' : preview.join(' · '),
                style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
              ),
            ),
            if (selection.isEstimate) ...[const SizedBox(width: 8), const EstimatePill()],
          ],
        ),
      ],
    );
  }
}

class _StepButton extends StatelessWidget {
  final IconData icon;
  final String tooltip;
  final VoidCallback? onPressed;

  const _StepButton({required this.icon, required this.tooltip, this.onPressed});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 48,
      height: 48,
      child: IconButton.filledTonal(
        tooltip: tooltip,
        onPressed: onPressed,
        icon: Icon(icon),
        style: IconButton.styleFrom(
          backgroundColor: MaviohColors.tint(MaviohColors.primary, 0.1),
          foregroundColor: MaviohColors.primary,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        ),
      ),
    );
  }
}
