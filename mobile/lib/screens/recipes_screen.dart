import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:image_picker/image_picker.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'dart:convert';

class RecipesScreen extends StatefulWidget {
  const RecipesScreen({super.key});

  @override
  State<RecipesScreen> createState() => _RecipesScreenState();
}

class _RecipesScreenState extends State<RecipesScreen> {
  static const _storage = FlutterSecureStorage();
  static const _baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api',
  );

  final Dio _dio = Dio(
    BaseOptions(
      baseUrl: _baseUrl,
      headers: const {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    ),
  );

  final Map<String, _NutritionPer100?> _nutritionCache = {};
  final ImagePicker _imagePicker = ImagePicker();

  List<_RecipeRecord> _recipes = [];
  bool _loading = true;
  bool _submitting = false;
  bool _autoCalculatingCalories = false;
  bool _activeNutritionLoading = false;
  String _error = '';
  String _success = '';
  String _calorieHint = '';
  String _macroHint = '';

  _RecipeFilter _filter = _RecipeFilter.public;
  int? _featuredRecipeId;

  bool _editorSheetOpen = false;
  int? _editingId;
  _RecipeDraft _draft = _RecipeDraft.empty();
  List<bool> _expandedIngredients = [true];

  _ActiveRecipeNutrition? _activeRecipeNutrition;

  @override
  void initState() {
    super.initState();
    _loadRecipes();
  }

  List<_RecipeRecord> get _publicRecipes => _recipes.where((recipe) => recipe.isPublic).toList();

  List<_RecipeRecord> get _myRecipes => _recipes.where((recipe) => recipe.isOwner).toList();

  List<_RecipeRecord> get _visibleRecipes {
    switch (_filter) {
      case _RecipeFilter.mine:
        return _myRecipes;
      case _RecipeFilter.all:
        return _recipes;
      case _RecipeFilter.public:
        return _publicRecipes;
    }
  }

  _RecipeRecord? get _activeRecipe {
    if (_featuredRecipeId != null) {
      for (final recipe in _recipes) {
        if (recipe.id == _featuredRecipeId) return recipe;
      }
    }

    if (_publicRecipes.isNotEmpty) return _publicRecipes.first;
    if (_visibleRecipes.isNotEmpty) return _visibleRecipes.first;
    return null;
  }

  String _extractMessage(dynamic data, String fallback) {
    if (data is Map) {
      final message = data['message'];
      if (message != null && message.toString().trim().isNotEmpty) {
        return message.toString();
      }

      final errors = data['errors'];
      if (errors is Map) {
        final parts = <String>[];
        for (final value in errors.values) {
          if (value is Iterable) {
            parts.addAll(value.map((item) => item.toString()));
          } else if (value != null) {
            parts.add(value.toString());
          }
        }
        if (parts.isNotEmpty) return parts.join(' ');
      }
    }

    return fallback;
  }

  Future<String?> _getToken() {
    return _storage.read(key: 'token');
  }

  Future<void> _loadRecipes() async {
    final token = await _getToken();

    if (token == null) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = 'Session invalide. Reconnecte-toi.';
      });
      return;
    }

    setState(() {
      _loading = true;
      _error = '';
    });

    try {
      final response = await _dio.get(
        '/recipes',
        options: Options(headers: {'Authorization': 'Bearer $token'}),
      );

      final payload = Map<String, dynamic>.from(response.data as Map);
      final data = payload['data'];
      final recipes = (data is List)
          ? data
              .map((item) => _RecipeRecord.fromJson(Map<String, dynamic>.from(item as Map)))
              .toList()
          : <_RecipeRecord>[];

      recipes.sort((a, b) {
        final left = DateTime.tryParse(a.updatedAt ?? '') ?? DateTime.fromMillisecondsSinceEpoch(0);
        final right = DateTime.tryParse(b.updatedAt ?? '') ?? DateTime.fromMillisecondsSinceEpoch(0);
        return right.compareTo(left);
      });

      if (!mounted) return;
      setState(() {
        _recipes = recipes;
        _error = '';
      });

      await _computeActiveRecipeNutrition();
    } on DioException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = _extractMessage(e.response?.data, 'Impossible de charger les recettes.');
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = 'Impossible de charger les recettes.';
      });
    } finally {
      if (mounted) {
        setState(() {
          _loading = false;
        });
      }
    }
  }

  Future<_NutritionPer100?> _fetchNutritionFromOpenFoodFacts(String barcode) async {
    try {
      final response = await _dio.get(
        'https://world.openfoodfacts.org/api/v2/product/$barcode.json',
        options: Options(
          sendTimeout: const Duration(milliseconds: 4500),
          receiveTimeout: const Duration(milliseconds: 4500),
        ),
      );

      final payload = Map<String, dynamic>.from(response.data as Map);
      if (payload['status'] != 1 || payload['product'] == null || payload['product'] is! Map) {
        return null;
      }

      final product = Map<String, dynamic>.from(payload['product'] as Map);
      final nutriments = product['nutriments'] is Map
          ? Map<String, dynamic>.from(product['nutriments'] as Map)
          : <String, dynamic>{};

      num? read(dynamic value) {
        if (value == null) return null;
        return num.tryParse(value.toString());
      }

      final calories = read(nutriments['energy_kcal_100g'] ?? nutriments['energy_100g']);
      final fat = read(nutriments['fat_100g']);
      final carbs = read(nutriments['carbohydrates_100g']);
      final proteins = read(nutriments['proteins_100g']);

      if (calories == null && fat == null && carbs == null && proteins == null) return null;

      return _NutritionPer100(
        calories: calories,
        fat: fat,
        carbs: carbs,
        proteins: proteins,
      );
    } catch (_) {
      return null;
    }
  }

  String? _extractBarcode(String input) {
    final regex = RegExp(r'\d{8,14}');
    final match = regex.firstMatch(input);
    return match?.group(0);
  }

  List<String> _barcodeCandidates(String barcode) {
    final clean = barcode.trim();
    if (!RegExp(r'^\d{8,14}$').hasMatch(clean)) return [clean];

    final variants = <String>{clean};
    if (clean.length == 12) {
      variants.add('0$clean');
    }
    if (clean.length == 13 && clean.startsWith('0')) {
      variants.add(clean.substring(1));
    }

    return variants.toList();
  }

  Future<_NutritionPer100?> _resolveNutritionPer100(String rawNameOrEan) async {
    final input = rawNameOrEan.trim();
    if (input.isEmpty) return null;

    final barcode = _extractBarcode(input);
    final token = await _getToken();

    if (barcode != null) {
      final cacheKey = 'ean:$barcode';
      if (_nutritionCache.containsKey(cacheKey)) {
        return _nutritionCache[cacheKey];
      }

      _NutritionPer100? result;
      try {
        final response = await _dio.get(
          '/foods/barcode/${Uri.encodeComponent(barcode)}',
          options: Options(
            headers: {
              if (token != null) 'Authorization': 'Bearer $token',
            },
          ),
        );

        final payload = Map<String, dynamic>.from(response.data as Map);
        if (payload['data'] is Map) {
          final data = Map<String, dynamic>.from(payload['data'] as Map);
          result = _NutritionPer100(
            calories: _toNum(data['calories']),
            fat: _toNum(data['fat']),
            carbs: _toNum(data['carbs']),
            proteins: _toNum(data['proteins']),
          );
          if (result.isEmpty) result = null;
        }
      } catch (_) {
        result = null;
      }

      result ??= await _fetchNutritionFromOpenFoodFacts(barcode);
      _nutritionCache[cacheKey] = result;
      return result;
    }

    final cacheKey = 'name:${input.toLowerCase()}';
    if (_nutritionCache.containsKey(cacheKey)) {
      return _nutritionCache[cacheKey];
    }

    _NutritionPer100? result;
    try {
      final response = await _dio.get(
        '/foods/search',
        queryParameters: {'q': input},
        options: Options(
          headers: {
            if (token != null) 'Authorization': 'Bearer $token',
          },
        ),
      );

      final payload = Map<String, dynamic>.from(response.data as Map);
      if (payload['data'] is List) {
        final items = (payload['data'] as List).whereType<Map>().toList();
        for (final item in items) {
          final normalized = _NutritionPer100(
            calories: _toNum(item['calories']),
            fat: _toNum(item['fat']),
            carbs: _toNum(item['carbs']),
            proteins: _toNum(item['proteins']),
          );

          if (!normalized.isEmpty) {
            result = normalized;
            break;
          }
        }
      }
    } catch (_) {
      result = null;
    }

    _nutritionCache[cacheKey] = result;
    return result;
  }

  num? _toNum(dynamic value) {
    if (value == null) return null;
    return num.tryParse(value.toString());
  }

  double _computeServingFactor(String amountValue, String unitValue) {
    final amount = double.tryParse(amountValue.trim());
    if (amount == null || amount <= 0) return 1;

    final unit = unitValue.trim().toLowerCase();

    if (unit == 'kg' || unit.contains('kilo')) return amount * 10;
    if (unit == 'g' || unit.contains('gram')) return amount / 100;
    if (unit == 'ml') return amount / 100;
    if (unit == 'cl') return amount / 10;
    if (unit == 'l') return amount * 10;

    return amount / 100;
  }

  Future<void> _computeActiveRecipeNutrition() async {
    final recipe = _activeRecipe;
    if (recipe == null) {
      if (!mounted) return;
      setState(() {
        _activeRecipeNutrition = null;
      });
      return;
    }

    final ingredients = recipe.ingredients
        .where((ingredient) => (ingredient.ean ?? ingredient.name).trim().isNotEmpty)
        .toList();

    if (ingredients.isEmpty) {
      if (!mounted) return;
      setState(() {
        _activeRecipeNutrition = const _ActiveRecipeNutrition(
          fat: 0,
          carbs: 0,
          proteins: 0,
          resolvedCount: 0,
          totalCount: 0,
        );
      });
      return;
    }

    if (mounted) {
      setState(() {
        _activeNutritionLoading = true;
      });
    }

    var totalFat = 0.0;
    var totalCarbs = 0.0;
    var totalProteins = 0.0;
    var resolvedCount = 0;

    for (final ingredient in ingredients) {
      final identifier = (ingredient.ean ?? ingredient.name).trim();
      final nutrition = await _resolveNutritionPer100(identifier);
      if (nutrition == null) continue;

      final factor = _computeServingFactor(
        ingredient.amount?.toString() ?? '',
        ingredient.unit ?? '',
      );

      final hasAnyMacro = nutrition.fat != null || nutrition.carbs != null || nutrition.proteins != null;
      if (!hasAnyMacro) continue;

      totalFat += (nutrition.fat ?? 0) * factor;
      totalCarbs += (nutrition.carbs ?? 0) * factor;
      totalProteins += (nutrition.proteins ?? 0) * factor;
      resolvedCount += 1;
    }

    if (!mounted) return;
    setState(() {
      _activeRecipeNutrition = _ActiveRecipeNutrition(
        fat: double.parse(totalFat.toStringAsFixed(1)),
        carbs: double.parse(totalCarbs.toStringAsFixed(1)),
        proteins: double.parse(totalProteins.toStringAsFixed(1)),
        resolvedCount: resolvedCount,
        totalCount: ingredients.length,
      );
      _activeNutritionLoading = false;
    });
  }

  Future<void> _openCreateEditor() async {
    setState(() {
      _editingId = null;
      _draft = _RecipeDraft.empty();
      _expandedIngredients = [true];
      _error = '';
      _success = '';
      _calorieHint = '';
      _macroHint = '';
    });

    await _openEditorSheet();
  }

  Future<void> _openEditEditor(_RecipeRecord recipe) async {
    final draft = _RecipeDraft.fromRecipe(recipe);
    setState(() {
      _editingId = recipe.id;
      _featuredRecipeId = recipe.id;
      _draft = draft;
      _expandedIngredients = _buildIngredientExpansionState(draft.ingredients.length);
      _error = '';
      _success = '';
      _calorieHint = '';
      _macroHint = '';
    });

    _recalculateDraftCalories();
    await _openEditorSheet();
  }

  void _closeEditor() {
    setState(() {
      _editingId = null;
      _draft = _RecipeDraft.empty();
      _expandedIngredients = [true];
      _calorieHint = '';
      _macroHint = '';
      _error = '';
    });
  }

  Future<void> _openEditorSheet() async {
    if (_editorSheetOpen) return;
    _editorSheetOpen = true;

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (sheetContext) {
        final height = (MediaQuery.sizeOf(sheetContext).height * 0.92).clamp(520.0, 940.0);

        return Padding(
          padding: const EdgeInsets.fromLTRB(10, 10, 10, 16),
          child: SizedBox(
            height: height,
            child: StatefulBuilder(
              builder: (context, sheetSetState) {
                return SingleChildScrollView(
                  child: Column(
                    children: [
                      _RecipeEditorCard(
                        draft: _draft,
                        editing: _editingId != null,
                        submitting: _submitting,
                        autoCalculatingCalories: _autoCalculatingCalories,
                        calorieHint: _calorieHint,
                        macroHint: _macroHint,
                        expandedIngredients: _expandedIngredients,
                        onClose: () => Navigator.of(sheetContext).pop(),
                        onSubmit: () async {
                          await _submitRecipe();
                          if (mounted) {
                            sheetSetState(() {});
                          }
                        },
                        onDraftChanged: (next) {
                          setState(() {
                            _draft = next;
                          });
                          sheetSetState(() {});
                        },
                        onExpandAll: () {
                          setState(() {
                            _expandedIngredients = List<bool>.filled(_draft.ingredients.length, true);
                          });
                          sheetSetState(() {});
                        },
                        onCollapseAll: () {
                          setState(() {
                            _expandedIngredients = List<bool>.generate(_draft.ingredients.length, (index) => index == 0);
                          });
                          sheetSetState(() {});
                        },
                        onIngredientToggle: (index) {
                          setState(() {
                            _expandedIngredients[index] = !_expandedIngredients[index];
                          });
                          sheetSetState(() {});
                        },
                        onIngredientChanged: (index, value) {
                          _updateIngredient(index, value);
                          sheetSetState(() {});
                        },
                        onIngredientRemoved: (index) {
                          _removeIngredient(index);
                          sheetSetState(() {});
                        },
                        onIngredientAdded: () {
                          _addIngredient();
                          sheetSetState(() {});
                        },
                        onLookupEan: (index) async {
                          await _lookupIngredientByEan(index);
                          if (mounted) {
                            sheetSetState(() {});
                          }
                        },
                        onScanEan: (index) async {
                          await _scanIngredientEan(index);
                          if (mounted) {
                            sheetSetState(() {});
                          }
                        },
                        ingredientPreview: _ingredientPreview,
                        onPickCameraImage: () {
                          _pickRecipeImage(ImageSource.camera).whenComplete(() {
                            if (mounted) {
                              sheetSetState(() {});
                            }
                          });
                        },
                        onPickGalleryImage: () {
                          _pickRecipeImage(ImageSource.gallery).whenComplete(() {
                            if (mounted) {
                              sheetSetState(() {});
                            }
                          });
                        },
                        onClearImage: () {
                          _clearRecipeImage();
                          sheetSetState(() {});
                        },
                      ),
                      if (_error.isNotEmpty) ...[
                        const SizedBox(height: 10),
                        _FeedbackBox(message: _error, isError: true),
                      ],
                      if (_success.isNotEmpty) ...[
                        const SizedBox(height: 10),
                        _FeedbackBox(message: _success, isError: false),
                      ],
                    ],
                  ),
                );
              },
            ),
          ),
        );
      },
    );

    _editorSheetOpen = false;
    if (mounted) {
      _closeEditor();
    }
  }

  List<bool> _buildIngredientExpansionState(int length) {
    if (length <= 4) return List<bool>.filled(length, true);
    return List<bool>.generate(length, (index) => index < 2);
  }

  void _addIngredient() {
    setState(() {
      _draft = _draft.copyWith(
        ingredients: [..._draft.ingredients, _RecipeIngredientDraft.empty()],
      );
      _expandedIngredients = [..._expandedIngredients, true];
    });
  }

  void _removeIngredient(int index) {
    final nextIngredients = [..._draft.ingredients]..removeAt(index);
    final normalizedIngredients = nextIngredients.isEmpty ? [_RecipeIngredientDraft.empty()] : nextIngredients;

    final nextExpanded = [..._expandedIngredients]..removeAt(index);
    final normalizedExpanded = nextExpanded.isEmpty ? [true] : nextExpanded;

    setState(() {
      _draft = _draft.copyWith(ingredients: normalizedIngredients);
      _expandedIngredients = normalizedExpanded;
    });

    _recalculateDraftCalories();
  }

  void _updateIngredient(int index, _RecipeIngredientDraft value) {
    final next = [..._draft.ingredients];
    next[index] = value;
    setState(() {
      _draft = _draft.copyWith(ingredients: next);
    });

    _recalculateDraftCalories();
  }

  String _ingredientPreview(_RecipeIngredientDraft ingredient) {
    final name = ingredient.name.trim().isEmpty ? 'Aliment sans nom' : ingredient.name.trim();
    final amount = ingredient.amount.trim();
    final unit = ingredient.unit.trim();
    if (amount.isEmpty && unit.isEmpty) return name;
    return '$name · ${amount.isEmpty ? '?' : amount}${unit.isEmpty ? '' : ' $unit'}';
  }

  Future<void> _lookupIngredientByEan(int index) async {
    final ingredient = _draft.ingredients[index];
    final input = ingredient.name.trim();
    final barcode = (ingredient.ean.trim().isNotEmpty)
        ? ingredient.ean.trim()
        : (_extractBarcode(input) ?? input);

    if (!RegExp(r'^\d{8,14}$').hasMatch(barcode)) {
      setState(() {
        _error = 'Saisis un EAN valide dans le champ nom.';
      });
      return;
    }

    final token = await _getToken();
    String? foundName;

    for (final code in _barcodeCandidates(barcode)) {
      try {
        final response = await _dio.get(
          '/foods/barcode/${Uri.encodeComponent(code)}',
          options: Options(
            headers: {
              if (token != null) 'Authorization': 'Bearer $token',
            },
          ),
        );

        final payload = Map<String, dynamic>.from(response.data as Map);
        if (payload['data'] is Map) {
          final data = Map<String, dynamic>.from(payload['data'] as Map);
          final name = data['name']?.toString().trim();
          if (name != null && name.isNotEmpty) {
            foundName = name;
            break;
          }
        }
      } catch (_) {
        foundName = null;
      }
    }

    if (foundName == null) {
      for (final code in _barcodeCandidates(barcode)) {
        foundName = await _fetchNameFromOpenFoodFacts(code);
        if (foundName != null && foundName.trim().isNotEmpty) {
          break;
        }
      }
    }

    if (foundName == null) {
      if (!mounted) return;
      setState(() {
        _error = 'Produit introuvable pour cet EAN.';
      });
      return;
    }

    final updated = _draft.ingredients[index].copyWith(name: foundName, ean: barcode);
    _updateIngredient(index, updated);

    if (!mounted) return;
    setState(() {
      _error = '';
      _success = 'Aliment chargé depuis l EAN ${updated.name}.';
    });
  }

  Future<void> _scanIngredientEan(int index) async {
    final scannedCode = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.black,
      builder: (context) {
        final sheetHeight = (MediaQuery.sizeOf(context).height * 0.78).clamp(420.0, 680.0);
        return SizedBox(
          height: sheetHeight,
          child: const _EanScannerSheet(),
        );
      },
    );

    if (!mounted || scannedCode == null) {
      return;
    }

    final match = RegExp(r'\d{8,14}').firstMatch(scannedCode.trim());
    final cleaned = match?.group(0) ?? '';

    if (!RegExp(r'^\d{8,14}$').hasMatch(cleaned)) {
      setState(() {
        _error = 'Code scanné invalide. Réessaie avec le code-barres complet.';
      });
      return;
    }

    final updated = _draft.ingredients[index].copyWith(name: cleaned, ean: cleaned);
    _updateIngredient(index, updated);

    setState(() {
      _error = '';
      _success = 'EAN scanné: $cleaned. Chargement du produit...';
    });

    await _lookupIngredientByEan(index);
  }

  String _guessMimeType(String path) {
    final lower = path.toLowerCase();
    if (lower.endsWith('.png')) return 'image/png';
    if (lower.endsWith('.webp')) return 'image/webp';
    if (lower.endsWith('.gif')) return 'image/gif';
    return 'image/jpeg';
  }

  Future<void> _pickRecipeImage(ImageSource source) async {
    try {
      final picked = await _imagePicker.pickImage(
        source: source,
        imageQuality: 85,
        maxWidth: 1600,
      );

      if (picked == null) {
        return;
      }

      final bytes = await picked.readAsBytes();
      if (bytes.lengthInBytes > 5 * 1024 * 1024) {
        if (!mounted) return;
        setState(() {
          _error = 'L image doit faire moins de 5 Mo.';
        });
        return;
      }

      final mimeType = _guessMimeType(picked.path);
      final dataUrl = 'data:$mimeType;base64,${base64Encode(bytes)}';

      if (!mounted) return;
      setState(() {
        _draft = _draft.copyWith(imageUrl: dataUrl);
        _error = '';
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = source == ImageSource.camera
            ? 'Impossible d ouvrir la caméra.'
            : 'Impossible d ouvrir la galerie.';
      });
    }
  }

  void _clearRecipeImage() {
    setState(() {
      _draft = _draft.copyWith(imageUrl: '');
    });
  }

  Future<String?> _fetchNameFromOpenFoodFacts(String barcode) async {
    try {
      final response = await _dio.get(
        'https://world.openfoodfacts.org/api/v2/product/$barcode.json',
        options: Options(
          sendTimeout: const Duration(milliseconds: 4500),
          receiveTimeout: const Duration(milliseconds: 4500),
        ),
      );

      final payload = Map<String, dynamic>.from(response.data as Map);
      if (payload['status'] != 1 || payload['product'] == null || payload['product'] is! Map) {
        return null;
      }

      final product = Map<String, dynamic>.from(payload['product'] as Map);
      final candidates = [
        product['product_name_fr'],
        product['product_name_en'],
        product['product_name'],
        product['generic_name_fr'],
        product['generic_name_en'],
        product['generic_name'],
        product['abbreviated_product_name'],
      ]
          .map((value) => value?.toString().trim() ?? '')
          .where((value) => value.isNotEmpty)
          .toList();

      if (candidates.isEmpty) return null;
      return candidates.first;
    } catch (_) {
      return null;
    }
  }

  Future<void> _recalculateDraftCalories() async {
    final ingredients = _draft.ingredients.where((item) => item.name.trim().isNotEmpty).toList();

    if (ingredients.isEmpty) {
      if (!mounted) return;
      setState(() {
        _draft = _draft.copyWith(calories: '0');
        _calorieHint = '';
        _macroHint = '';
      });
      return;
    }

    if (mounted) {
      setState(() {
        _autoCalculatingCalories = true;
      });
    }

    var totalCalories = 0.0;
    var totalFat = 0.0;
    var totalCarbs = 0.0;
    var totalProteins = 0.0;
    var resolvedCount = 0;

    for (final ingredient in ingredients) {
      final identifier = ingredient.ean.trim().isNotEmpty ? ingredient.ean : ingredient.name;
      final nutrition = await _resolveNutritionPer100(identifier);
      final caloriesPer100 = nutrition?.calories;
      final hasAnyMacro = caloriesPer100 != null ||
          nutrition?.fat != null ||
          nutrition?.carbs != null ||
          nutrition?.proteins != null;
      if (!hasAnyMacro) continue;

      final factor = _computeServingFactor(ingredient.amount, ingredient.unit);
      totalCalories += (caloriesPer100 ?? 0) * factor;
      totalFat += (nutrition?.fat ?? 0) * factor;
      totalCarbs += (nutrition?.carbs ?? 0) * factor;
      totalProteins += (nutrition?.proteins ?? 0) * factor;
      resolvedCount += 1;
    }

    if (!mounted) return;

    final rounded = totalCalories.round().clamp(0, 1000000);

    setState(() {
      _draft = _draft.copyWith(calories: rounded.toString());
      _calorieHint = 'Calories auto: $resolvedCount/${ingredients.length} aliments pris en compte.';
      _macroHint =
          'Lipides: ${totalFat.toStringAsFixed(1)} g · Glucides: ${totalCarbs.toStringAsFixed(1)} g · Protéines: ${totalProteins.toStringAsFixed(1)} g';
      _autoCalculatingCalories = false;
    });
  }

  String _validateDraft() {
    if (_draft.title.trim().isEmpty) return 'Le titre est requis.';

    final calories = num.tryParse(_draft.calories.trim());
    if (calories == null) return 'Les calories sont requises.';

    if (_draft.isPublic) {
      if (_draft.imageUrl.trim().isEmpty) {
        return 'Une image est requise pour publier une recette publique.';
      }
      if (num.tryParse(_draft.prepTimeMinutes.trim()) == null) {
        return 'Le temps de préparation est requis pour une recette publique.';
      }
      final hasIngredient = _draft.ingredients.any((item) => item.name.trim().isNotEmpty);
      if (!hasIngredient) {
        return 'Une recette publique doit contenir au moins un aliment.';
      }
    }

    return '';
  }

  Future<void> _submitRecipe() async {
    final validation = _validateDraft();
    if (validation.isNotEmpty) {
      setState(() {
        _error = validation;
        _success = '';
      });
      return;
    }

    final token = await _getToken();
    if (token == null) {
      setState(() {
        _error = 'Session invalide. Reconnecte-toi.';
      });
      return;
    }

    setState(() {
      _submitting = true;
      _error = '';
      _success = '';
    });

    final payload = _draft.toPayload();

    try {
      final response = await _dio.request(
        _editingId == null ? '/recipes' : '/recipes/$_editingId',
        data: payload,
        options: Options(
          method: _editingId == null ? 'POST' : 'PUT',
          headers: {'Authorization': 'Bearer $token'},
        ),
      );

      final result = Map<String, dynamic>.from(response.data as Map);
      final data = result['data'];
      if (data is! Map) {
        throw Exception('Réponse inattendue du serveur.');
      }

      final saved = _RecipeRecord.fromJson(Map<String, dynamic>.from(data));
      final next = _recipes.where((recipe) => recipe.id != saved.id).toList();
      next.insert(0, saved);

      next.sort((a, b) {
        final left = DateTime.tryParse(a.updatedAt ?? '') ?? DateTime.fromMillisecondsSinceEpoch(0);
        final right = DateTime.tryParse(b.updatedAt ?? '') ?? DateTime.fromMillisecondsSinceEpoch(0);
        return right.compareTo(left);
      });

      if (!mounted) return;
      setState(() {
        _recipes = next;
        _featuredRecipeId = saved.id;
        _success = saved.isPublic ? 'Recette publiée.' : 'Recette enregistrée en privé.';
        _submitting = false;
        _calorieHint = '';
        _macroHint = '';
      });

      if (_editorSheetOpen && mounted) {
        Navigator.of(context).pop();
      } else {
        _closeEditor();
      }

      await _computeActiveRecipeNutrition();
    } on DioException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = _extractMessage(e.response?.data, 'Impossible d enregistrer la recette.');
        _submitting = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString();
        _submitting = false;
      });
    }
  }

  Future<void> _deleteRecipe(_RecipeRecord recipe) async {
    final confirmed = await showDialog<bool>(
          context: context,
          builder: (context) => AlertDialog(
            title: const Text('Supprimer la recette'),
            content: const Text('Confirmer la suppression ?'),
            actions: [
              TextButton(
                onPressed: () => Navigator.of(context).pop(false),
                child: const Text('Annuler'),
              ),
              FilledButton(
                onPressed: () => Navigator.of(context).pop(true),
                child: const Text('Supprimer'),
              ),
            ],
          ),
        ) ??
        false;

    if (!confirmed) return;

    final token = await _getToken();
    if (token == null) {
      setState(() {
        _error = 'Session invalide. Reconnecte-toi.';
      });
      return;
    }

    try {
      await _dio.delete(
        '/recipes/${recipe.id}',
        options: Options(headers: {'Authorization': 'Bearer $token'}),
      );

      final next = _recipes.where((item) => item.id != recipe.id).toList();
      if (!mounted) return;
      setState(() {
        _recipes = next;
        if (_featuredRecipeId == recipe.id) {
          _featuredRecipeId = next.isNotEmpty ? next.first.id : null;
        }
        _success = 'Recette supprimée.';
        _error = '';
      });

      await _computeActiveRecipeNutrition();
    } on DioException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = _extractMessage(e.response?.data, 'Impossible de supprimer la recette.');
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = 'Impossible de supprimer la recette.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final active = _activeRecipe;
    final bottomInset = MediaQuery.viewPaddingOf(context).bottom;

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    return Stack(
      children: [
        ListView(
          padding: const EdgeInsets.fromLTRB(18, 14, 18, 106),
          children: [
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                ChoiceChip(
                  label: const Text('Publiques'),
                  selected: _filter == _RecipeFilter.public,
                  onSelected: (_) => setState(() => _filter = _RecipeFilter.public),
                ),
                ChoiceChip(
                  label: const Text('Mes recettes'),
                  selected: _filter == _RecipeFilter.mine,
                  onSelected: (_) => setState(() => _filter = _RecipeFilter.mine),
                ),
                ChoiceChip(
                  label: const Text('Toutes'),
                  selected: _filter == _RecipeFilter.all,
                  onSelected: (_) => setState(() => _filter = _RecipeFilter.all),
                ),
              ],
            ),
            const SizedBox(height: 14),
            Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(28),
            border: Border.all(color: const Color(0xFFE2E8F0)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Fil d\'actualités',
                          style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: Color(0xFF0F172A)),
                        ),
                        SizedBox(height: 2),
                        Text(
                          'Recettes publiques triées par mise à jour récente.',
                          style: TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                        ),
                      ],
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(999),
                      border: Border.all(color: const Color(0xFFE2E8F0)),
                      color: const Color(0xFFF8FAFC),
                    ),
                    child: Text('${_visibleRecipes.length} résultat${_visibleRecipes.length > 1 ? 's' : ''}'),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              if (active != null) ...[
                _ActiveRecipeCard(
                  recipe: active,
                  nutrition: _activeRecipeNutrition,
                  loading: _activeNutritionLoading,
                ),
                const SizedBox(height: 12),
              ],
              if (_visibleRecipes.isEmpty)
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(16),
                    border: Border.all(color: const Color(0xFFE2E8F0)),
                    color: const Color(0xFFF8FAFC),
                  ),
                  child: const Text('Aucune recette pour ce filtre.'),
                ),
              ..._visibleRecipes.map(
                (recipe) => Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: _RecipeListTile(
                    recipe: recipe,
                    selected: recipe.id == active?.id,
                    onTap: () async {
                      setState(() {
                        _featuredRecipeId = recipe.id;
                      });
                      await _computeActiveRecipeNutrition();
                    },
                    onEdit: recipe.isOwner ? () => _openEditEditor(recipe) : null,
                    onDelete: recipe.isOwner ? () => _deleteRecipe(recipe) : null,
                  ),
                ),
              ),
            ],
          ),
        ),
            if (_error.isNotEmpty) ...[
              const SizedBox(height: 12),
              _FeedbackBox(message: _error, isError: true),
            ],
            if (_success.isNotEmpty) ...[
              const SizedBox(height: 10),
              _FeedbackBox(message: _success, isError: false),
            ],
          ],
        ),
        Positioned(
          right: 16,
          bottom: bottomInset + 18,
          child: FloatingActionButton(
            onPressed: _openCreateEditor,
            tooltip: 'Créer une recette',
            child: const Icon(Icons.add),
          ),
        ),
      ],
    );
  }
}

enum _RecipeFilter { public, mine, all }

class _FeedbackBox extends StatelessWidget {
  final String message;
  final bool isError;

  const _FeedbackBox({required this.message, required this.isError});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: isError ? const Color(0xFFFFF1F2) : const Color(0xFFECFDF5),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: isError ? const Color(0xFFFDA4AF) : const Color(0xFFA7F3D0),
        ),
      ),
      child: Text(
        message,
        style: TextStyle(
          color: isError ? const Color(0xFFBE123C) : const Color(0xFF047857),
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}

class _RecipeEditorCard extends StatelessWidget {
  final _RecipeDraft draft;
  final bool editing;
  final bool submitting;
  final bool autoCalculatingCalories;
  final String calorieHint;
  final String macroHint;
  final List<bool> expandedIngredients;
  final VoidCallback onClose;
  final VoidCallback onSubmit;
  final ValueChanged<_RecipeDraft> onDraftChanged;
  final VoidCallback onExpandAll;
  final VoidCallback onCollapseAll;
  final ValueChanged<int> onIngredientToggle;
  final void Function(int index, _RecipeIngredientDraft value) onIngredientChanged;
  final ValueChanged<int> onIngredientRemoved;
  final VoidCallback onIngredientAdded;
  final Future<void> Function(int index) onLookupEan;
  final Future<void> Function(int index) onScanEan;
  final String Function(_RecipeIngredientDraft ingredient) ingredientPreview;
  final VoidCallback onPickCameraImage;
  final VoidCallback onPickGalleryImage;
  final VoidCallback onClearImage;

  const _RecipeEditorCard({
    required this.draft,
    required this.editing,
    required this.submitting,
    required this.autoCalculatingCalories,
    required this.calorieHint,
    required this.macroHint,
    required this.expandedIngredients,
    required this.onClose,
    required this.onSubmit,
    required this.onDraftChanged,
    required this.onExpandAll,
    required this.onCollapseAll,
    required this.onIngredientToggle,
    required this.onIngredientChanged,
    required this.onIngredientRemoved,
    required this.onIngredientAdded,
    required this.onLookupEan,
    required this.onScanEan,
    required this.ingredientPreview,
    required this.onPickCameraImage,
    required this.onPickGalleryImage,
    required this.onClearImage,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(28),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  editing ? 'Modifier la recette' : 'Créer une recette',
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                ),
              ),
              IconButton(
                onPressed: onClose,
                icon: const Icon(Icons.close),
              ),
            ],
          ),
          const SizedBox(height: 10),
          TextField(
            decoration: const InputDecoration(labelText: 'Nom de la recette', border: OutlineInputBorder()),
            controller: TextEditingController(text: draft.title)
              ..selection = TextSelection.collapsed(offset: draft.title.length),
            onChanged: (value) => onDraftChanged(draft.copyWith(title: value)),
          ),
          const SizedBox(height: 10),
          TextField(
            decoration: const InputDecoration(labelText: 'Description', border: OutlineInputBorder()),
            minLines: 3,
            maxLines: 4,
            controller: TextEditingController(text: draft.description)
              ..selection = TextSelection.collapsed(offset: draft.description.length),
            onChanged: (value) => onDraftChanged(draft.copyWith(description: value)),
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: TextField(
                  decoration: const InputDecoration(labelText: 'Calories (auto)', border: OutlineInputBorder()),
                  readOnly: true,
                  controller: TextEditingController(text: draft.calories)
                    ..selection = TextSelection.collapsed(offset: draft.calories.length),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: TextField(
                  decoration: const InputDecoration(labelText: 'Temps de préparation (min)', border: OutlineInputBorder()),
                  keyboardType: const TextInputType.numberWithOptions(decimal: false),
                  controller: TextEditingController(text: draft.prepTimeMinutes)
                    ..selection = TextSelection.collapsed(offset: draft.prepTimeMinutes.length),
                  onChanged: (value) => onDraftChanged(draft.copyWith(prepTimeMinutes: value)),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          if (autoCalculatingCalories)
            const Text('Calcul des calories en cours...', style: TextStyle(fontSize: 12, color: Color(0xFF64748B)))
          else if (calorieHint.isNotEmpty)
            Text(calorieHint, style: const TextStyle(fontSize: 12, color: Color(0xFF64748B))),
          if (!autoCalculatingCalories && macroHint.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(macroHint, style: const TextStyle(fontSize: 12, color: Color(0xFF64748B))),
          ],
          const SizedBox(height: 10),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: const Color(0xFFF8FAFC),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: const Color(0xFFE2E8F0)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Photo de la recette',
                  style: TextStyle(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 8),
                _RecipeImagePreview(imageUrl: draft.imageUrl),
                const SizedBox(height: 10),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    OutlinedButton.icon(
                      onPressed: onPickCameraImage,
                      icon: const Icon(Icons.photo_camera_outlined),
                      label: const Text('Prendre une photo'),
                    ),
                    OutlinedButton.icon(
                      onPressed: onPickGalleryImage,
                      icon: const Icon(Icons.photo_library_outlined),
                      label: const Text('Choisir depuis la galerie'),
                    ),
                    if (draft.imageUrl.trim().isNotEmpty)
                      OutlinedButton.icon(
                        onPressed: onClearImage,
                        icon: const Icon(Icons.delete_outline),
                        label: const Text('Retirer l image'),
                      ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 10),
          if (draft.imageUrl.trim().startsWith('data:image'))
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: const Color(0xFFF8FAFC),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFE2E8F0)),
              ),
              child: const Text(
                'Image importée depuis l appareil (base64). Elle sera envoyée telle quelle.',
                style: TextStyle(fontSize: 12, color: Color(0xFF475569)),
              ),
            )
          else
            TextField(
              decoration: const InputDecoration(
                labelText: 'Image URL (optionnel)',
                border: OutlineInputBorder(),
              ),
              controller: TextEditingController(text: draft.imageUrl)
                ..selection = TextSelection.collapsed(offset: draft.imageUrl.length),
              onChanged: (value) => onDraftChanged(draft.copyWith(imageUrl: value)),
            ),
          const SizedBox(height: 10),
          SwitchListTile(
            value: draft.isPublic,
            onChanged: (value) => onDraftChanged(draft.copyWith(isPublic: value)),
            title: const Text('Publier cette recette au public'),
            contentPadding: EdgeInsets.zero,
          ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 6,
            runSpacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              const Padding(
                padding: EdgeInsets.only(right: 4),
                child: Text(
                  'Aliments de la recette',
                  style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
                ),
              ),
              TextButton(onPressed: onExpandAll, child: const Text('Tout dérouler')),
              TextButton(onPressed: onCollapseAll, child: const Text('Réduire')),
            ],
          ),
          const SizedBox(height: 6),
          ...List.generate(draft.ingredients.length, (index) {
            final ingredient = draft.ingredients[index];
            final expanded = expandedIngredients[index];

            return Container(
              margin: const EdgeInsets.only(bottom: 10),
              decoration: BoxDecoration(
                color: const Color(0xFFF8FAFC),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: const Color(0xFFE2E8F0)),
              ),
              child: Column(
                children: [
                  InkWell(
                    borderRadius: BorderRadius.circular(14),
                    onTap: () => onIngredientToggle(index),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                      child: Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  'Aliment ${index + 1}',
                                  style: const TextStyle(
                                    fontSize: 11,
                                    fontWeight: FontWeight.w700,
                                    letterSpacing: 0.8,
                                    color: Color(0xFF64748B),
                                  ),
                                ),
                                const SizedBox(height: 2),
                                Text(
                                  ingredientPreview(ingredient),
                                  style: const TextStyle(fontWeight: FontWeight.w600),
                                ),
                              ],
                            ),
                          ),
                          Text(
                            expanded ? 'Replier' : 'Dérouler',
                            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Color(0xFF475569)),
                          ),
                        ],
                      ),
                    ),
                  ),
                  if (expanded)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                      child: Column(
                        children: [
                          TextField(
                            decoration: const InputDecoration(
                              labelText: 'Nom de l aliment',
                              hintText: 'Saumon ou EAN',
                              border: OutlineInputBorder(),
                            ),
                            controller: TextEditingController(text: ingredient.name)
                              ..selection = TextSelection.collapsed(offset: ingredient.name.length),
                            onChanged: (value) => onIngredientChanged(index, ingredient.copyWith(name: value)),
                          ),
                          const SizedBox(height: 8),
                          Row(
                            children: [
                              Expanded(
                                child: TextField(
                                  decoration: const InputDecoration(
                                    labelText: 'Quantité',
                                    border: OutlineInputBorder(),
                                  ),
                                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                                  controller: TextEditingController(text: ingredient.amount)
                                    ..selection = TextSelection.collapsed(offset: ingredient.amount.length),
                                  onChanged: (value) => onIngredientChanged(index, ingredient.copyWith(amount: value)),
                                ),
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: TextField(
                                  decoration: const InputDecoration(
                                    labelText: 'Unité',
                                    hintText: 'g',
                                    border: OutlineInputBorder(),
                                  ),
                                  controller: TextEditingController(text: ingredient.unit)
                                    ..selection = TextSelection.collapsed(offset: ingredient.unit.length),
                                  onChanged: (value) => onIngredientChanged(index, ingredient.copyWith(unit: value)),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 8),
                          Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              OutlinedButton.icon(
                                onPressed: () => onScanEan(index),
                                icon: const Icon(Icons.qr_code_scanner_rounded),
                                label: const Text('Scanner code-barres'),
                              ),
                              OutlinedButton(
                                onPressed: () => onLookupEan(index),
                                child: const Text('Charger depuis l EAN'),
                              ),
                              OutlinedButton(
                                onPressed: () => onIngredientRemoved(index),
                                child: const Text('Supprimer'),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                ],
              ),
            );
          }),
          const SizedBox(height: 4),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton.icon(
              onPressed: onIngredientAdded,
              icon: const Icon(Icons.add),
              label: const Text('Ajouter un aliment'),
            ),
          ),
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            decoration: BoxDecoration(
              color: const Color(0xFFFFFBEB),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xFFFDE68A)),
            ),
            child: const Text(
              'Pour publier au public, il faut une image, un temps de préparation et des aliments complets.',
              style: TextStyle(color: Color(0xFF92400E), fontWeight: FontWeight.w600),
            ),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              FilledButton(
                onPressed: submitting ? null : onSubmit,
                child: Text(submitting ? 'Enregistrement...' : (editing ? 'Mettre à jour' : 'Créer la recette')),
              ),
              const SizedBox(width: 8),
              OutlinedButton(onPressed: onClose, child: const Text('Annuler')),
            ],
          ),
        ],
      ),
    );
  }
}

class _RecipeImagePreview extends StatelessWidget {
  final String imageUrl;

  const _RecipeImagePreview({required this.imageUrl});

  @override
  Widget build(BuildContext context) {
    final trimmed = imageUrl.trim();
    if (trimmed.isEmpty) {
      return Container(
        height: 150,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(12),
          color: const Color(0xFFE2E8F0),
        ),
        alignment: Alignment.center,
        child: const Text('Aucune image sélectionnée'),
      );
    }

    if (trimmed.startsWith('data:image')) {
      final commaIndex = trimmed.indexOf(',');
      if (commaIndex > 0) {
        try {
          final encoded = trimmed.substring(commaIndex + 1);
          final bytes = base64Decode(encoded);
          return ClipRRect(
            borderRadius: BorderRadius.circular(12),
            child: Image.memory(
              bytes,
              height: 170,
              width: double.infinity,
              fit: BoxFit.cover,
            ),
          );
        } catch (_) {
          return _invalidImagePreview();
        }
      }
      return _invalidImagePreview();
    }

    return ClipRRect(
      borderRadius: BorderRadius.circular(12),
      child: Image.network(
        trimmed,
        height: 170,
        width: double.infinity,
        fit: BoxFit.cover,
        errorBuilder: (context, error, stackTrace) => _invalidImagePreview(),
      ),
    );
  }

  Widget _invalidImagePreview() {
    return Container(
      height: 150,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        color: const Color(0xFFFEE2E2),
      ),
      alignment: Alignment.center,
      child: const Text('Image invalide', style: TextStyle(color: Color(0xFFB91C1C))),
    );
  }
}

class _ActiveRecipeCard extends StatelessWidget {
  final _RecipeRecord recipe;
  final _ActiveRecipeNutrition? nutrition;
  final bool loading;

  const _ActiveRecipeCard({
    required this.recipe,
    required this.nutrition,
    required this.loading,
  });

  @override
  Widget build(BuildContext context) {
    Widget buildRecipeImage(String raw) {
      final trimmed = raw.trim();

      if (trimmed.startsWith('data:image')) {
        final commaIndex = trimmed.indexOf(',');
        if (commaIndex > 0) {
          try {
            final bytes = base64Decode(trimmed.substring(commaIndex + 1));
            return Image.memory(
              bytes,
              fit: BoxFit.cover,
              errorBuilder: (context, error, stackTrace) => Container(
                color: const Color(0xFFE2E8F0),
                alignment: Alignment.center,
                child: const Icon(Icons.image_not_supported_outlined, color: Color(0xFF64748B)),
              ),
            );
          } catch (_) {
            return Container(
              color: const Color(0xFFE2E8F0),
              alignment: Alignment.center,
              child: const Icon(Icons.image_not_supported_outlined, color: Color(0xFF64748B)),
            );
          }
        }
      }

      return Image.network(
        trimmed,
        fit: BoxFit.cover,
        errorBuilder: (context, error, stackTrace) => Container(
          color: const Color(0xFFE2E8F0),
          alignment: Alignment.center,
          child: const Icon(Icons.image_not_supported_outlined, color: Color(0xFF64748B)),
        ),
      );
    }

    return Container(
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (recipe.imageUrl != null && recipe.imageUrl!.trim().isNotEmpty)
            AspectRatio(
              aspectRatio: 16 / 9,
              child: buildRecipeImage(recipe.imageUrl!),
            ),
          Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(recipe.title, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: Color(0xFF020617))),
                const SizedBox(height: 8),
                Text(
                  recipe.description?.isNotEmpty == true ? recipe.description! : 'Aucune description pour le moment.',
                  style: const TextStyle(color: Color(0xFF475569), height: 1.4),
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    _MacroPill(label: 'Lipides', value: loading ? '...' : '${nutrition?.fat ?? 0} g', color: const Color(0xFFFFFBEB), border: const Color(0xFFFDE68A), text: const Color(0xFF92400E)),
                    const SizedBox(width: 8),
                    _MacroPill(label: 'Glucides', value: loading ? '...' : '${nutrition?.carbs ?? 0} g', color: const Color(0xFFECFEFF), border: const Color(0xFFA5F3FC), text: const Color(0xFF155E75)),
                    const SizedBox(width: 8),
                    _MacroPill(label: 'Protéines', value: loading ? '...' : '${nutrition?.proteins ?? 0} g', color: const Color(0xFFECFDF5), border: const Color(0xFF86EFAC), text: const Color(0xFF166534)),
                  ],
                ),
                const SizedBox(height: 8),
                if (loading)
                  const Text('Calcul nutritionnel en cours...', style: TextStyle(fontSize: 12, color: Color(0xFF64748B)))
                else if (nutrition != null)
                  Text(
                    'Valeurs calculées sur ${nutrition!.resolvedCount}/${nutrition!.totalCount} aliments.',
                    style: const TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _MacroPill extends StatelessWidget {
  final String label;
  final String value;
  final Color color;
  final Color border;
  final Color text;

  const _MacroPill({
    required this.label,
    required this.value,
    required this.color,
    required this.border,
    required this.text,
  });

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
          color: color,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: text)),
            const SizedBox(height: 3),
            Text(value, style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: text)),
          ],
        ),
      ),
    );
  }
}

class _RecipeListTile extends StatelessWidget {
  final _RecipeRecord recipe;
  final bool selected;
  final VoidCallback onTap;
  final VoidCallback? onEdit;
  final VoidCallback? onDelete;

  const _RecipeListTile({
    required this.recipe,
    required this.selected,
    required this.onTap,
    this.onEdit,
    this.onDelete,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: selected ? const Color(0xFFECFDF5) : Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color: selected ? const Color(0xFFA7F3D0) : const Color(0xFFE2E8F0),
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    recipe.title,
                    style: const TextStyle(fontWeight: FontWeight.w700, color: Color(0xFF0F172A)),
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF7FEE7),
                    borderRadius: BorderRadius.circular(999),
                    border: Border.all(color: const Color(0xFFD9F99D)),
                  ),
                  child: Text(
                    '${recipe.calories.round()} kcal',
                    style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: Color(0xFF4D7C0F)),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              recipe.description?.trim().isEmpty == false ? recipe.description! : 'Aucune description',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: Color(0xFF475569)),
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(999),
                    color: recipe.isPublic ? const Color(0xFFECFDF5) : const Color(0xFFF8FAFC),
                    border: Border.all(
                      color: recipe.isPublic ? const Color(0xFFA7F3D0) : const Color(0xFFE2E8F0),
                    ),
                  ),
                  child: Text(recipe.isPublic ? 'Publique' : 'Privée', style: const TextStyle(fontSize: 11)),
                ),
                const SizedBox(width: 6),
                Text('${recipe.ingredientsCount} aliments', style: const TextStyle(fontSize: 12, color: Color(0xFF64748B))),
                const Spacer(),
                if (onEdit != null)
                  IconButton(onPressed: onEdit, icon: const Icon(Icons.edit_outlined), tooltip: 'Modifier'),
                if (onDelete != null)
                  IconButton(onPressed: onDelete, icon: const Icon(Icons.delete_outline), tooltip: 'Supprimer'),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _EanScannerSheet extends StatefulWidget {
  const _EanScannerSheet();

  @override
  State<_EanScannerSheet> createState() => _EanScannerSheetState();
}

class _EanScannerSheetState extends State<_EanScannerSheet> {
  final MobileScannerController _controller = MobileScannerController(
    facing: CameraFacing.back,
    detectionSpeed: DetectionSpeed.noDuplicates,
    formats: const [
      BarcodeFormat.ean13,
      BarcodeFormat.ean8,
      BarcodeFormat.upcA,
      BarcodeFormat.upcE,
      BarcodeFormat.code128,
    ],
  );

  bool _handled = false;
  String? _lastPreview;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onDetect(BarcodeCapture capture) {
    if (_handled) return;

    for (final barcode in capture.barcodes) {
      final raw = barcode.rawValue?.trim();
      if (raw == null || raw.isEmpty) continue;

      if (mounted) {
        setState(() {
          _lastPreview = raw;
        });
      }

      if (RegExp(r'^\d{8,14}$').hasMatch(raw)) {
        _handled = true;
        Navigator.of(context).pop(raw);
        return;
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 8, 8),
          child: Row(
            children: [
              const Expanded(
                child: Text(
                  'Scanner un code-barres',
                  style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w700),
                ),
              ),
              IconButton(
                onPressed: () => Navigator.of(context).pop(),
                icon: const Icon(Icons.close, color: Colors.white),
              ),
            ],
          ),
        ),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              MobileScanner(
                controller: _controller,
                onDetect: _onDetect,
              ),
              Align(
                alignment: Alignment.center,
                child: LayoutBuilder(
                  builder: (context, constraints) {
                    final frameWidth = (constraints.maxWidth * 0.72).clamp(220.0, 340.0);
                    final frameHeight = (constraints.maxHeight * 0.2).clamp(96.0, 150.0);

                    return Container(
                      width: frameWidth,
                      height: frameHeight,
                      decoration: BoxDecoration(
                        border: Border.all(color: const Color(0xFF34D399), width: 3),
                        borderRadius: BorderRadius.circular(16),
                      ),
                    );
                  },
                ),
              ),
            ],
          ),
        ),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.fromLTRB(14, 10, 14, 14),
          color: const Color(0xFF020617),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Place le code-barres dans le cadre.',
                style: TextStyle(color: Colors.white70),
              ),
              if (_lastPreview != null) ...[
                const SizedBox(height: 6),
                Text(
                  'Détection: $_lastPreview',
                  style: const TextStyle(color: Colors.white),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _RecipeRecord {
  final int id;
  final String title;
  final String? description;
  final num calories;
  final int? prepTimeMinutes;
  final String? imageUrl;
  final List<_RecipeIngredient> ingredients;
  final int ingredientsCount;
  final bool isPublic;
  final bool isOwner;
  final String? createdAt;
  final String? updatedAt;

  const _RecipeRecord({
    required this.id,
    required this.title,
    required this.description,
    required this.calories,
    required this.prepTimeMinutes,
    required this.imageUrl,
    required this.ingredients,
    required this.ingredientsCount,
    required this.isPublic,
    required this.isOwner,
    required this.createdAt,
    required this.updatedAt,
  });

  factory _RecipeRecord.fromJson(Map<String, dynamic> json) {
    final ingredientsRaw = json['ingredients'];
    final ingredients = (ingredientsRaw is List)
        ? ingredientsRaw
            .whereType<Map>()
            .map((item) => _RecipeIngredient.fromJson(Map<String, dynamic>.from(item)))
            .toList()
        : <_RecipeIngredient>[];

    final calories = num.tryParse((json['calories'] ?? 0).toString()) ?? 0;
    final prepTime = int.tryParse((json['prep_time_minutes'] ?? '').toString());

    return _RecipeRecord(
      id: int.tryParse(json['id'].toString()) ?? 0,
      title: (json['title'] ?? '').toString(),
      description: json['description']?.toString(),
      calories: calories,
      prepTimeMinutes: prepTime,
      imageUrl: json['image_url']?.toString(),
      ingredients: ingredients,
      ingredientsCount: int.tryParse((json['ingredients_count'] ?? ingredients.length).toString()) ?? ingredients.length,
      isPublic: (json['is_public'] == true || json['is_public'] == 1),
      isOwner: (json['is_owner'] == true || json['is_owner'] == 1),
      createdAt: json['created_at']?.toString(),
      updatedAt: json['updated_at']?.toString(),
    );
  }
}

class _RecipeIngredient {
  final String name;
  final String? ean;
  final num? amount;
  final String? unit;

  const _RecipeIngredient({
    required this.name,
    required this.ean,
    required this.amount,
    required this.unit,
  });

  factory _RecipeIngredient.fromJson(Map<String, dynamic> json) {
    return _RecipeIngredient(
      name: (json['name'] ?? '').toString(),
      ean: json['ean']?.toString(),
      amount: num.tryParse((json['amount'] ?? '').toString()),
      unit: json['unit']?.toString(),
    );
  }
}

class _RecipeIngredientDraft {
  final String name;
  final String ean;
  final String amount;
  final String unit;

  const _RecipeIngredientDraft({
    required this.name,
    required this.ean,
    required this.amount,
    required this.unit,
  });

  factory _RecipeIngredientDraft.empty() {
    return const _RecipeIngredientDraft(name: '', ean: '', amount: '', unit: '');
  }

  _RecipeIngredientDraft copyWith({
    String? name,
    String? ean,
    String? amount,
    String? unit,
  }) {
    return _RecipeIngredientDraft(
      name: name ?? this.name,
      ean: ean ?? this.ean,
      amount: amount ?? this.amount,
      unit: unit ?? this.unit,
    );
  }
}

class _RecipeDraft {
  final String title;
  final String description;
  final String calories;
  final String prepTimeMinutes;
  final String imageUrl;
  final bool isPublic;
  final List<_RecipeIngredientDraft> ingredients;

  const _RecipeDraft({
    required this.title,
    required this.description,
    required this.calories,
    required this.prepTimeMinutes,
    required this.imageUrl,
    required this.isPublic,
    required this.ingredients,
  });

  factory _RecipeDraft.empty() {
    return _RecipeDraft(
      title: '',
      description: '',
      calories: '0',
      prepTimeMinutes: '',
      imageUrl: '',
      isPublic: false,
      ingredients: [_RecipeIngredientDraft.empty()],
    );
  }

  factory _RecipeDraft.fromRecipe(_RecipeRecord recipe) {
    final ingredients = recipe.ingredients.isEmpty
        ? [_RecipeIngredientDraft.empty()]
        : recipe.ingredients
            .map(
              (item) => _RecipeIngredientDraft(
                name: (item.ean ?? item.name).trim(),
                ean: item.ean?.trim() ?? '',
                amount: item.amount?.toString() ?? '',
                unit: item.unit ?? '',
              ),
            )
            .toList();

    return _RecipeDraft(
      title: recipe.title,
      description: recipe.description ?? '',
      calories: recipe.calories.round().toString(),
      prepTimeMinutes: recipe.prepTimeMinutes?.toString() ?? '',
      imageUrl: recipe.imageUrl ?? '',
      isPublic: recipe.isPublic,
      ingredients: ingredients,
    );
  }

  _RecipeDraft copyWith({
    String? title,
    String? description,
    String? calories,
    String? prepTimeMinutes,
    String? imageUrl,
    bool? isPublic,
    List<_RecipeIngredientDraft>? ingredients,
  }) {
    return _RecipeDraft(
      title: title ?? this.title,
      description: description ?? this.description,
      calories: calories ?? this.calories,
      prepTimeMinutes: prepTimeMinutes ?? this.prepTimeMinutes,
      imageUrl: imageUrl ?? this.imageUrl,
      isPublic: isPublic ?? this.isPublic,
      ingredients: ingredients ?? this.ingredients,
    );
  }

  Map<String, dynamic> toPayload() {
    final caloriesNumber = num.tryParse(calories.trim()) ?? 0;
    final prepTime = num.tryParse(prepTimeMinutes.trim());

    final mappedIngredients = ingredients
        .map((item) {
          final name = item.name.trim();
          if (name.isEmpty) return null;

          final normalizedEan = item.ean.trim();
          final ean = RegExp(r'^\d{8,14}$').hasMatch(normalizedEan)
              ? normalizedEan
              : (RegExp(r'^\d{8,14}$').hasMatch(name) ? name : null);

          return {
            'name': name,
            'ean': ean,
            'amount': num.tryParse(item.amount.trim()),
            'unit': item.unit.trim().isEmpty ? null : item.unit.trim(),
          };
        })
        .whereType<Map<String, dynamic>>()
        .toList();

    return {
      'title': title.trim(),
      'description': description.trim().isEmpty ? null : description.trim(),
      'calories': caloriesNumber,
      'prep_time_minutes': prepTime,
      'image_url': imageUrl.trim().isEmpty ? null : imageUrl.trim(),
      'ingredients': mappedIngredients,
      'is_public': isPublic,
    };
  }
}

class _NutritionPer100 {
  final num? calories;
  final num? fat;
  final num? carbs;
  final num? proteins;

  const _NutritionPer100({
    required this.calories,
    required this.fat,
    required this.carbs,
    required this.proteins,
  });

  bool get isEmpty => calories == null && fat == null && carbs == null && proteins == null;
}

class _ActiveRecipeNutrition {
  final double fat;
  final double carbs;
  final double proteins;
  final int resolvedCount;
  final int totalCount;

  const _ActiveRecipeNutrition({
    required this.fat,
    required this.carbs,
    required this.proteins,
    required this.resolvedCount,
    required this.totalCount,
  });
}
