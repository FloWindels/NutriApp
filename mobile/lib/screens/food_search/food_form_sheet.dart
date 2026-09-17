import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../core/strings.dart';
import '../../models/food.dart';
import '../../services/food_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/barcode_scanner_sheet.dart';
import '../../widgets/status_banner.dart';

/// Result of the create/edit form.
class FoodFormResult {
  final Food food;
  final String? message;
  final bool alreadyExisted;

  const FoodFormResult({required this.food, this.message, this.alreadyExisted = false});
}

/// « Créer un aliment » / « Modifier » form (§16.4).
///
/// Values are per 100 g (or 100 ml). The sheet stays open on failure and maps
/// 422 field errors to the matching inputs.
class FoodFormSheet extends StatefulWidget {
  const FoodFormSheet._({this.food, this.initialBarcode, this.initialName});

  final Food? food;
  final String? initialBarcode;
  final String? initialName;

  /// Opens the sheet. [food] non-null switches to edit mode (`PUT /foods/{id}`).
  static Future<FoodFormResult?> show(
    BuildContext context, {
    Food? food,
    String? initialBarcode,
    String? initialName,
  }) {
    return showModalBottomSheet<FoodFormResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      useRootNavigator: true,
      builder: (_) => FoodFormSheet._(food: food, initialBarcode: initialBarcode, initialName: initialName),
    );
  }

  @override
  State<FoodFormSheet> createState() => _FoodFormSheetState();
}

class _FoodFormSheetState extends State<FoodFormSheet> {
  final _service = FoodService();

  late final TextEditingController _barcode;
  late final TextEditingController _name;
  late final TextEditingController _brand;
  late final TextEditingController _imageUrl;
  late final TextEditingController _calories;
  late final TextEditingController _proteins;
  late final TextEditingController _carbs;
  late final TextEditingController _fat;
  late final TextEditingController _fiber;
  late final TextEditingController _sugar;
  late final TextEditingController _salt;
  late final TextEditingController _servingSize;
  late final TextEditingController _category;

  bool _saving = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  bool get _isEdit => widget.food != null;

  @override
  void initState() {
    super.initState();
    final food = widget.food;
    String fmt(double? value) => value == null ? '' : fmtDecimal(value, decimals: 2).replaceAll(nnbsp, '');
    _barcode = TextEditingController(text: food?.barcode ?? widget.initialBarcode ?? '');
    _name = TextEditingController(text: food?.name ?? widget.initialName ?? '');
    _brand = TextEditingController(text: food?.brand ?? '');
    _imageUrl = TextEditingController(text: food?.imageUrl ?? '');
    _calories = TextEditingController(text: fmt(food?.calories));
    _proteins = TextEditingController(text: fmt(food?.proteins));
    _carbs = TextEditingController(text: fmt(food?.carbs));
    _fat = TextEditingController(text: fmt(food?.fat));
    _fiber = TextEditingController(text: fmt(food?.fiber));
    _sugar = TextEditingController(text: fmt(food?.sugar));
    _salt = TextEditingController(text: fmt(food?.salt));
    _servingSize = TextEditingController(text: fmt(food?.servingSizeG));
    _category = TextEditingController(text: food?.category ?? '');
  }

  @override
  void dispose() {
    for (final controller in [
      _barcode,
      _name,
      _brand,
      _imageUrl,
      _calories,
      _proteins,
      _carbs,
      _fat,
      _fiber,
      _sugar,
      _salt,
      _servingSize,
      _category,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _scan() async {
    final code = await BarcodeScannerSheet.show(context);
    if (code == null || !mounted) return;
    setState(() {
      _barcode.text = code;
      _fieldErrors = Map.of(_fieldErrors)..remove('barcode');
    });
  }

  Map<String, dynamic> _body() {
    final barcode = _barcode.text.trim();
    final brand = _brand.text.trim();
    final imageUrl = _imageUrl.text.trim();
    final category = _category.text.trim();
    return <String, dynamic>{
      'barcode': barcode.isEmpty ? null : barcode,
      'name': _name.text.trim(),
      'brand': brand.isEmpty ? null : brand,
      'image_url': imageUrl.isEmpty ? null : imageUrl,
      'calories': parseDecimal(_calories.text),
      'proteins': parseDecimal(_proteins.text),
      'carbs': parseDecimal(_carbs.text),
      'fat': parseDecimal(_fat.text),
      'fiber': parseDecimal(_fiber.text),
      'sugar': parseDecimal(_sugar.text),
      'salt': parseDecimal(_salt.text),
      'serving_size_g': parseDecimal(_servingSize.text),
      'category': category.isEmpty ? null : category,
      if (!_isEdit) 'source_type': 'manual',
    };
  }

  String? _validate() {
    if (_name.text.trim().isEmpty) {
      setState(() => _fieldErrors = {..._fieldErrors, 'name': 'Le nom est obligatoire.'});
      return 'Vérifie les champs du formulaire.';
    }
    final barcode = _barcode.text.trim();
    if (barcode.isNotEmpty && !RegExp(r'^\d{8,14}$').hasMatch(barcode)) {
      setState(() => _fieldErrors = {..._fieldErrors, 'barcode': 'Le code-barres doit contenir 8 à 14 chiffres.'});
      return 'Vérifie les champs du formulaire.';
    }
    return null;
  }

  Future<void> _submit() async {
    setState(() {
      _error = null;
      _fieldErrors = const {};
    });
    final invalid = _validate();
    if (invalid != null) {
      setState(() => _error = invalid);
      return;
    }
    setState(() => _saving = true);
    try {
      final body = _body();
      if (_isEdit) {
        final food = await _service.update(widget.food!.id, body);
        if (!mounted) return;
        Navigator.of(context).pop(FoodFormResult(food: food, message: 'Aliment mis à jour.'));
      } else {
        final result = await _service.create(body);
        if (!mounted) return;
        Navigator.of(context).pop(
          FoodFormResult(
            food: result.food,
            message: result.message ?? 'Aliment créé.',
            alreadyExisted: result.alreadyExisted,
          ),
        );
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
        _fieldErrors = {
          for (final entry in e.fieldErrors.entries)
            if (entry.value.isNotEmpty) entry.key: entry.value.first,
        };
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final height = MediaQuery.sizeOf(context).height * 0.92;
    final bottomInset = MediaQuery.viewInsetsOf(context).bottom;

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
                  child: Text(
                    _isEdit ? 'Modifier l’aliment' : 'Créer un aliment',
                    style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
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
              padding: EdgeInsets.fromLTRB(20, 4, 20, 24 + bottomInset),
              children: [
                if (_error != null)
                  StatusBanner.error(
                    _error!,
                    margin: const EdgeInsets.only(bottom: 14),
                    onClose: () => setState(() => _error = null),
                  ),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: _field(
                        controller: _barcode,
                        label: 'Code-barres (facultatif)',
                        keyboard: TextInputType.number,
                        errorKey: 'barcode',
                        digitsOnly: true,
                      ),
                    ),
                    const SizedBox(width: 8),
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: IconButton.filledTonal(
                        tooltip: 'Scanner un code-barres',
                        onPressed: _saving ? null : _scan,
                        icon: const Icon(Icons.qr_code_scanner_rounded),
                        style: IconButton.styleFrom(minimumSize: const Size(52, 52)),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                _field(controller: _name, label: 'Nom *', errorKey: 'name', textCapitalization: TextCapitalization.sentences),
                const SizedBox(height: 12),
                _field(controller: _brand, label: 'Marque', errorKey: 'brand', textCapitalization: TextCapitalization.sentences),
                const SizedBox(height: 12),
                _field(controller: _imageUrl, label: 'URL de l’image', errorKey: 'image_url', keyboard: TextInputType.url),
                const SizedBox(height: 18),
                const Text(
                  'Valeurs pour 100 g (ou 100 ml)',
                  style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
                ),
                const SizedBox(height: 10),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(child: _field(controller: _calories, label: 'Calories (kcal)', errorKey: 'calories', decimal: true)),
                    const SizedBox(width: 8),
                    Expanded(child: _field(controller: _proteins, label: 'Protéines (g)', errorKey: 'proteins', decimal: true)),
                  ],
                ),
                const SizedBox(height: 12),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(child: _field(controller: _carbs, label: 'Glucides (g)', errorKey: 'carbs', decimal: true)),
                    const SizedBox(width: 8),
                    Expanded(child: _field(controller: _fat, label: 'Lipides (g)', errorKey: 'fat', decimal: true)),
                  ],
                ),
                const SizedBox(height: 12),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(child: _field(controller: _fiber, label: 'Fibres (g)', errorKey: 'fiber', decimal: true)),
                    const SizedBox(width: 8),
                    Expanded(child: _field(controller: _sugar, label: 'Sucres (g)', errorKey: 'sugar', decimal: true)),
                  ],
                ),
                const SizedBox(height: 12),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(child: _field(controller: _salt, label: 'Sel (g)', errorKey: 'salt', decimal: true)),
                    const SizedBox(width: 8),
                    Expanded(
                      child: _field(
                        controller: _servingSize,
                        label: 'Portion (g)',
                        errorKey: 'serving_size_g',
                        decimal: true,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                _field(
                  controller: _category,
                  label: 'Catégorie',
                  errorKey: 'category',
                  helper: 'Ex. : yaourt, fruit, pain — sert à estimer une portion.',
                ),
                const SizedBox(height: 10),
                const Text(
                  'L’aliment sera partagé avec la communauté Mavi’oh.',
                  style: TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.4),
                ),
                const SizedBox(height: 20),
                FilledButton.icon(
                  onPressed: _saving ? null : _submit,
                  icon: _saving
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                        )
                      : Icon(_isEdit ? Icons.check_rounded : Icons.add_rounded),
                  label: Text(_saving ? 'Enregistrement…' : (_isEdit ? AppStrings.save : 'Créer l’aliment')),
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _field({
    required TextEditingController controller,
    required String label,
    String? errorKey,
    String? helper,
    TextInputType? keyboard,
    bool decimal = false,
    bool digitsOnly = false,
    TextCapitalization textCapitalization = TextCapitalization.none,
  }) {
    return TextField(
      controller: controller,
      enabled: !_saving,
      textCapitalization: textCapitalization,
      keyboardType: decimal ? const TextInputType.numberWithOptions(decimal: true) : keyboard,
      inputFormatters: [
        if (digitsOnly) FilteringTextInputFormatter.digitsOnly,
        if (decimal) FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]')),
      ],
      decoration: InputDecoration(
        labelText: label,
        helperText: helper,
        helperMaxLines: 2,
        errorText: errorKey == null ? null : _fieldErrors[errorKey],
        errorMaxLines: 3,
      ),
      onChanged: errorKey == null
          ? null
          : (_) {
              if (_fieldErrors.containsKey(errorKey)) {
                setState(() => _fieldErrors = Map.of(_fieldErrors)..remove(errorKey));
              }
            },
    );
  }
}
