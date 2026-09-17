import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../models/portion.dart';
import '../../models/recipe.dart';
import '../../services/recipe_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/app_card.dart';
import '../../widgets/barcode_scanner_sheet.dart';
import '../../widgets/macro_pill.dart';
import '../../widgets/recipe_tile.dart';
import '../../widgets/status_banner.dart';

/// Maximum weight of a recipe photo (§16.4).
const int kRecipeImageMaxBytes = 350 * 1024;

/// Editor of a recipe (creation and edition), as its own [StatefulWidget] so
/// every field owns a stable [TextEditingController] (no caret jumps).
///
/// Returns the saved [Recipe] through `Navigator.pop` — never before a 2xx.
class RecipeEditorSheet extends StatefulWidget {
  const RecipeEditorSheet({super.key, this.recipe, this.service, this.imagePicker});

  /// Recipe being edited (null → creation).
  final Recipe? recipe;

  /// Injectable for tests.
  final RecipeService? service;

  /// Injectable for tests (the platform picker is never built in tests).
  final ImagePicker? imagePicker;

  /// Opens the editor as a bottom sheet. Resolves with the saved recipe or null.
  static Future<Recipe?> show(BuildContext context, {Recipe? recipe, RecipeService? service}) {
    final height = MediaQuery.sizeOf(context).height * 0.92;
    return showModalBottomSheet<Recipe>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => SizedBox(
        height: height,
        child: RecipeEditorSheet(recipe: recipe, service: service),
      ),
    );
  }

  @override
  State<RecipeEditorSheet> createState() => _RecipeEditorSheetState();
}

class _RecipeEditorSheetState extends State<RecipeEditorSheet> {
  late final RecipeService _service = widget.service ?? RecipeService();
  late final ImagePicker _picker = widget.imagePicker ?? ImagePicker();

  final _titleController = TextEditingController();
  final _descriptionController = TextEditingController();
  final _prepTimeController = TextEditingController();
  final _caloriesController = TextEditingController();
  final _proteinsController = TextEditingController();
  final _carbsController = TextEditingController();
  final _fatController = TextEditingController();
  final _imageLinkController = TextEditingController();

  final List<_IngredientDraft> _ingredients = <_IngredientDraft>[];
  final Set<String> _tags = <String>{};
  final Set<String> _mealTypes = <String>{};

  double _servings = 1;
  bool _isPublic = false;
  String? _imageUrl;

  bool _saving = false;
  bool _estimating = false;
  int _estimateRun = 0;
  bool _macrosEstimated = false;
  String? _estimateNote;
  List<String> _unresolved = const [];

  Map<String, List<String>> _fieldErrors = const {};
  String? _formError;
  String? _imageError;

  bool get _isEdit => widget.recipe != null;

  @override
  void initState() {
    super.initState();
    final recipe = widget.recipe;
    if (recipe != null) {
      _titleController.text = recipe.title;
      _descriptionController.text = recipe.description ?? '';
      _prepTimeController.text = recipe.prepTimeMinutes?.toString() ?? '';
      _caloriesController.text = _numberText(recipe.calories);
      _proteinsController.text = _numberText(recipe.proteins);
      _carbsController.text = _numberText(recipe.carbs);
      _fatController.text = _numberText(recipe.fat);
      _servings = recipe.servings <= 0 ? 1 : recipe.servings;
      _isPublic = recipe.isPublic;
      _imageUrl = recipe.imageUrl;
      _tags.addAll(recipe.tags);
      _mealTypes.addAll(recipe.mealTypes);
      _macrosEstimated = recipe.isEstimate;
      for (final ingredient in recipe.ingredients) {
        _ingredients.add(_IngredientDraft.fromIngredient(ingredient));
      }
    }
    if (_ingredients.isEmpty) _ingredients.add(_IngredientDraft());
    Session.instance.loadPortions();
  }

  @override
  void dispose() {
    _titleController.dispose();
    _descriptionController.dispose();
    _prepTimeController.dispose();
    _caloriesController.dispose();
    _proteinsController.dispose();
    _carbsController.dispose();
    _fatController.dispose();
    _imageLinkController.dispose();
    for (final ingredient in _ingredients) {
      ingredient.dispose();
    }
    super.dispose();
  }

  static String _numberText(double? value) {
    if (value == null) return '';
    if (value == value.roundToDouble()) return value.round().toString();
    return fmtDecimal(value, decimals: 2).replaceAll(nbsp, '');
  }

  String? _errorFor(String field) {
    final list = _fieldErrors[field];
    if (list == null || list.isEmpty) return null;
    return list.first;
  }

  // ----- Ingredients --------------------------------------------------------

  void _addIngredient() {
    setState(() => _ingredients.add(_IngredientDraft()));
  }

  void _removeIngredient(int index) {
    if (index < 0 || index >= _ingredients.length) return;
    setState(() {
      _ingredients.removeAt(index).dispose();
      if (_ingredients.isEmpty) _ingredients.add(_IngredientDraft());
    });
  }

  Future<void> _scanIngredient(int index) async {
    final code = await BarcodeScannerSheet.show(context);
    if (code == null || !mounted) return;
    setState(() {
      _ingredients[index].ean.text = code;
      _imageError = null;
    });
  }

  List<RecipeIngredient> _collectIngredients() {
    final list = <RecipeIngredient>[];
    for (final draft in _ingredients) {
      final ingredient = draft.toIngredient();
      if (ingredient == null) continue;
      list.add(ingredient);
    }
    return list;
  }

  // ----- Estimation ---------------------------------------------------------

  Future<void> _estimateFromIngredients() async {
    final ingredients = _collectIngredients();
    if (ingredients.isEmpty) {
      setState(() => _formError = 'Ajoute au moins un ingrédient (nom ou code-barres) pour lancer l’estimation.');
      return;
    }
    final run = ++_estimateRun;
    setState(() {
      _estimating = true;
      _formError = null;
    });
    try {
      final estimate = await _service.estimate(ingredients);
      if (!mounted || run != _estimateRun) return;
      setState(() {
        _estimating = false;
        _caloriesController.text = _numberText(estimate.calories);
        _proteinsController.text = _numberText(estimate.proteins);
        _carbsController.text = _numberText(estimate.carbs);
        _fatController.text = _numberText(estimate.fat);
        _macrosEstimated = true;
        _estimateNote = '${estimate.resolvedCount}/${estimate.totalCount} ingrédients reconnus';
        _unresolved = [
          for (final detail in estimate.details)
            if (!detail.resolved && detail.name.trim().isNotEmpty) detail.name,
        ];
        _fieldErrors = const {};
      });
    } on ApiException catch (e) {
      if (!mounted || run != _estimateRun) return;
      setState(() {
        _estimating = false;
        _formError = e.message;
      });
    }
  }

  // ----- Image --------------------------------------------------------------

  String _mimeType(String path) {
    final lower = path.toLowerCase();
    if (lower.endsWith('.png')) return 'image/png';
    if (lower.endsWith('.webp')) return 'image/webp';
    if (lower.endsWith('.gif')) return 'image/gif';
    return 'image/jpeg';
  }

  Future<void> _pickImage(ImageSource source) async {
    setState(() => _imageError = null);
    try {
      final picked = await _picker.pickImage(source: source, maxWidth: 1200, imageQuality: 75);
      if (picked == null || !mounted) return;
      final bytes = await picked.readAsBytes();
      if (!mounted) return;
      if (bytes.lengthInBytes > kRecipeImageMaxBytes) {
        final ko = (bytes.lengthInBytes / 1024).round();
        setState(() => _imageError =
            'Photo trop lourde ($ko${nbsp}Ko). La limite est de 350${nbsp}Ko : recadre l’image ou choisis-en une plus légère.');
        return;
      }
      setState(() {
        _imageUrl = 'data:${_mimeType(picked.path)};base64,${base64Encode(bytes)}';
        _imageLinkController.clear();
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _imageError = source == ImageSource.camera
          ? 'Impossible d’ouvrir la caméra. Vérifie les autorisations de l’application.'
          : 'Impossible d’ouvrir la galerie. Vérifie les autorisations de l’application.');
    }
  }

  void _useImageLink() {
    final raw = _imageLinkController.text.trim();
    if (raw.isEmpty) {
      setState(() => _imageError = 'Colle un lien commençant par http:// ou https://.');
      return;
    }
    final uri = Uri.tryParse(raw);
    final valid = uri != null && (uri.scheme == 'http' || uri.scheme == 'https') && uri.host.isNotEmpty;
    setState(() {
      if (!valid) {
        _imageError = 'Ce lien n’est pas valide. Il doit commencer par http:// ou https://.';
        return;
      }
      _imageUrl = raw;
      _imageError = null;
    });
  }

  void _clearImage() {
    setState(() {
      _imageUrl = null;
      _imageError = null;
      _imageLinkController.clear();
    });
  }

  // ----- Save ---------------------------------------------------------------

  Map<String, dynamic> _buildBody() {
    final ingredients = _collectIngredients();
    return <String, dynamic>{
      'title': _titleController.text.trim(),
      'description': _nullable(_descriptionController.text),
      'prep_time_minutes': parseDecimal(_prepTimeController.text)?.round(),
      'calories': parseDecimal(_caloriesController.text),
      'proteins': parseDecimal(_proteinsController.text),
      'carbs': parseDecimal(_carbsController.text),
      'fat': parseDecimal(_fatController.text),
      'servings': _servings,
      'image_url': _imageUrl,
      'is_public': _isPublic,
      'tags': _tags.toList(),
      'meal_types': _mealTypes.toList(),
      'ingredients': ingredients.map((i) => i.toJson()).toList(),
    };
  }

  static String? _nullable(String value) {
    final trimmed = value.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  Future<void> _save() async {
    if (_saving) return;
    FocusScope.of(context).unfocus();
    final title = _titleController.text.trim();
    final calories = parseDecimal(_caloriesController.text);
    final errors = <String, List<String>>{};
    if (title.isEmpty) errors['title'] = ['Le titre est requis.'];
    if (calories == null) errors['calories'] = ['Les calories sont requises.'];
    if (errors.isNotEmpty) {
      setState(() {
        _fieldErrors = errors;
        _formError = 'Vérifie les champs en rouge.';
      });
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
      _fieldErrors = const {};
    });

    try {
      final body = _buildBody();
      final recipe = _isEdit ? await _service.update(widget.recipe!.id, body) : await _service.create(body);
      if (!mounted) return;
      Session.instance.invalidate('recipes');
      Navigator.of(context).pop(recipe);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _fieldErrors = e.fieldErrors;
        _formError = e.message;
      });
    }
  }

  // ----- UI -----------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final inset = MediaQuery.viewInsetsOf(context).bottom;
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 4, 8, 4),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  _isEdit ? 'Modifier la recette' : 'Nouvelle recette',
                  style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800, color: MaviohColors.text),
                ),
              ),
              IconButton(
                tooltip: 'Fermer',
                onPressed: _saving ? null : () => Navigator.of(context).pop(),
                icon: const Icon(Icons.close_rounded),
              ),
            ],
          ),
        ),
        Expanded(
          child: ListView(
            padding: EdgeInsets.fromLTRB(20, 4, 20, 20),
            children: [
              if (_formError != null)
                StatusBanner.error(
                  _formError!,
                  margin: const EdgeInsets.only(bottom: 14),
                  onClose: () => setState(() => _formError = null),
                ),
              _sectionTitle('Recette'),
              TextField(
                controller: _titleController,
                textCapitalization: TextCapitalization.sentences,
                decoration: InputDecoration(
                  labelText: 'Titre',
                  hintText: 'Ex. : gratin de courgettes',
                  errorText: _errorFor('title'),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _descriptionController,
                minLines: 2,
                maxLines: 5,
                textCapitalization: TextCapitalization.sentences,
                decoration: InputDecoration(
                  labelText: 'Description',
                  hintText: 'Préparation, astuces…',
                  errorText: _errorFor('description'),
                ),
              ),
              const SizedBox(height: 12),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: TextField(
                      controller: _prepTimeController,
                      keyboardType: TextInputType.number,
                      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                      decoration: InputDecoration(
                        labelText: 'Temps de préparation',
                        suffixText: 'min',
                        errorText: _errorFor('prep_time_minutes'),
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              _servingsStepper(),
              const SizedBox(height: 18),
              _sectionTitle('Valeurs nutritionnelles', trailing: _macrosEstimated ? const EstimatePill() : null),
              const Text(
                'Valeurs totales de la recette (toutes portions confondues).',
                style: TextStyle(fontSize: 12.5, color: MaviohColors.muted),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: _caloriesController,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                onChanged: (_) {
                  if (_macrosEstimated) setState(() => _macrosEstimated = false);
                },
                decoration: InputDecoration(
                  labelText: 'Calories',
                  suffixText: 'kcal',
                  errorText: _errorFor('calories'),
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
              const SizedBox(height: 12),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: _estimating ? null : _estimateFromIngredients,
                  icon: _estimating
                      ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.calculate_outlined),
                  label: Text(_estimating ? 'Estimation en cours…' : 'Estimer depuis les ingrédients'),
                  style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                ),
              ),
              if (_estimateNote != null) ...[
                const SizedBox(height: 8),
                Text(
                  _estimateNote!,
                  style: const TextStyle(fontSize: 12.5, color: MaviohColors.textTertiary, fontWeight: FontWeight.w600),
                ),
                if (_unresolved.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                      'Non reconnus : ${_unresolved.join(', ')}. Complète le code-barres ou saisis les valeurs à la main.',
                      style: const TextStyle(fontSize: 12, color: MaviohColors.muted, height: 1.35),
                    ),
                  ),
              ],
              const SizedBox(height: 18),
              _sectionTitle('Ingrédients'),
              for (var index = 0; index < _ingredients.length; index++)
                Padding(
                  key: ValueKey('ingredient-${_ingredients[index].id}'),
                  padding: const EdgeInsets.only(bottom: 12),
                  child: _ingredientCard(index),
                ),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  onPressed: _addIngredient,
                  icon: const Icon(Icons.add_rounded),
                  label: const Text('Ajouter un ingrédient'),
                  style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
                ),
              ),
              if (_errorFor('ingredients') != null)
                Text(
                  _errorFor('ingredients')!,
                  style: const TextStyle(color: MaviohColors.error, fontSize: 12.5),
                ),
              const SizedBox(height: 10),
              _sectionTitle('Photo'),
              _imageCard(),
              const SizedBox(height: 18),
              _sectionTitle('Étiquettes'),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final entry in AppStrings.recipeTagLabels.entries)
                    FilterChip(
                      label: Text(entry.value),
                      selected: _tags.contains(entry.key),
                      onSelected: (selected) => setState(() {
                        if (selected) {
                          _tags.add(entry.key);
                        } else {
                          _tags.remove(entry.key);
                        }
                      }),
                    ),
                ],
              ),
              if (_errorFor('tags') != null)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(_errorFor('tags')!, style: const TextStyle(color: MaviohColors.error, fontSize: 12.5)),
                ),
              const SizedBox(height: 16),
              _sectionTitle('Moments de la journée'),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final type in AppStrings.mealTypeOrder)
                    FilterChip(
                      label: Text(AppStrings.mealType(type)),
                      selected: _mealTypes.contains(type),
                      onSelected: (selected) => setState(() {
                        if (selected) {
                          _mealTypes.add(type);
                        } else {
                          _mealTypes.remove(type);
                        }
                      }),
                    ),
                ],
              ),
              if (_errorFor('meal_types') != null)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(_errorFor('meal_types')!, style: const TextStyle(color: MaviohColors.error, fontSize: 12.5)),
                ),
              const SizedBox(height: 18),
              _publicCard(),
            ],
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: EdgeInsets.fromLTRB(20, 8, 20, 12 + inset),
            child: SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: _saving ? null : _save,
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                child: _saving
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                      )
                    : Text(_isEdit ? 'Enregistrer les modifications' : 'Créer la recette'),
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _sectionTitle(String label, {Widget? trailing}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
            ),
          ),
          ?trailing,
        ],
      ),
    );
  }

  Widget _macroField(String label, TextEditingController controller, String field) {
    return TextField(
      controller: controller,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      onChanged: (_) {
        if (_macrosEstimated) setState(() => _macrosEstimated = false);
      },
      decoration: InputDecoration(labelText: label, suffixText: 'g', errorText: _errorFor(field)),
    );
  }

  Widget _servingsStepper() {
    final error = _errorFor('servings');
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            const Expanded(
              child: Text(
                'Portions',
                style: TextStyle(fontSize: 14.5, fontWeight: FontWeight.w700, color: MaviohColors.text),
              ),
            ),
            IconButton.outlined(
              tooltip: 'Une portion de moins',
              onPressed: _servings <= 1 ? null : () => setState(() => _servings = _servings - 1),
              icon: const Icon(Icons.remove_rounded),
            ),
            SizedBox(
              width: 56,
              child: Text(
                fmtDecimal(_servings, decimals: _servings == _servings.roundToDouble() ? 0 : 1),
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
              ),
            ),
            IconButton.outlined(
              tooltip: 'Une portion de plus',
              onPressed: _servings >= 50 ? null : () => setState(() => _servings = _servings + 1),
              icon: const Icon(Icons.add_rounded),
            ),
          ],
        ),
        const Text(
          'Les calories par portion sont calculées à partir de ce nombre.',
          style: TextStyle(fontSize: 12.5, color: MaviohColors.muted),
        ),
        if (error != null)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(error, style: const TextStyle(color: MaviohColors.error, fontSize: 12.5)),
          ),
      ],
    );
  }

  Widget _ingredientCard(int index) {
    final draft = _ingredients[index];
    final units = _unitOptions;
    final visibleUnits = draft.showAllUnits || units.length <= 4
        ? units
        : <String>[
            ...units.take(4),
            if (!units.take(4).contains(draft.unit) && units.contains(draft.unit)) draft.unit,
          ];

    return AppCard(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
      radius: 20,
      color: MaviohColors.surface,
      shadow: false,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Ingrédient ${index + 1}',
                  style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: MaviohColors.muted),
                ),
              ),
              IconButton(
                tooltip: 'Scanner le code-barres',
                onPressed: () => _scanIngredient(index),
                icon: const Icon(Icons.qr_code_scanner_rounded),
              ),
              IconButton(
                tooltip: 'Retirer cet ingrédient',
                onPressed: () => _removeIngredient(index),
                icon: const Icon(Icons.delete_outline_rounded, color: MaviohColors.error),
              ),
            ],
          ),
          TextField(
            key: ValueKey('ingredient-name-${draft.id}'),
            controller: draft.name,
            textCapitalization: TextCapitalization.sentences,
            decoration: InputDecoration(
              labelText: 'Nom',
              hintText: 'Ex. : courgette',
              errorText: _errorFor('ingredients.$index.name'),
            ),
          ),
          const SizedBox(height: 10),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                flex: 3,
                child: TextField(
                  key: ValueKey('ingredient-ean-${draft.id}'),
                  controller: draft.ean,
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: InputDecoration(
                    labelText: 'Code-barres (EAN)',
                    hintText: 'facultatif',
                    errorText: _errorFor('ingredients.$index.ean'),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                flex: 2,
                child: TextField(
                  key: ValueKey('ingredient-amount-${draft.id}'),
                  controller: draft.amount,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: InputDecoration(
                    labelText: 'Quantité',
                    errorText: _errorFor('ingredients.$index.amount'),
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
                  selected: draft.unit == unit,
                  onSelected: (_) => setState(() => draft.unit = unit),
                ),
              if (!draft.showAllUnits && units.length > 4)
                ActionChip(
                  label: const Text('Plus…'),
                  avatar: const Icon(Icons.expand_more_rounded, size: 16),
                  onPressed: () => setState(() => draft.showAllUnits = true),
                ),
            ],
          ),
        ],
      ),
    );
  }

  List<String> get _unitOptions {
    final portions = Session.instance.portions;
    final source = portions.isEmpty ? Portion.defaults : portions;
    const order = ['g', 'ml', 'piece', 'cas', 'cac', 'tranche', 'portion', 'verre', 'bol', 'poignee', 'assiette'];
    final available = source.map((p) => p.unit).toSet();
    final units = <String>[
      for (final unit in order)
        if (available.contains(unit)) unit,
    ];
    return units.isEmpty ? const ['g'] : units;
  }

  Widget _imageCard() {
    final image = _imageUrl;
    return AppCard(
      padding: const EdgeInsets.all(14),
      radius: 20,
      color: MaviohColors.surface,
      shadow: false,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (_imageError != null)
            StatusBanner.error(_imageError!, margin: const EdgeInsets.only(bottom: 12)),
          Row(
            children: [
              RecipeThumb(imageUrl: image, size: 72),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  image == null
                      ? 'Ajoute une photo : elle est obligatoire pour publier la recette.'
                      : 'Photo prête. Elle sera envoyée avec la recette.',
                  style: const TextStyle(fontSize: 12.5, color: MaviohColors.textTertiary, height: 1.35),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              OutlinedButton.icon(
                onPressed: () => _pickImage(ImageSource.camera),
                icon: const Icon(Icons.photo_camera_outlined, size: 18),
                label: const Text('Photo'),
                style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
              ),
              OutlinedButton.icon(
                onPressed: () => _pickImage(ImageSource.gallery),
                icon: const Icon(Icons.photo_library_outlined, size: 18),
                label: const Text('Galerie'),
                style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
              ),
              if (image != null)
                TextButton.icon(
                  onPressed: _clearImage,
                  icon: const Icon(Icons.delete_outline_rounded, size: 18),
                  label: const Text('Retirer'),
                  style: TextButton.styleFrom(minimumSize: const Size(48, 48), foregroundColor: MaviohColors.error),
                ),
            ],
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _imageLinkController,
            keyboardType: TextInputType.url,
            decoration: InputDecoration(
              labelText: 'ou lien de l’image',
              hintText: 'https://…',
              errorText: _errorFor('image_url'),
              suffixIcon: IconButton(
                tooltip: 'Utiliser ce lien',
                onPressed: _useImageLink,
                icon: const Icon(Icons.check_rounded),
              ),
            ),
            onSubmitted: (_) => _useImageLink(),
          ),
          const SizedBox(height: 6),
          Text(
            'Photo limitée à 350${nbsp}Ko (redimensionnée automatiquement).',
            style: const TextStyle(fontSize: 12, color: MaviohColors.muted),
          ),
        ],
      ),
    );
  }

  Widget _publicCard() {
    final hasIngredient = _collectIngredients().isNotEmpty;
    final hasImage = (_imageUrl ?? '').isNotEmpty;
    final error = _errorFor('is_public');
    return AppCard(
      padding: const EdgeInsets.fromLTRB(14, 6, 10, 10),
      radius: 20,
      color: MaviohColors.surface,
      shadow: false,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            value: _isPublic,
            onChanged: (value) => setState(() => _isPublic = value),
            title: const Text(
              'Recette publique',
              style: TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
            ),
            subtitle: const Text(
              'Une recette publique doit avoir une photo et au moins un ingrédient.',
              style: TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.35),
            ),
          ),
          if (_isPublic && (!hasImage || !hasIngredient))
            StatusBanner.warning(
              !hasImage && !hasIngredient
                  ? 'Il manque la photo et au moins un ingrédient : le serveur refusera la publication.'
                  : !hasImage
                      ? 'Il manque la photo : le serveur refusera la publication.'
                      : 'Il manque au moins un ingrédient : le serveur refusera la publication.',
              margin: const EdgeInsets.only(top: 4),
            ),
          if (error != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(error, style: const TextStyle(color: MaviohColors.error, fontSize: 12.5)),
            ),
        ],
      ),
    );
  }
}

/// One editable ingredient row: its own controllers, kept stable by [id].
class _IngredientDraft {
  _IngredientDraft({String name = '', String ean = '', String amount = '', this.unit = 'g'})
      : id = _nextId++,
        name = TextEditingController(text: name),
        ean = TextEditingController(text: ean),
        amount = TextEditingController(text: amount);

  factory _IngredientDraft.fromIngredient(RecipeIngredient ingredient) {
    final amount = ingredient.amount;
    return _IngredientDraft(
      name: ingredient.name,
      ean: ingredient.ean ?? '',
      amount: amount == null
          ? ''
          : (amount == amount.roundToDouble() ? amount.round().toString() : amount.toString()),
      unit: ingredient.unit ?? 'g',
    );
  }

  static int _nextId = 0;

  final int id;
  final TextEditingController name;
  final TextEditingController ean;
  final TextEditingController amount;
  String unit;
  bool showAllUnits = false;

  /// Null when the row is empty (no name and no barcode).
  RecipeIngredient? toIngredient() {
    final label = name.text.trim();
    final code = ean.text.trim();
    if (label.isEmpty && code.isEmpty) return null;
    return RecipeIngredient(
      name: label.isEmpty ? code : label,
      ean: code.isEmpty ? null : code,
      amount: parseDecimal(amount.text),
      unit: unit,
    );
  }

  void dispose() {
    name.dispose();
    ean.dispose();
    amount.dispose();
  }
}
