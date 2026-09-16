import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

class StockScreen extends StatefulWidget {
  const StockScreen({super.key});

  @override
  State<StockScreen> createState() => _StockScreenState();
}

class _StockScreenState extends State<StockScreen> {
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

  final _queryController = TextEditingController();
  final _newLocationController = TextEditingController();
  final _quantityController = TextEditingController(text: '1');
  final _unitController = TextEditingController(text: 'unite');
  final _expiresAtController = TextEditingController();

  bool _searchLoading = false;
  bool _enriching = false;
  bool _saving = false;
  bool _creatingLocation = false;
  bool _itemsLoading = true;
  bool _addFormVisible = false;
  int? _savingItemId;

  String _errorMessage = '';
  String _successMessage = '';

  List<_FoodSearchItem> _searchResults = [];
  _FoodSearchItem? _selectedFood;
  _OffCandidate? _offCandidate;

  List<_StockItem> _items = [];
  List<_StockLocation> _locations = [];
  int? _selectedLocationId;
  int? _activeLocationId;

  int _requestId = 0;

  final Map<int, _ItemDraft> _drafts = {};
  final Set<int> _expandedItemIds = <int>{};
  final Map<String, _FoodSearchItem> _publicEanCache = {};
  final Map<String, _OffCandidate?> _offCache = {};
  final ValueNotifier<int> _addSheetRefresh = ValueNotifier<int>(0);

  @override
  void initState() {
    super.initState();
    _loadStock();
  }

  @override
  void dispose() {
    _addSheetRefresh.dispose();
    _queryController.dispose();
    _newLocationController.dispose();
    _quantityController.dispose();
    _unitController.dispose();
    _expiresAtController.dispose();
    super.dispose();
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
        if (parts.isNotEmpty) {
          return parts.join(' ');
        }
      }
    }

    return fallback;
  }

  bool _hasText(Object? value) {
    if (value == null) return false;
    final text = value.toString().trim();
    return text.isNotEmpty && text.toLowerCase() != 'null';
  }

  bool _isLikelyBarcode(String value) {
    return RegExp(r'^\d{8,14}$').hasMatch(value);
  }

  List<String> _barcodeCandidates(String barcode) {
    final clean = barcode.trim();
    if (!_isLikelyBarcode(clean)) return [clean];

    final variants = <String>{clean};
    if (clean.length == 12) {
      variants.add('0$clean');
    }
    if (clean.length == 13 && clean.startsWith('0')) {
      variants.add(clean.substring(1));
    }

    return variants.toList();
  }

  void _notifyAddSheetRefresh() {
    _addSheetRefresh.value = _addSheetRefresh.value + 1;
  }

  Future<void> _loadStock() async {
    setState(() {
      _itemsLoading = true;
      _errorMessage = '';
    });

    try {
      final token = await _storage.read(key: 'token');
      if (token == null) {
        throw Exception('Session invalide. Reconnecte-toi.');
      }

      final response = await _dio.get(
        '/stocks',
        options: Options(headers: {'Authorization': 'Bearer $token'}),
      );

      final payload = Map<String, dynamic>.from(response.data as Map);
      final items = (payload['data'] as List? ?? [])
          .map((item) => _StockItem.fromJson(Map<String, dynamic>.from(item as Map)))
          .toList();

      final locations = (payload['locations'] as List? ?? [])
          .map((item) => _StockLocation.fromJson(Map<String, dynamic>.from(item as Map)))
          .toList();

      final nextDrafts = <int, _ItemDraft>{};
      for (final item in items) {
        nextDrafts[item.id] = _ItemDraft(
          quantity: item.quantity.toString(),
          unit: item.unit,
          expiresAt: item.expiresAt ?? '',
        );
      }

      setState(() {
        _items = items;
        _locations = locations;
        _drafts
          ..clear()
          ..addAll(nextDrafts);
        _expandedItemIds.removeWhere((id) => items.every((item) => item.id != id));

        if (_selectedLocationId == null && locations.isNotEmpty) {
          _selectedLocationId = locations.first.id;
        } else if (_selectedLocationId != null && locations.every((loc) => loc.id != _selectedLocationId)) {
          _selectedLocationId = locations.isNotEmpty ? locations.first.id : null;
        }

        if (_activeLocationId != null && locations.every((loc) => loc.id != _activeLocationId)) {
          _activeLocationId = null;
        }
      });
    } on DioException catch (e) {
      setState(() {
        _errorMessage = _extractMessage(e.response?.data, 'Impossible de charger ton stock.');
      });
    } catch (e) {
      setState(() {
        _errorMessage = e.toString();
      });
    } finally {
      if (mounted) {
        setState(() {
          _itemsLoading = false;
        });
      }
    }
  }

  Future<void> _handleSearch() async {
    final trimmedQuery = _queryController.text.trim();

    if (trimmedQuery.length < 2) {
      setState(() {
        _errorMessage = 'Saisis au moins 2 caracteres pour rechercher.';
      });
      return;
    }

    setState(() {
      _searchLoading = true;
      _enriching = false;
      _errorMessage = '';
      _successMessage = '';
      _offCandidate = null;
    });
    _notifyAddSheetRefresh();

    try {
      if (_isLikelyBarcode(trimmedQuery)) {
        final requestId = _requestId + 1;
        _requestId = requestId;
        final barcodeCandidates = _barcodeCandidates(trimmedQuery);

        _FoodSearchItem? localFood;

        for (final code in barcodeCandidates) {
          if (_publicEanCache[code] != null) {
            localFood = _publicEanCache[code];
            break;
          }
        }

        if (localFood == null) {
          final token = await _storage.read(key: 'token');

          for (final code in barcodeCandidates) {
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
                if (data['id'] != null) {
                  localFood = _FoodSearchItem(
                    id: data['id'] as int,
                    barcode: data['barcode']?.toString() ?? code,
                    name: data['name']?.toString() ?? 'Aliment',
                    brand: data['brand']?.toString(),
                  );
                  _publicEanCache[trimmedQuery] = localFood;
                  _publicEanCache[code] = localFood;
                  break;
                }
              }
            } on DioException catch (e) {
              if (e.response?.statusCode != 404) {
                throw Exception(_extractMessage(e.response?.data, 'Recherche locale impossible.'));
              }
            }
          }
        }

        if (localFood != null) {
          setState(() {
            _searchResults = [localFood!];
            _selectedFood = localFood;
            _successMessage = 'Aliment trouve dans la base publique.';
          });
          _notifyAddSheetRefresh();
          return;
        }

        setState(() {
          _searchResults = [];
          _selectedFood = null;
          _errorMessage = 'Produit absent de la base publique. Recherche Open Food Facts en cours...';
        });
        _notifyAddSheetRefresh();
        await _enrichFromOpenFoodFacts(trimmedQuery, requestId);
        return;
      }

      final response = await _dio.get(
        '/foods/search',
        queryParameters: {'q': trimmedQuery},
      );

      final payload = Map<String, dynamic>.from(response.data as Map);
      final results = (payload['data'] as List? ?? [])
          .map((food) {
            final item = Map<String, dynamic>.from(food as Map);
            return _FoodSearchItem(
              id: item['id'] as int,
              barcode: item['barcode']?.toString() ?? '',
              name: item['name']?.toString() ?? 'Aliment',
              brand: item['brand']?.toString(),
            );
          })
          .toList();

      setState(() {
        _searchResults = results;
        _selectedFood = results.isNotEmpty ? results.first : null;
        if (results.isEmpty) {
          _errorMessage = 'Aucun aliment trouve.';
        }
      });
      _notifyAddSheetRefresh();
    } on DioException catch (e) {
      setState(() {
        _errorMessage = _extractMessage(e.response?.data, 'Recherche impossible.');
      });
      _notifyAddSheetRefresh();
    } catch (e) {
      setState(() {
        _errorMessage = e.toString();
      });
      _notifyAddSheetRefresh();
    } finally {
      if (mounted) {
        setState(() {
          _searchLoading = false;
        });
        _notifyAddSheetRefresh();
      }
    }
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

    if (!_isLikelyBarcode(scannedEan)) {
      setState(() {
        _errorMessage = 'Code scanné invalide. Réessaie avec le code-barres complet.';
      });
      _notifyAddSheetRefresh();
      return;
    }

    _queryController.value = TextEditingValue(
      text: scannedEan,
      selection: TextSelection.collapsed(offset: scannedEan.length),
    );

    await _handleSearch();
  }

  Future<void> _enrichFromOpenFoodFacts(String barcode, int requestId) async {
    setState(() {
      _enriching = true;
    });

    try {
      _OffCandidate? candidate;

      for (final code in _barcodeCandidates(barcode)) {
        if (_offCache.containsKey(code)) {
          candidate = _offCache[code];
          if (candidate != null) break;
          continue;
        }

        final response = await _dio.get(
          'https://world.openfoodfacts.org/api/v2/product/$code.json',
          options: Options(
            sendTimeout: const Duration(milliseconds: 3500),
            receiveTimeout: const Duration(milliseconds: 3500),
          ),
        );

        final payload = Map<String, dynamic>.from(response.data as Map);
        if (payload['status'] == 1 && payload['product'] is Map) {
          candidate = _OffCandidate.fromProduct(Map<String, dynamic>.from(payload['product'] as Map));
        } else {
          candidate = null;
        }

        _offCache[code] = candidate;
        if (candidate != null) {
          _offCache[barcode] = candidate;
          break;
        }
      }

      if (_requestId != requestId) {
        return;
      }

      if (candidate == null) {
        setState(() {
          _errorMessage = 'Aucun resultat sur Open Food Facts pour cet EAN.';
        });
        _notifyAddSheetRefresh();
        return;
      }

      final selection = _FoodSearchItem(
        id: null,
        barcode: candidate.barcode.isNotEmpty ? candidate.barcode : barcode,
        name: candidate.name,
        brand: _hasText(candidate.brand) ? candidate.brand : null,
      );

      setState(() {
        _offCandidate = candidate;
        _searchResults = [selection];
        _selectedFood = selection;
        _errorMessage = '';
        _successMessage = 'Trouve sur Open Food Facts et ajoute dans Selection.';
      });
      _notifyAddSheetRefresh();
    } catch (_) {
      if (_requestId != requestId) {
        return;
      }
      setState(() {
        _errorMessage = 'Recherche Open Food Facts impossible pour le moment.';
      });
      _notifyAddSheetRefresh();
    } finally {
      if (_requestId == requestId && mounted) {
        setState(() {
          _enriching = false;
        });
        _notifyAddSheetRefresh();
      }
    }
  }

  Future<void> _handleAddToStock() async {
    final token = await _storage.read(key: 'token');

    if (token == null || _selectedFood == null) {
      setState(() {
        _errorMessage = 'Selectionne un aliment et reconnecte-toi.';
      });
      _notifyAddSheetRefresh();
      return;
    }

    final parsedQuantity = num.tryParse(_quantityController.text.trim());
    if (parsedQuantity == null || parsedQuantity <= 0) {
      setState(() {
        _errorMessage = 'La quantite doit etre superieure a 0.';
      });
      _notifyAddSheetRefresh();
      return;
    }

    setState(() {
      _saving = true;
      _errorMessage = '';
      _successMessage = '';
    });
    _notifyAddSheetRefresh();

    try {
      await _dio.post(
        '/stocks/items',
        options: Options(headers: {'Authorization': 'Bearer $token'}),
        data: {
          'stock_id': _selectedLocationId,
          if (_selectedFood!.id != null)
            'food_id': _selectedFood!.id
          else ...{
            'food_name': _selectedFood!.name,
            'food_barcode': _hasText(_selectedFood!.barcode) ? _selectedFood!.barcode : null,
            'food_brand': _selectedFood!.brand,
          },
          'quantity': parsedQuantity,
          'unit': _unitController.text.trim().isEmpty ? 'unite' : _unitController.text.trim(),
          'expires_at': _expiresAtController.text.trim().isEmpty ? null : _expiresAtController.text.trim(),
        },
      );

      setState(() {
        _successMessage = 'Aliment ajoute au stock.';
        _queryController.clear();
        _searchResults = [];
        _offCandidate = null;
        _selectedFood = null;
        _quantityController.text = '1';
        _unitController.text = 'unite';
        _expiresAtController.clear();
      });
      _notifyAddSheetRefresh();

      await _loadStock();
    } on DioException catch (e) {
      setState(() {
        _errorMessage = _extractMessage(e.response?.data, 'Ajout au stock impossible.');
      });
      _notifyAddSheetRefresh();
    } finally {
      if (mounted) {
        setState(() {
          _saving = false;
        });
        _notifyAddSheetRefresh();
      }
    }
  }

  Future<void> _handleCreateLocation() async {
    final token = await _storage.read(key: 'token');
    final name = _newLocationController.text.trim();

    if (token == null || name.isEmpty) {
      setState(() {
        _errorMessage = 'Saisis un nom de lieu valide.';
      });
      return;
    }

    setState(() {
      _creatingLocation = true;
      _errorMessage = '';
      _successMessage = '';
    });

    try {
      final response = await _dio.post(
        '/stocks',
        options: Options(headers: {'Authorization': 'Bearer $token'}),
        data: {'name': name},
      );

      final payload = Map<String, dynamic>.from(response.data as Map);
      if (payload['data'] is! Map) {
        throw Exception('Creation du lieu impossible.');
      }

      final created = _StockLocation.fromJson(Map<String, dynamic>.from(payload['data'] as Map));

      setState(() {
        _locations = [..._locations, created]..sort((a, b) => a.name.compareTo(b.name));
        _selectedLocationId = created.id;
        _activeLocationId = created.id;
        _newLocationController.clear();
        _successMessage = 'Lieu de stock ajoute.';
      });
    } on DioException catch (e) {
      setState(() {
        _errorMessage = _extractMessage(e.response?.data, 'Creation du lieu impossible.');
      });
    } finally {
      if (mounted) {
        setState(() {
          _creatingLocation = false;
        });
      }
    }
  }

  Future<void> _handleUpdateItem(int itemId) async {
    final token = await _storage.read(key: 'token');
    final draft = _drafts[itemId];

    if (token == null || draft == null) {
      return;
    }

    final parsedQuantity = num.tryParse(draft.quantity.trim());
    if (parsedQuantity == null || parsedQuantity <= 0) {
      setState(() {
        _errorMessage = 'La quantite doit etre superieure a 0.';
      });
      return;
    }

    setState(() {
      _savingItemId = itemId;
      _errorMessage = '';
      _successMessage = '';
    });

    try {
      await _dio.put(
        '/stocks/items/$itemId',
        options: Options(headers: {'Authorization': 'Bearer $token'}),
        data: {
          'quantity': parsedQuantity,
          'unit': draft.unit.trim().isEmpty ? 'unite' : draft.unit.trim(),
          'expires_at': draft.expiresAt.trim().isEmpty ? null : draft.expiresAt.trim(),
        },
      );

      setState(() {
        _successMessage = 'Element du stock mis a jour.';
      });
      await _loadStock();
    } on DioException catch (e) {
      setState(() {
        _errorMessage = _extractMessage(e.response?.data, 'Mise a jour impossible.');
      });
    } finally {
      if (mounted) {
        setState(() {
          _savingItemId = null;
        });
      }
    }
  }

  Future<void> _pickItemExpiryDate(int itemId) async {
    final current = _drafts[itemId];
    if (current == null) return;

    final parsed = DateTime.tryParse(current.expiresAt.trim());
    final now = DateTime.now();
    final initialDate = parsed ?? now;

    final picked = await showDatePicker(
      context: context,
      initialDate: initialDate,
      firstDate: DateTime(now.year - 1),
      lastDate: DateTime(now.year + 10),
    );

    if (picked == null) return;

    setState(() {
      _drafts[itemId] = current.copyWith(expiresAt: picked.toIso8601String().split('T').first);
    });
  }

  Future<void> _handleDeleteItem(int itemId) async {
    final token = await _storage.read(key: 'token');
    if (token == null) return;

    setState(() {
      _savingItemId = itemId;
      _errorMessage = '';
      _successMessage = '';
    });

    try {
      await _dio.delete(
        '/stocks/items/$itemId',
        options: Options(headers: {'Authorization': 'Bearer $token'}),
      );

      setState(() {
        _successMessage = 'Element supprime du stock.';
      });
      await _loadStock();
    } on DioException catch (e) {
      setState(() {
        _errorMessage = _extractMessage(e.response?.data, 'Suppression impossible.');
      });
    } finally {
      if (mounted) {
        setState(() {
          _savingItemId = null;
        });
      }
    }
  }

  List<_StockItem> get _sortedItems {
    final sorted = [..._items]
      ..sort((a, b) {
        if (a.expiresAt == null && b.expiresAt == null) return 0;
        if (a.expiresAt == null) return 1;
        if (b.expiresAt == null) return -1;
        return a.expiresAt!.compareTo(b.expiresAt!);
      });

    return sorted;
  }

  List<_StockItem> get _filteredItems {
    if (_activeLocationId == null) {
      return _sortedItems;
    }

    return _sortedItems.where((item) => item.stockId == _activeLocationId).toList();
  }

  String get _activeLocationName {
    if (_activeLocationId == null) return 'Tous';
    return _locations.firstWhere(
      (loc) => loc.id == _activeLocationId,
      orElse: () => const _StockLocation(id: -1, name: 'Lieu'),
    ).name;
  }

  int _getLocationCount(int locationId) {
    return _items.where((item) => item.stockId == locationId).length;
  }

  Future<void> _openAddFoodSheet() async {
    setState(() {
      _addFormVisible = true;
      _errorMessage = '';
      _successMessage = '';
    });

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (sheetContext) {
        final height = (MediaQuery.sizeOf(sheetContext).height * 0.92).clamp(520.0, 900.0);
        return Padding(
          padding: const EdgeInsets.fromLTRB(10, 10, 10, 16),
          child: SizedBox(
            height: height,
            child: ValueListenableBuilder<int>(
              valueListenable: _addSheetRefresh,
              builder: (context, refreshValue, child) {
                return SingleChildScrollView(
                  child: _buildAddPanel(),
                );
              },
            ),
          ),
        );
      },
    );

    if (!mounted) return;
    setState(() {
      _addFormVisible = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.viewPaddingOf(context).bottom;

    return Stack(
      children: [
        RefreshIndicator(
          onRefresh: _loadStock,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 96),
            children: [
              _buildListPanel(),
            ],
          ),
        ),
        Positioned(
          right: 16,
          bottom: bottomInset + 18,
          child: FloatingActionButton(
            onPressed: _openAddFoodSheet,
            tooltip: 'Ajouter un aliment',
            child: const Icon(Icons.add),
          ),
        ),
      ],
    );
  }

  Widget _buildAddPanel() {
    final panelWidth = MediaQuery.sizeOf(context).width;
    final compactHeader = panelWidth < 420;

    return Container(
      padding: EdgeInsets.all(compactHeader ? 14 : 16),
      decoration: BoxDecoration(
        color: const Color(0xFFF7FEE7),
        borderRadius: BorderRadius.circular(22),
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
            'Stock',
            style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: 1.2, color: Color(0xFF4D7C0F)),
          ),
          SizedBox(height: compactHeader ? 2 : 4),
          Text(
            'Ajouter des aliments',
            style: TextStyle(
              fontSize: compactHeader ? 22 : 24,
              fontWeight: FontWeight.w800,
              color: const Color(0xFF020617),
              height: 1.1,
            ),
          ),
          SizedBox(height: compactHeader ? 4 : 6),
          Text(
            'Recherche un aliment puis ajoute-le dans le lieu de ton choix avec sa date de peremption.',
            style: TextStyle(
              color: const Color(0xFF475569),
              height: 1.35,
              fontSize: compactHeader ? 14 : 15,
            ),
          ),
          SizedBox(height: compactHeader ? 10 : 12),
          if (!_addFormVisible)
            FilledButton.icon(
              onPressed: () {
                setState(() {
                  _addFormVisible = true;
                  _errorMessage = '';
                  _successMessage = '';
                });
              },
              icon: const Icon(Icons.add_circle_outline),
              label: const Text('Ajouter un aliment'),
            )
          else ...[
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                onPressed: _saving
                    ? null
                    : () {
                        Navigator.of(context).pop();
                      },
                icon: const Icon(Icons.close),
                label: const Text('Fermer'),
              ),
            ),
            const SizedBox(height: 6),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(18),
                border: Border.all(color: const Color(0xFFE2E8F0)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                const Text('Rechercher un aliment', style: TextStyle(fontWeight: FontWeight.w600, color: Color(0xFF334155))),
                const SizedBox(height: 10),
                LayoutBuilder(
                  builder: (context, constraints) {
                    final isCompact = constraints.maxWidth < 520;

                    final textField = TextField(
                      controller: _queryController,
                      decoration: const InputDecoration(hintText: 'Nom, marque ou EAN'),
                    );

                    final searchButton = ElevatedButton(
                      onPressed: _searchLoading ? null : _handleSearch,
                      child: Text(_searchLoading ? 'Recherche...' : 'Rechercher'),
                    );

                    final scannerButton = OutlinedButton.icon(
                      onPressed: _searchLoading ? null : _openBarcodeScannerAndSearch,
                      icon: const Icon(Icons.qr_code_scanner_rounded),
                      label: const Text('Scanner'),
                    );

                    if (isCompact) {
                      return Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          textField,
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
                        Expanded(child: textField),
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
                      'Verification Open Food Facts en arriere-plan...',
                      style: TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                    ),
                  ),
                if (_offCandidate != null)
                  Container(
                    margin: const EdgeInsets.only(top: 10),
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: const Color(0xFFECFEFF),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFFBAE6FD)),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Trouve sur Open Food Facts', style: TextStyle(fontWeight: FontWeight.w700, color: Color(0xFF0C4A6E))),
                        const SizedBox(height: 2),
                        Text(
                          '${_offCandidate!.name}${_hasText(_offCandidate!.brand) ? ' · ${_offCandidate!.brand}' : ''} · EAN ${_offCandidate!.barcode}',
                          style: const TextStyle(color: Color(0xFF075985)),
                        ),
                      ],
                    ),
                  ),
                const SizedBox(height: 10),
                Container(
                  constraints: const BoxConstraints(maxHeight: 220),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFFE2E8F0)),
                  ),
                  child: _searchResults.isEmpty
                      ? const Padding(
                          padding: EdgeInsets.all(12),
                          child: Text('Aucun resultat pour le moment.', style: TextStyle(color: Color(0xFF64748B))),
                        )
                      : ListView.separated(
                          shrinkWrap: true,
                          itemCount: _searchResults.length,
                          separatorBuilder: (context, index) => const Divider(height: 1),
                          itemBuilder: (context, index) {
                            final food = _searchResults[index];
                            final selected = _selectedFood?.id == food.id && _selectedFood?.barcode == food.barcode;

                            return Material(
                              color: selected ? const Color(0xFFECFCCB) : Colors.white,
                              child: InkWell(
                                onTap: () {
                                  setState(() => _selectedFood = food);
                                  _notifyAddSheetRefresh();
                                },
                                child: Padding(
                                  padding: const EdgeInsets.all(10),
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(food.name, style: const TextStyle(fontWeight: FontWeight.w600, color: Color(0xFF0F172A))),
                                      const SizedBox(height: 2),
                                      Text(
                                        '${_hasText(food.brand) ? '${food.brand} · ' : ''}EAN: ${food.barcode}',
                                        style: const TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            );
                          },
                        ),
                ),
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFFE2E8F0)),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Selection', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Color(0xFF64748B))),
                      const SizedBox(height: 4),
                      Text(
                        _selectedFood != null
                            ? '${_selectedFood!.name} (EAN ${_selectedFood!.barcode})'
                            : 'Aucun aliment selectionne',
                        style: const TextStyle(color: Color(0xFF0F172A)),
                      ),
                      const SizedBox(height: 10),
                      LayoutBuilder(
                        builder: (context, constraints) {
                          final isCompact = constraints.maxWidth < 380;
                          final firstWidth = isCompact ? constraints.maxWidth : constraints.maxWidth * 0.55;
                          final secondWidth = isCompact ? constraints.maxWidth : constraints.maxWidth * 0.28;

                          return Wrap(
                            spacing: 10,
                            runSpacing: 10,
                            children: [
                              SizedBox(
                                width: firstWidth,
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Text('Lieu de stock', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xFF64748B))),
                                    const SizedBox(height: 4),
                                    DropdownButtonFormField<int>(
                                      initialValue: _selectedLocationId,
                                      decoration: const InputDecoration(isDense: true),
                                      items: _locations
                                          .map(
                                            (location) => DropdownMenuItem<int>(
                                              value: location.id,
                                              child: Text(location.name, overflow: TextOverflow.ellipsis),
                                            ),
                                          )
                                          .toList(),
                                      onChanged: (value) {
                                        setState(() => _selectedLocationId = value);
                                        _notifyAddSheetRefresh();
                                      },
                                    ),
                                  ],
                                ),
                              ),
                              SizedBox(
                                width: secondWidth,
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Text('Nouveau lieu', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xFF64748B))),
                                    const SizedBox(height: 4),
                                    TextField(
                                      controller: _newLocationController,
                                      decoration: const InputDecoration(isDense: true, hintText: 'Ex: Placard'),
                                    ),
                                  ],
                                ),
                              ),
                              SizedBox(
                                width: isCompact ? constraints.maxWidth : null,
                                child: Align(
                                  alignment: isCompact ? Alignment.centerLeft : Alignment.topLeft,
                                  child: OutlinedButton(
                                    onPressed: _creatingLocation ? null : _handleCreateLocation,
                                    child: Text(_creatingLocation ? '...' : 'Ajouter'),
                                  ),
                                ),
                              ),
                            ],
                          );
                        },
                      ),
                      const SizedBox(height: 10),
                      LayoutBuilder(
                        builder: (context, constraints) {
                          final fieldWidth = constraints.maxWidth < 420
                              ? (constraints.maxWidth - 8) / 2
                              : (constraints.maxWidth - 16) / 3;

                          return Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              SizedBox(
                                width: fieldWidth,
                                child: TextField(
                                  controller: _quantityController,
                                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                                  decoration: const InputDecoration(isDense: true, labelText: 'Quantite'),
                                ),
                              ),
                              SizedBox(
                                width: fieldWidth,
                                child: TextField(
                                  controller: _unitController,
                                  decoration: const InputDecoration(isDense: true, labelText: 'Unite'),
                                ),
                              ),
                              SizedBox(
                                width: fieldWidth,
                                child: TextField(
                                  controller: _expiresAtController,
                                  readOnly: true,
                                  decoration: const InputDecoration(isDense: true, labelText: 'Date de peremption'),
                                  onTap: () async {
                                    final now = DateTime.now();
                                    final picked = await showDatePicker(
                                      context: context,
                                      initialDate: now,
                                      firstDate: DateTime(now.year - 1),
                                      lastDate: DateTime(now.year + 10),
                                    );
                                    if (picked != null) {
                                      _expiresAtController.text = picked.toIso8601String().split('T').first;
                                    }
                                  },
                                ),
                              ),
                            ],
                          );
                        },
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 10),
                ElevatedButton(
                  onPressed: _saving || _selectedFood == null ? null : _handleAddToStock,
                  child: Text(_saving ? 'Ajout en cours...' : 'Ajouter au stock'),
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
          ],
        ],
      ),
    );
  }

  Widget _buildListPanel() {
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
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Expanded(
                child: Text(
                  'Produits dans ton stock',
                  style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: Color(0xFF020617)),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: const Color(0xFFF8FAFC),
                  borderRadius: BorderRadius.circular(999),
                  border: Border.all(color: const Color(0xFFE2E8F0)),
                ),
                child: Text('${_items.length} element(s)', style: const TextStyle(fontSize: 12, color: Color(0xFF64748B))),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _LocationFilterChip(
                label: 'Tous',
                selected: _activeLocationId == null,
                onTap: () => setState(() => _activeLocationId = null),
              ),
              ..._locations.map(
                (location) => _LocationFilterChip(
                  label: '${location.name} (${_getLocationCount(location.id)})',
                  selected: _activeLocationId == location.id,
                  onTap: () => setState(() => _activeLocationId = location.id),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text('Lieu affiche: $_activeLocationName', style: const TextStyle(fontSize: 12, color: Color(0xFF64748B))),
          const SizedBox(height: 12),
          if (_errorMessage.isNotEmpty)
            Container(
              width: double.infinity,
              margin: const EdgeInsets.only(bottom: 10),
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
              width: double.infinity,
              margin: const EdgeInsets.only(bottom: 10),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: const Color(0xFFECFDF5),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFA7F3D0)),
              ),
              child: Text(_successMessage, style: const TextStyle(color: Color(0xFF047857))),
            ),
          if (_itemsLoading)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 22),
              child: Text('Chargement du stock...', style: TextStyle(color: Color(0xFF64748B))),
            )
          else if (_filteredItems.isEmpty)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 16),
              decoration: BoxDecoration(
                color: const Color(0xFFF8FAFC),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFE2E8F0)),
              ),
              child: const Text('Aucun aliment dans ce lieu pour le moment.', style: TextStyle(color: Color(0xFF64748B))),
            )
          else
            ..._filteredItems.map(
              (item) {
                final draft = _drafts[item.id] ??
                    _ItemDraft(
                      quantity: item.quantity.toString(),
                      unit: item.unit,
                      expiresAt: item.expiresAt ?? '',
                    );

                final expiry = _expiryLabel(item.daysLeft);
                final expanded = _expandedItemIds.contains(item.id);
                return Container(
                  margin: const EdgeInsets.only(bottom: 10),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF8FAFC),
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: const Color(0xFFE2E8F0)),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      InkWell(
                        borderRadius: BorderRadius.circular(10),
                        onTap: () {
                          setState(() {
                            if (expanded) {
                              _expandedItemIds.remove(item.id);
                            } else {
                              _expandedItemIds.add(item.id);
                            }
                          });
                        },
                        child: Padding(
                          padding: const EdgeInsets.symmetric(vertical: 2),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(item.foodName ?? 'Aliment', style: const TextStyle(fontWeight: FontWeight.w700, color: Color(0xFF0F172A))),
                                    const SizedBox(height: 2),
                                    Text('Temps restant: ${expiry.text}', style: const TextStyle(fontSize: 12, color: Color(0xFF64748B))),
                                    if (expanded) ...[
                                      const SizedBox(height: 2),
                                      Text(
                                        '${item.foodBrand != null ? '${item.foodBrand} · ' : ''}EAN: ${item.foodBarcode ?? '-'}',
                                        style: const TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                                      ),
                                      Text('Lieu: ${item.stockName ?? 'Frigo'}', style: const TextStyle(fontSize: 12, color: Color(0xFF64748B))),
                                    ],
                                  ],
                                ),
                              ),
                              const SizedBox(width: 8),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                                decoration: BoxDecoration(
                                  color: expiry.bg,
                                  borderRadius: BorderRadius.circular(999),
                                  border: Border.all(color: expiry.border),
                                ),
                                child: Text(expiry.text, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: expiry.textColor)),
                              ),
                              const SizedBox(width: 4),
                              Icon(
                                expanded ? Icons.expand_less : Icons.expand_more,
                                color: const Color(0xFF64748B),
                              ),
                            ],
                          ),
                        ),
                      ),
                      if (expanded) ...[
                        const SizedBox(height: 10),
                        Row(
                          children: [
                            Expanded(
                              child: TextFormField(
                                key: ValueKey('q-${item.id}-${draft.quantity}'),
                                initialValue: draft.quantity,
                                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                                decoration: const InputDecoration(isDense: true, labelText: 'Quantite'),
                                onChanged: (value) {
                                  setState(() {
                                    _drafts[item.id] = draft.copyWith(quantity: value);
                                  });
                                },
                              ),
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: TextFormField(
                                key: ValueKey('u-${item.id}-${draft.unit}'),
                                initialValue: draft.unit,
                                decoration: const InputDecoration(isDense: true, labelText: 'Unite'),
                                onChanged: (value) {
                                  setState(() {
                                    _drafts[item.id] = draft.copyWith(unit: value);
                                  });
                                },
                              ),
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: TextFormField(
                                key: ValueKey('d-${item.id}-${draft.expiresAt}'),
                                initialValue: draft.expiresAt,
                                readOnly: true,
                                decoration: const InputDecoration(
                                  isDense: true,
                                  labelText: 'Date peremption',
                                  hintText: 'YYYY-MM-DD',
                                ),
                                onTap: () => _pickItemExpiryDate(item.id),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 10),
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            OutlinedButton(
                              onPressed: _savingItemId == item.id ? null : () => _handleUpdateItem(item.id),
                              child: const Text('Mettre a jour'),
                            ),
                            OutlinedButton(
                              onPressed: _savingItemId == item.id ? null : () => _handleDeleteItem(item.id),
                              style: OutlinedButton.styleFrom(foregroundColor: const Color(0xFFB91C1C)),
                              child: const Text('Supprimer'),
                            ),
                          ],
                        ),
                      ],
                    ],
                  ),
                );
              },
            ),
        ],
      ),
    );
  }

  _ExpiryVisual _expiryLabel(int? daysLeft) {
    if (daysLeft == null) {
      return const _ExpiryVisual(
        text: 'Sans date',
        bg: Color(0xFFF8FAFC),
        border: Color(0xFFE2E8F0),
        textColor: Color(0xFF334155),
      );
    }

    if (daysLeft < 0) {
      return const _ExpiryVisual(
        text: 'Perime',
        bg: Color(0xFFFFF1F2),
        border: Color(0xFFFECDD3),
        textColor: Color(0xFFBE123C),
      );
    }

    if (daysLeft <= 2) {
      return _ExpiryVisual(
        text: '$daysLeft j restant(s)',
        bg: const Color(0xFFFFFBEB),
        border: const Color(0xFFFDE68A),
        textColor: const Color(0xFF92400E),
      );
    }

    return _ExpiryVisual(
      text: '$daysLeft j restants',
      bg: const Color(0xFFECFDF5),
      border: const Color(0xFFA7F3D0),
      textColor: const Color(0xFF047857),
    );
  }
}

class _FoodSearchItem {
  final int? id;
  final String barcode;
  final String name;
  final String? brand;

  const _FoodSearchItem({
    required this.id,
    required this.barcode,
    required this.name,
    required this.brand,
  });
}

class _OffCandidate {
  final String barcode;
  final String name;
  final String brand;
  final String imageUrl;
  final num? calories;
  final num? fat;
  final num? carbs;
  final num? proteins;

  const _OffCandidate({
    required this.barcode,
    required this.name,
    required this.brand,
    required this.imageUrl,
    required this.calories,
    required this.fat,
    required this.carbs,
    required this.proteins,
  });

  factory _OffCandidate.fromProduct(Map<String, dynamic> product) {
    final nutriments = (product['nutriments'] is Map)
        ? Map<String, dynamic>.from(product['nutriments'] as Map)
        : <String, dynamic>{};

    final brands = product['brands']?.toString() ?? '';
    final firstBrand = brands
        .split(',')
        .map((value) => value.trim())
        .where((value) => value.isNotEmpty)
        .toList();

    final localizedNameCandidates = [
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

    return _OffCandidate(
      barcode: product['code']?.toString() ?? '',
      name: localizedNameCandidates.isNotEmpty ? localizedNameCandidates.first : 'Produit sans nom',
      brand: firstBrand.isNotEmpty ? firstBrand.first : '',
      imageUrl: product['image_front_url']?.toString() ?? product['image_url']?.toString() ?? '',
      calories: nutriments['energy_kcal_100g'] ?? nutriments['energy_100g'],
      fat: nutriments['fat_100g'] as num?,
      carbs: nutriments['carbohydrates_100g'] as num?,
      proteins: nutriments['proteins_100g'] as num?,
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

class _StockLocation {
  final int id;
  final String name;

  const _StockLocation({required this.id, required this.name});

  factory _StockLocation.fromJson(Map<String, dynamic> json) {
    return _StockLocation(
      id: json['id'] as int,
      name: json['name']?.toString() ?? 'Lieu',
    );
  }
}

class _StockItem {
  final int id;
  final int stockId;
  final String? stockName;
  final int? foodId;
  final String? foodName;
  final String? foodBarcode;
  final String? foodBrand;
  final num quantity;
  final String unit;
  final String? expiresAt;
  final int? daysLeft;

  const _StockItem({
    required this.id,
    required this.stockId,
    required this.stockName,
    required this.foodId,
    required this.foodName,
    required this.foodBarcode,
    required this.foodBrand,
    required this.quantity,
    required this.unit,
    required this.expiresAt,
    required this.daysLeft,
  });

  factory _StockItem.fromJson(Map<String, dynamic> json) {
    return _StockItem(
      id: json['id'] as int,
      stockId: json['stock_id'] as int,
      stockName: json['stock_name']?.toString(),
      foodId: json['food_id'] as int?,
      foodName: json['food_name']?.toString(),
      foodBarcode: json['food_barcode']?.toString(),
      foodBrand: json['food_brand']?.toString(),
      quantity: json['quantity'] as num? ?? 1,
      unit: json['unit']?.toString() ?? 'unite',
      expiresAt: json['expires_at']?.toString(),
      daysLeft: json['days_left'] as int?,
    );
  }
}

class _ItemDraft {
  final String quantity;
  final String unit;
  final String expiresAt;

  const _ItemDraft({required this.quantity, required this.unit, required this.expiresAt});

  _ItemDraft copyWith({String? quantity, String? unit, String? expiresAt}) {
    return _ItemDraft(
      quantity: quantity ?? this.quantity,
      unit: unit ?? this.unit,
      expiresAt: expiresAt ?? this.expiresAt,
    );
  }
}

class _LocationFilterChip extends StatelessWidget {
  final String label;
  final bool selected;
  final VoidCallback onTap;

  const _LocationFilterChip({required this.label, required this.selected, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return ActionChip(
      label: Text(label),
      onPressed: onTap,
      backgroundColor: selected ? const Color(0xFF65A30D) : Colors.white,
      labelStyle: TextStyle(
        color: selected ? Colors.white : const Color(0xFF475569),
        fontWeight: FontWeight.w600,
      ),
      side: BorderSide(color: selected ? const Color(0xFF65A30D) : const Color(0xFFE2E8F0)),
    );
  }
}

class _ExpiryVisual {
  final String text;
  final Color bg;
  final Color border;
  final Color textColor;

  const _ExpiryVisual({
    required this.text,
    required this.bg,
    required this.border,
    required this.textColor,
  });
}
