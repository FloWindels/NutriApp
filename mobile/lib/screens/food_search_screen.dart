import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class FoodSearchScreen extends StatefulWidget {
  const FoodSearchScreen({super.key});

  @override
  State<FoodSearchScreen> createState() => _FoodSearchScreenState();
}

class _FoodSearchScreenState extends State<FoodSearchScreen> {
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

  final _eanController = TextEditingController();

  bool _loading = false;
  bool _enriching = false;
  bool _saving = false;
  bool _creationOpen = false;
  bool _editing = false;

  String _errorMessage = '';
  String _successMessage = '';

  _FoodItem? _currentFood;
  _FoodDraft? _draft;

  int _requestId = 0;
  final Map<String, _FoodItem> _publicCache = {};
  final Map<String, _FoodItem?> _offCache = {};

  @override
  void dispose() {
    _eanController.dispose();
    super.dispose();
  }

  num? _toNumber(String value) {
    final trimmed = value.trim();
    if (trimmed.isEmpty) return null;
    return num.tryParse(trimmed);
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

  Future<_FoodItem?> _fetchFoodFromPublicCatalog(String barcode) async {
    final token = await _storage.read(key: 'token');

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
      final data = payload['data'];
      if (data == null || data is! Map) return null;
      return _FoodItem.fromBackend(Map<String, dynamic>.from(data));
    } on DioException catch (e) {
      if (e.response?.statusCode == 404) {
        return null;
      }
      throw Exception('Impossible de lire la base publique.');
    }
  }

  _FoodItem _normalizeOpenFoodFactsProduct(Map<String, dynamic> product) {
    final nutriments = (product['nutriments'] is Map)
        ? Map<String, dynamic>.from(product['nutriments'] as Map)
        : <String, dynamic>{};

    final rawBrand = product['brands']?.toString();
    final brandCandidates = rawBrand
      ?.split(',')
      .map((v) => v.trim())
      .where((v) => v.isNotEmpty)
      .toList();
    final brand = brandCandidates?.firstOrNull;

    num? toRounded(dynamic value, {int decimals = 1}) {
      if (value == null) return null;
      final parsed = num.tryParse(value.toString());
      if (parsed == null) return null;
      return num.parse(parsed.toStringAsFixed(decimals));
    }

    final productName = product['product_name']?.toString().trim();

    final calories = nutriments['energy_kcal_100g'] ?? nutriments['energy_100g'];

    return _FoodItem(
      id: null,
      barcode: product['code']?.toString() ?? '',
      name: (productName?.isNotEmpty ?? false) ? productName! : 'Produit sans nom',
      brand: brand,
      imageUrl: product['image_front_url']?.toString() ?? product['image_url']?.toString(),
      calories: calories == null ? null : num.tryParse(calories.toString())?.round(),
      fat: toRounded(nutriments['fat_100g']),
      carbs: toRounded(nutriments['carbohydrates_100g']),
      proteins: toRounded(nutriments['proteins_100g']),
      sourceType: 'open_food_facts',
      isOwner: false,
    );
  }

  Future<_FoodItem?> _fetchFoodFromOpenFoodFacts(String barcode) async {
    try {
      final response = await _dio.get(
        'https://world.openfoodfacts.org/api/v2/product/$barcode.json',
        options: Options(
          sendTimeout: const Duration(milliseconds: 3500),
          receiveTimeout: const Duration(milliseconds: 3500),
        ),
      );
      final payload = Map<String, dynamic>.from(response.data as Map);
      if (payload['status'] != 1 || payload['product'] == null || payload['product'] is! Map) {
        return null;
      }
      return _normalizeOpenFoodFactsProduct(Map<String, dynamic>.from(payload['product'] as Map));
    } on DioException {
      throw Exception('Impossible de joindre Open Food Facts.');
    }
  }

  Future<void> _enrichFromOpenFoodFacts(String barcode, int requestId) async {
    setState(() {
      _enriching = true;
    });

    try {
      _FoodItem? offFood;
      if (_offCache.containsKey(barcode)) {
        offFood = _offCache[barcode];
      } else {
        offFood = await _fetchFoodFromOpenFoodFacts(barcode);
        _offCache[barcode] = offFood;
      }

      if (_requestId != requestId || offFood == null) return;

      setState(() {
        _currentFood = offFood;
        _draft = _FoodDraft.fromFood(offFood!, barcodeFallback: barcode);
        _creationOpen = true;
        _editing = false;
        _successMessage = 'Produit trouvé sur Open Food Facts. Tu peux l ajouter à la base publique.';
        _errorMessage = '';
      });
    } catch (_) {
      if (_requestId != requestId) return;
    } finally {
      if (_requestId == requestId && mounted) {
        setState(() {
          _enriching = false;
        });
      }
    }
  }

  Future<void> _handleSearch(String barcode) async {
    final trimmed = barcode.trim();
    final requestId = _requestId + 1;
    _requestId = requestId;

    if (trimmed.length < 8) {
      setState(() {
        _errorMessage = 'Saisis un code EAN valide.';
        _successMessage = '';
        _currentFood = null;
        _draft = null;
        _creationOpen = false;
        _editing = false;
      });
      return;
    }

    setState(() {
      _loading = true;
      _enriching = false;
      _errorMessage = '';
      _successMessage = '';
    });

    _FoodItem? publicFood;
    var publicCatalogUnavailable = false;

    try {
      if (_publicCache[trimmed] != null) {
        publicFood = _publicCache[trimmed];
      } else {
        publicFood = await _fetchFoodFromPublicCatalog(trimmed);
        if (publicFood != null) {
          _publicCache[trimmed] = publicFood;
        }
      }
    } catch (_) {
      publicCatalogUnavailable = true;
    } finally {
      if (_requestId == requestId && mounted) {
        setState(() {
          _loading = false;
        });
      }
    }

    if (_requestId != requestId) return;

    if (publicFood != null) {
      setState(() {
        _currentFood = publicFood;
        _draft = null;
        _creationOpen = false;
        _editing = false;
      });
      return;
    }

    setState(() {
      _currentFood = null;
      _draft = _FoodDraft.empty(trimmed);
      _creationOpen = true;
      _editing = false;
      _errorMessage = publicCatalogUnavailable
          ? 'Base publique temporairement indisponible. Recherche Open Food Facts en cours...'
          : 'Produit absent de la base publique. Recherche Open Food Facts en cours...';
    });

    await _enrichFromOpenFoodFacts(trimmed, requestId);
  }

  Future<void> _openBarcodeScannerAndSearch() async {
    final scannedRaw = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.black,
      builder: (context) {
        final sheetHeight = (MediaQuery.sizeOf(context).height * 0.78).clamp(420.0, 680.0);
        return SizedBox(
          height: sheetHeight,
          child: const _BarcodeScannerSheet(),
        );
      },
    );

    if (!mounted || scannedRaw == null) {
      return;
    }

    final match = RegExp(r'\d{8,14}').firstMatch(scannedRaw.trim());
    final scannedEan = match?.group(0) ?? '';

    if (!RegExp(r'^\d{8,14}$').hasMatch(scannedEan)) {
      setState(() {
        _errorMessage = 'Code scanné invalide. Réessaie avec le code-barres complet.';
      });
      return;
    }

    _eanController.value = TextEditingValue(
      text: scannedEan,
      selection: TextSelection.collapsed(offset: scannedEan.length),
    );

    await _handleSearch(scannedEan);
  }

  Future<void> _handleCreateProduct() async {
    final token = await _storage.read(key: 'token');
    if (token == null) {
      setState(() {
        _errorMessage = 'Session invalide. Reconnecte-toi pour ajouter un produit.';
      });
      return;
    }

    if (_draft == null || _draft!.barcode.trim().isEmpty || _draft!.name.trim().isEmpty) {
      setState(() {
        _errorMessage = 'Le code EAN et le nom sont obligatoires pour créer le produit.';
      });
      return;
    }

    setState(() {
      _saving = true;
      _errorMessage = '';
      _successMessage = '';
    });

    try {
      final response = await _dio.post(
        '/foods',
        options: Options(headers: {'Authorization': 'Bearer $token'}),
        data: {
          'barcode': _draft!.barcode.trim(),
          'name': _draft!.name.trim(),
          'brand': _draft!.brand.trim().isEmpty ? null : _draft!.brand.trim(),
          'image_url': _draft!.imageUrl.trim().isEmpty ? null : _draft!.imageUrl.trim(),
          'calories': _toNumber(_draft!.calories),
          'fat': _toNumber(_draft!.fat),
          'carbs': _toNumber(_draft!.carbs),
          'proteins': _toNumber(_draft!.proteins),
          'source_type': _currentFood?.sourceType == 'open_food_facts' ? 'open_food_facts' : 'manual',
        },
      );

      final payload = Map<String, dynamic>.from(response.data as Map);
      final data = payload['data'];
      if (data is Map) {
        final normalized = _FoodItem.fromBackend(Map<String, dynamic>.from(data));
        setState(() {
          _currentFood = normalized;
          _publicCache[normalized.barcode] = normalized;
        });
      }

      setState(() {
        _successMessage = 'Produit enregistré dans la base publique.';
        _creationOpen = false;
        _editing = false;
        _draft = null;
      });
    } on DioException catch (e) {
      setState(() {
        _errorMessage = _extractMessage(e.response?.data, 'Impossible d enregistrer le produit.');
      });
    } finally {
      if (mounted) {
        setState(() {
          _saving = false;
        });
      }
    }
  }

  Future<void> _handleUpdateProduct() async {
    final token = await _storage.read(key: 'token');
    if (token == null) {
      setState(() {
        _errorMessage = 'Session invalide. Reconnecte-toi pour modifier un produit.';
      });
      return;
    }

    if (_currentFood?.id == null || _draft == null || _draft!.barcode.trim().isEmpty || _draft!.name.trim().isEmpty) {
      setState(() {
        _errorMessage = 'Informations insuffisantes pour modifier le produit.';
      });
      return;
    }

    setState(() {
      _saving = true;
      _errorMessage = '';
      _successMessage = '';
    });

    try {
      final response = await _dio.put(
        '/foods/${_currentFood!.id}',
        options: Options(headers: {'Authorization': 'Bearer $token'}),
        data: {
          'barcode': _draft!.barcode.trim(),
          'name': _draft!.name.trim(),
          'brand': _draft!.brand.trim().isEmpty ? null : _draft!.brand.trim(),
          'image_url': _draft!.imageUrl.trim().isEmpty ? null : _draft!.imageUrl.trim(),
          'calories': _toNumber(_draft!.calories),
          'fat': _toNumber(_draft!.fat),
          'carbs': _toNumber(_draft!.carbs),
          'proteins': _toNumber(_draft!.proteins),
        },
      );

      final payload = Map<String, dynamic>.from(response.data as Map);
      final data = payload['data'];
      if (data is Map) {
        final normalized = _FoodItem.fromBackend(Map<String, dynamic>.from(data));
        setState(() {
          _currentFood = normalized;
          _publicCache[normalized.barcode] = normalized;
        });
      }

      setState(() {
        _successMessage = 'Produit mis à jour.';
        _creationOpen = false;
        _editing = false;
        _draft = null;
      });
    } on DioException catch (e) {
      setState(() {
        _errorMessage = _extractMessage(e.response?.data, 'Modification impossible.');
      });
    } finally {
      if (mounted) {
        setState(() {
          _saving = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final twoColumns = constraints.maxWidth >= 980;

        return ListView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
          children: [
            if (!twoColumns) ...[
              _leftPanel(),
              const SizedBox(height: 16),
              _rightPanel(),
            ] else
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(flex: 95, child: _leftPanel()),
                  const SizedBox(width: 16),
                  Expanded(flex: 105, child: _rightPanel()),
                ],
              ),
          ],
        );
      },
    );
  }

  Widget _leftPanel() {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: const Color(0xFFF0FDF4),
        borderRadius: BorderRadius.circular(28),
        border: Border.all(color: const Color(0xFFD9F99D)),
        boxShadow: const [
          BoxShadow(
            color: Color(0x120F172A),
            blurRadius: 22,
            offset: Offset(0, 12),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Recherche d aliments',
            style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: 1.2, color: Color(0xFF047857)),
          ),
          const SizedBox(height: 6),
          const Text(
            'Base publique par EAN',
            style: TextStyle(fontSize: 28, fontWeight: FontWeight.w800, color: Color(0xFF020617), height: 1.1),
          ),
          const SizedBox(height: 8),
          const Text(
            'Cherche d abord dans la base publique du projet. Si le produit n existe pas, il peut être ajouté pour tous les clients.',
            style: TextStyle(color: Color(0xFF475569), height: 1.45),
          ),
          const SizedBox(height: 16),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(22),
              border: Border.all(color: const Color(0xFFE2E8F0)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Code EAN', style: TextStyle(fontWeight: FontWeight.w600, color: Color(0xFF334155))),
                const SizedBox(height: 10),
                LayoutBuilder(
                  builder: (context, constraints) {
                    final isCompact = constraints.maxWidth < 520;

                    final eanField = TextField(
                      controller: _eanController,
                      keyboardType: TextInputType.number,
                      onChanged: (value) {
                        final clean = value.replaceAll(RegExp(r'\s+'), '');
                        if (clean != value) {
                          _eanController.value = TextEditingValue(
                            text: clean,
                            selection: TextSelection.collapsed(offset: clean.length),
                          );
                        }
                      },
                      decoration: const InputDecoration(
                        hintText: 'Ex: 3017620422003',
                      ),
                    );

                    final searchButton = ElevatedButton(
                      onPressed: _loading ? null : () => _handleSearch(_eanController.text),
                      child: Text(_loading ? 'Recherche locale...' : 'Rechercher'),
                    );

                    final scannerButton = OutlinedButton.icon(
                      onPressed: _loading ? null : _openBarcodeScannerAndSearch,
                      icon: const Icon(Icons.qr_code_scanner_rounded),
                      label: const Text('Scanner'),
                    );

                    if (isCompact) {
                      return Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          eanField,
                          const SizedBox(height: 8),
                          Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              searchButton,
                              scannerButton,
                            ],
                          ),
                        ],
                      );
                    }

                    return Row(
                      children: [
                        Expanded(child: eanField),
                        const SizedBox(width: 10),
                        searchButton,
                        const SizedBox(width: 8),
                        scannerButton,
                      ],
                    );
                  },
                ),
                if (_enriching)
                  const Padding(
                    padding: EdgeInsets.only(top: 8),
                    child: Text(
                      'Vérification Open Food Facts en arrière-plan...',
                      style: TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                    ),
                  ),
                const SizedBox(height: 12),
                Wrap(
                  crossAxisAlignment: WrapCrossAlignment.center,
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    OutlinedButton(
                      onPressed: () {
                        setState(() {
                          _creationOpen = !_creationOpen;
                          _draft = _draft ?? _FoodDraft.empty(_eanController.text.trim());
                          _editing = false;
                        });
                      },
                      child: const Text('Créer dans la base publique'),
                    ),
                    const Text(
                      'Si le produit n existe pas, tu peux le créer pour tous.',
                      style: TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                    ),
                  ],
                ),
                if (_errorMessage.isNotEmpty)
                  Container(
                    margin: const EdgeInsets.only(top: 10),
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFF1F2),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFFFECDD3)),
                    ),
                    child: Text(_errorMessage, style: const TextStyle(color: Color(0xFFBE123C))),
                  ),
                if (_successMessage.isNotEmpty)
                  Container(
                    margin: const EdgeInsets.only(top: 10),
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: const Color(0xFFECFDF5),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFFA7F3D0)),
                    ),
                    child: Text(_successMessage, style: const TextStyle(color: Color(0xFF047857))),
                  ),
              ],
            ),
          ),
          if (_creationOpen) ...[
            const SizedBox(height: 14),
            _createOrEditPanel(),
          ],
          const SizedBox(height: 14),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: const [
              _UnitTile(label: 'Calories', value: 'kcal / 100g'),
              _UnitTile(label: 'Lipides', value: 'g / 100g'),
              _UnitTile(label: 'Glucides', value: 'g / 100g'),
              _UnitTile(label: 'Protéines', value: 'g / 100g'),
            ],
          ),
        ],
      ),
    );
  }

  Widget _createOrEditPanel() {
    final draft = _draft ?? _FoodDraft.empty(_eanController.text.trim());

    void update(_FoodDraft next) {
      setState(() {
        _draft = next;
      });
    }

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Créer le produit', style: TextStyle(fontWeight: FontWeight.w700, color: Color(0xFF0F172A))),
          if (_editing)
            const Padding(
              padding: EdgeInsets.only(top: 2),
              child: Text('Mode édition: réservé au créateur', style: TextStyle(fontSize: 12, color: Color(0xFFB45309))),
            ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              _FormFieldTile(label: 'EAN', value: draft.barcode, onChanged: (v) => update(draft.copyWith(barcode: v))),
              _FormFieldTile(label: 'Nom', value: draft.name, requiredLabel: true, onChanged: (v) => update(draft.copyWith(name: v))),
              _FormFieldTile(label: 'Marque', value: draft.brand, onChanged: (v) => update(draft.copyWith(brand: v))),
              _FormFieldTile(label: 'Image URL', value: draft.imageUrl, onChanged: (v) => update(draft.copyWith(imageUrl: v))),
              _FormFieldTile(label: 'Calories (kcal/100g)', value: draft.calories, onChanged: (v) => update(draft.copyWith(calories: v))),
              _FormFieldTile(label: 'Lipides (g/100g)', value: draft.fat, onChanged: (v) => update(draft.copyWith(fat: v))),
              _FormFieldTile(label: 'Glucides (g/100g)', value: draft.carbs, onChanged: (v) => update(draft.copyWith(carbs: v))),
              _FormFieldTile(label: 'Protéines (g/100g)', value: draft.proteins, onChanged: (v) => update(draft.copyWith(proteins: v))),
            ],
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              ElevatedButton(
                onPressed: _saving ? null : (_editing ? _handleUpdateProduct : _handleCreateProduct),
                child: Text(
                  _saving
                      ? 'Enregistrement...'
                      : _editing
                          ? 'Enregistrer les modifications'
                          : 'Enregistrer dans la base publique',
                ),
              ),
              OutlinedButton(
                onPressed: () {
                  setState(() {
                    _creationOpen = false;
                    _editing = false;
                    _draft = null;
                  });
                },
                child: const Text('Annuler'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _rightPanel() {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(28),
        border: Border.all(color: const Color(0xFFE2E8F0)),
        boxShadow: const [
          BoxShadow(
            color: Color(0x120F172A),
            blurRadius: 22,
            offset: Offset(0, 12),
          ),
        ],
      ),
      child: _currentFood == null
          ? Container(
              constraints: const BoxConstraints(minHeight: 360),
              decoration: BoxDecoration(
                color: const Color(0xFFF8FAFC),
                borderRadius: BorderRadius.circular(22),
                border: Border.all(color: const Color(0xFFE2E8F0), style: BorderStyle.solid),
              ),
              alignment: Alignment.center,
              padding: const EdgeInsets.all(24),
              child: const Text(
                'Lance une recherche pour afficher la fiche nutritionnelle du produit.',
                textAlign: TextAlign.center,
                style: TextStyle(color: Color(0xFF64748B)),
              ),
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF8FAFC),
                    borderRadius: BorderRadius.circular(22),
                    border: Border.all(color: const Color(0xFFE2E8F0)),
                  ),
                  child: Row(
                    children: [
                      Container(
                        width: 96,
                        height: 96,
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(16),
                          border: Border.all(color: const Color(0xFFE2E8F0)),
                        ),
                        clipBehavior: Clip.antiAlias,
                        child: _currentFood!.imageUrl != null && _currentFood!.imageUrl!.isNotEmpty
                            ? Image.network(
                                _currentFood!.imageUrl!,
                                fit: BoxFit.cover,
                                errorBuilder: (context, error, stackTrace) => const Center(
                                  child: Text('Sans image', style: TextStyle(fontSize: 12, color: Color(0xFF64748B))),
                                ),
                              )
                            : const Center(
                                child: Text('Sans image', style: TextStyle(fontSize: 12, color: Color(0xFF64748B))),
                              ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              _currentFood!.sourceType == 'open_food_facts'
                                  ? 'Produit Open Food Facts'
                                  : 'Produit de la base publique',
                              style: const TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w700,
                                letterSpacing: 1.1,
                                color: Color(0xFF047857),
                              ),
                            ),
                            const SizedBox(height: 6),
                            Text(
                              _currentFood!.name,
                              style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: Color(0xFF020617)),
                            ),
                            const SizedBox(height: 2),
                            Text(_currentFood!.brand ?? 'Marque inconnue', style: const TextStyle(color: Color(0xFF64748B))),
                            const SizedBox(height: 4),
                            Text('EAN ${_currentFood!.barcode}', style: const TextStyle(fontSize: 12, color: Color(0xFF64748B))),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 10,
                  runSpacing: 10,
                  children: [
                    _StatCard(label: 'Calories', value: _currentFood!.calories != null ? '${_currentFood!.calories} kcal' : 'N/A', tone: _Tone.emerald),
                    _StatCard(label: 'Lipides', value: _currentFood!.fat != null ? '${_currentFood!.fat} g' : 'N/A', tone: _Tone.amber),
                    _StatCard(label: 'Glucides', value: _currentFood!.carbs != null ? '${_currentFood!.carbs} g' : 'N/A', tone: _Tone.sky),
                    _StatCard(label: 'Protéines', value: _currentFood!.proteins != null ? '${_currentFood!.proteins} g' : 'N/A', tone: _Tone.rose),
                  ],
                ),
                if (_currentFood!.sourceType == 'open_food_facts')
                  Container(
                    margin: const EdgeInsets.only(top: 12),
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFFBEB),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFFFDE68A)),
                    ),
                    child: const Text(
                      'Ce produit vient d Open Food Facts. Clique sur "Enregistrer dans la base publique" pour le partager à tous les clients.',
                      style: TextStyle(color: Color(0xFF92400E)),
                    ),
                  ),
                if (_currentFood!.sourceType != 'open_food_facts')
                  Padding(
                    padding: const EdgeInsets.only(top: 12),
                    child: Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        OutlinedButton(
                          onPressed: !_currentFood!.isOwner
                              ? null
                              : () {
                                  setState(() {
                                    _draft = _FoodDraft.fromFood(_currentFood!);
                                    _creationOpen = true;
                                    _editing = true;
                                  });
                                },
                          child: const Text('Modifier cet aliment'),
                        ),
                        if (!_currentFood!.isOwner)
                          const Text(
                            'Seul le créateur peut modifier cet aliment.',
                            style: TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                          ),
                      ],
                    ),
                  ),
              ],
            ),
    );
  }
}

class _FoodItem {
  final int? id;
  final String barcode;
  final String name;
  final String? brand;
  final String? imageUrl;
  final num? calories;
  final num? fat;
  final num? carbs;
  final num? proteins;
  final String sourceType;
  final bool isOwner;

  const _FoodItem({
    required this.id,
    required this.barcode,
    required this.name,
    required this.brand,
    required this.imageUrl,
    required this.calories,
    required this.fat,
    required this.carbs,
    required this.proteins,
    required this.sourceType,
    required this.isOwner,
  });

  factory _FoodItem.fromBackend(Map<String, dynamic> json) {
    return _FoodItem(
      id: json['id'] as int?,
      barcode: json['barcode']?.toString() ?? '',
      name: json['name']?.toString() ?? 'Produit sans nom',
      brand: json['brand']?.toString(),
      imageUrl: json['image_url']?.toString(),
      calories: json['calories'] as num?,
      fat: json['fat'] as num?,
      carbs: json['carbs'] as num?,
      proteins: json['proteins'] as num?,
      sourceType: json['source_type']?.toString() ?? 'manual',
      isOwner: json['is_owner'] == true,
    );
  }
}

class _BarcodeScannerSheet extends StatefulWidget {
  const _BarcodeScannerSheet();

  @override
  State<_BarcodeScannerSheet> createState() => _BarcodeScannerSheetState();
}

class _BarcodeScannerSheetState extends State<_BarcodeScannerSheet> {
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
  String? _preview;

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

      final match = RegExp(r'\d{8,14}').firstMatch(raw);
      if (mounted) {
        setState(() {
          _preview = raw;
        });
      }

      if (match != null) {
        _handled = true;
        Navigator.of(context).pop(match.group(0));
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
                'Place le code-barres dans le cadre pour lancer la recherche.',
                style: TextStyle(color: Colors.white70),
              ),
              if (_preview != null) ...[
                const SizedBox(height: 6),
                Text(
                  'Détection: $_preview',
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

class _FoodDraft {
  final String barcode;
  final String name;
  final String brand;
  final String imageUrl;
  final String calories;
  final String fat;
  final String carbs;
  final String proteins;

  const _FoodDraft({
    required this.barcode,
    required this.name,
    required this.brand,
    required this.imageUrl,
    required this.calories,
    required this.fat,
    required this.carbs,
    required this.proteins,
  });

  factory _FoodDraft.empty(String barcode) {
    return _FoodDraft(
      barcode: barcode,
      name: '',
      brand: '',
      imageUrl: '',
      calories: '',
      fat: '',
      carbs: '',
      proteins: '',
    );
  }

  factory _FoodDraft.fromFood(_FoodItem food, {String? barcodeFallback}) {
    return _FoodDraft(
      barcode: food.barcode.isNotEmpty ? food.barcode : (barcodeFallback ?? ''),
      name: food.name,
      brand: food.brand ?? '',
      imageUrl: food.imageUrl ?? '',
      calories: food.calories?.toString() ?? '',
      fat: food.fat?.toString() ?? '',
      carbs: food.carbs?.toString() ?? '',
      proteins: food.proteins?.toString() ?? '',
    );
  }

  _FoodDraft copyWith({
    String? barcode,
    String? name,
    String? brand,
    String? imageUrl,
    String? calories,
    String? fat,
    String? carbs,
    String? proteins,
  }) {
    return _FoodDraft(
      barcode: barcode ?? this.barcode,
      name: name ?? this.name,
      brand: brand ?? this.brand,
      imageUrl: imageUrl ?? this.imageUrl,
      calories: calories ?? this.calories,
      fat: fat ?? this.fat,
      carbs: carbs ?? this.carbs,
      proteins: proteins ?? this.proteins,
    );
  }
}

class _UnitTile extends StatelessWidget {
  final String label;
  final String value;

  const _UnitTile({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 155,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, letterSpacing: 1.0, color: Color(0xFF64748B)),
          ),
          const SizedBox(height: 2),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w700, color: Color(0xFF0F172A))),
        ],
      ),
    );
  }
}

class _FormFieldTile extends StatelessWidget {
  final String label;
  final bool requiredLabel;
  final String value;
  final ValueChanged<String> onChanged;

  const _FormFieldTile({
    required this.label,
    required this.value,
    required this.onChanged,
    this.requiredLabel = false,
  });

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 220,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text.rich(
            TextSpan(
              text: label,
              children: requiredLabel
                  ? const [
                      TextSpan(text: ' *', style: TextStyle(color: Color(0xFFE11D48))),
                    ]
                  : const [],
            ),
            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: Color(0xFF334155)),
          ),
          const SizedBox(height: 6),
          TextFormField(
            key: ValueKey('$label-$value'),
            initialValue: value,
            onChanged: onChanged,
            decoration: const InputDecoration(isDense: true),
          ),
        ],
      ),
    );
  }
}

enum _Tone { emerald, amber, sky, rose }

class _StatCard extends StatelessWidget {
  final String label;
  final String value;
  final _Tone tone;

  const _StatCard({required this.label, required this.value, required this.tone});

  @override
  Widget build(BuildContext context) {
    final toneMap = switch (tone) {
      _Tone.emerald => (bg: const Color(0xFFECFDF5), border: const Color(0xFFA7F3D0), text: const Color(0xFF065F46)),
      _Tone.amber => (bg: const Color(0xFFFFFBEB), border: const Color(0xFFFDE68A), text: const Color(0xFF92400E)),
      _Tone.sky => (bg: const Color(0xFFF0F9FF), border: const Color(0xFFBAE6FD), text: const Color(0xFF075985)),
      _Tone.rose => (bg: const Color(0xFFFFF1F2), border: const Color(0xFFFECDD3), text: const Color(0xFF9F1239)),
    };

    return Container(
      width: 160,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: toneMap.bg,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: toneMap.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, letterSpacing: 1.0, color: toneMap.text)),
          const SizedBox(height: 2),
          Text(value, style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: toneMap.text)),
        ],
      ),
    );
  }
}

extension<T> on List<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
