import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class ProfileScreen extends StatefulWidget {
  final String userName;

  const ProfileScreen({super.key, required this.userName});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  static const _storage = FlutterSecureStorage();
  static const _baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api',
  );

  static const _activityFactors = {
    'sedentaire': 1.2,
    'leger': 1.375,
    'modere': 1.55,
    'eleve': 1.725,
    'tres_eleve': 1.9,
  };

  final Dio _dio = Dio(
    BaseOptions(
      baseUrl: _baseUrl,
      headers: const {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    ),
  );

  final _nomController = TextEditingController();
  final _ageController = TextEditingController();
  final _tailleController = TextEditingController();
  final _poidsActuelController = TextEditingController();
  final _poidsSouhaiteController = TextEditingController();
  final _delaiJoursController = TextEditingController();

  String _sexe = '';
  String _niveauActivite = '';
  String _objectifType = '';
  String _regimeAlimentaire = '';

  bool _loading = true;
  bool _saving = false;
  String? _errorMessage;
  String? _successMessage;
  Map<String, String> _fieldErrors = {};
  _CalculationResult? _calculation;

  @override
  void initState() {
    super.initState();
    _nomController.text = widget.userName;
    _loadProfile();
  }

  @override
  void dispose() {
    _nomController.dispose();
    _ageController.dispose();
    _tailleController.dispose();
    _poidsActuelController.dispose();
    _poidsSouhaiteController.dispose();
    _delaiJoursController.dispose();
    super.dispose();
  }

  Future<void> _loadProfile() async {
    setState(() {
      _loading = true;
      _errorMessage = null;
      _successMessage = null;
    });

    try {
      final token = await _storage.read(key: 'token');
      if (token == null) {
        throw Exception('Session invalide. Reconnecte-toi.');
      }

      final response = await _dio.get(
        '/profile',
        options: Options(headers: {'Authorization': 'Bearer $token'}),
      );

      final data = Map<String, dynamic>.from(response.data as Map);

      _nomController.text = (data['nom']?.toString().trim().isNotEmpty ?? false)
          ? data['nom'].toString()
          : widget.userName;
      _ageController.text = _toText(data['age']);
      _tailleController.text = _toText(data['taille']);
      _poidsActuelController.text = _toText(data['poids']);
      _poidsSouhaiteController.text = _toText(data['poids_souhaite_kg']);
      _delaiJoursController.text = _toText(data['delai_objectif_jours']);
      _sexe = data['sexe']?.toString() ?? '';
      _niveauActivite = data['niveau_activite']?.toString() ?? '';
      _objectifType = data['objectif_type']?.toString() ?? '';
      _regimeAlimentaire = data['regime_alimentaire']?.toString() ?? '';

      if (_isFormComplete()) {
        _calculation = _calculateResult();
      }
    } on DioException catch (e) {
      _errorMessage = _extractMessage(e.response?.data, 'Impossible de charger le profil.');
    } catch (e) {
      _errorMessage = e.toString();
    } finally {
      if (mounted) {
        setState(() {
          _loading = false;
        });
      }
    }
  }

  String _toText(dynamic value) {
    if (value == null) return '';
    if (value is double) {
      return value % 1 == 0 ? value.toInt().toString() : value.toString();
    }
    return value.toString();
  }

  bool _isFormComplete() {
    return _nomController.text.trim().isNotEmpty &&
        _sexe.isNotEmpty &&
        _ageController.text.trim().isNotEmpty &&
        _tailleController.text.trim().isNotEmpty &&
        _poidsActuelController.text.trim().isNotEmpty &&
        _poidsSouhaiteController.text.trim().isNotEmpty &&
        _delaiJoursController.text.trim().isNotEmpty &&
        _niveauActivite.isNotEmpty &&
        _objectifType.isNotEmpty &&
        _regimeAlimentaire.isNotEmpty;
  }

  double? _toNumber(String value) {
    final parsed = double.tryParse(value.trim());
    return parsed;
  }

  Map<String, String> _validateForm() {
    final errors = <String, String>{};

    if (_nomController.text.trim().isEmpty) {
      errors['nom'] = 'Le nom est obligatoire.';
    }

    if (_sexe.isEmpty) {
      errors['sexe'] = 'Le sexe est obligatoire.';
    }

    final age = _toNumber(_ageController.text);
    if (age == null || age < 12 || age > 120) {
      errors['age'] = 'L age doit etre compris entre 12 et 120 ans.';
    }

    final taille = _toNumber(_tailleController.text);
    if (taille == null || taille < 100 || taille > 250) {
      errors['taille'] = 'La taille doit etre comprise entre 100 et 250 cm.';
    }

    final poidsActuel = _toNumber(_poidsActuelController.text);
    if (poidsActuel == null || poidsActuel < 20 || poidsActuel > 500) {
      errors['poids_actuel'] = 'Le poids actuel doit etre compris entre 20 et 500 kg.';
    }

    final poidsSouhaite = _toNumber(_poidsSouhaiteController.text);
    if (poidsSouhaite == null || poidsSouhaite < 20 || poidsSouhaite > 500) {
      errors['poids_souhaite'] = 'Le poids souhaite doit etre compris entre 20 et 500 kg.';
    }

    final delai = _toNumber(_delaiJoursController.text);
    if (delai == null || delai < 1 || delai > 2000) {
      errors['delai'] = 'Le nombre de jours doit etre compris entre 1 et 2000.';
    }

    if (_niveauActivite.isEmpty || !_activityFactors.containsKey(_niveauActivite)) {
      errors['niveau_activite'] = 'Le niveau d activite est obligatoire.';
    }

    if (!['perdre', 'maintenir', 'prendre'].contains(_objectifType)) {
      errors['objectif_type'] = 'L objectif est obligatoire.';
    }

    if (_regimeAlimentaire.isEmpty) {
      errors['regime_alimentaire'] = 'Le regime alimentaire est obligatoire.';
    }

    return errors;
  }

  _CalculationResult _calculateResult() {
    final age = _toNumber(_ageController.text)!;
    final taille = _toNumber(_tailleController.text)!;
    final poidsActuel = _toNumber(_poidsActuelController.text)!;
    final poidsSouhaite = _toNumber(_poidsSouhaiteController.text)!;
    final delaiJours = _toNumber(_delaiJoursController.text)!;

    final bmr = _sexe == 'homme'
        ? 10 * poidsActuel + 6.25 * taille - 5 * age + 5
        : 10 * poidsActuel + 6.25 * taille - 5 * age - 161;

    final maintenanceCalories = bmr * _activityFactors[_niveauActivite]!;
    final variationKg = poidsSouhaite - poidsActuel;
    final adjustmentPerDay = (variationKg * 7700) / delaiJours;

    final floorCalories = _sexe == 'homme' ? 1400 : 1200;
    const maxDailyChange = 1100.0;

    String? warning;

    final adjustmentClamped = adjustmentPerDay.clamp(-maxDailyChange, maxDailyChange);
    if (adjustmentPerDay != adjustmentClamped) {
      warning = 'Objectif tres agressif: ajustement calorique limite pour proteger ta sante.';
    }

    final weeklyVariationKg = (variationKg / delaiJours) * 7;
    if (warning == null && weeklyVariationKg.abs() > 1) {
      warning = 'Objectif potentiellement irrealiste: variation > 1 kg/semaine.';
    }

    var targetCalories = maintenanceCalories + adjustmentClamped;
    if (targetCalories < floorCalories) {
      targetCalories = floorCalories.toDouble();
      warning = 'Les calories cibles ont ete remontees au seuil minimal de securite.';
    }

    final proteinsGrams = (poidsActuel * 1.6).round();
    final fatsGrams = ((targetCalories * 0.28) / 9).round();
    final carbsGrams = ((targetCalories - proteinsGrams * 4 - fatsGrams * 9) / 4).round().clamp(0, 10000);

    final summary = _objectifType == 'maintenir'
        ? 'Objectif maintien: stabiliser ton poids actuel.'
        : _objectifType == 'perdre'
            ? 'Objectif perte: passer de ${poidsActuel.toStringAsFixed(1)} kg a ${poidsSouhaite.toStringAsFixed(1)} kg en ${delaiJours.toInt()} jours.'
            : 'Objectif prise: passer de ${poidsActuel.toStringAsFixed(1)} kg a ${poidsSouhaite.toStringAsFixed(1)} kg en ${delaiJours.toInt()} jours.';

    const explanation =
        'Le calcul combine ton metabolisme de base, ton activite physique et ton delai cible. Les macros sont reparties automatiquement.';

    return _CalculationResult(
      bmr: bmr.round(),
      maintenanceCalories: maintenanceCalories.round(),
      targetCalories: targetCalories.round(),
      proteinsGrams: proteinsGrams,
      fatsGrams: fatsGrams,
      carbsGrams: carbsGrams,
      adjustmentPerDay: adjustmentClamped.round(),
      weeklyVariationKg: double.parse(weeklyVariationKg.toStringAsFixed(2)),
      variationKg: double.parse(variationKg.toStringAsFixed(2)),
      floorCalories: floorCalories,
      warning: warning,
      summary: summary,
      explanation: explanation,
    );
  }

  void _handleCalculate() {
    setState(() {
      _fieldErrors = _validateForm();
      _errorMessage = null;
      _successMessage = null;
    });

    if (_fieldErrors.isNotEmpty) {
      return;
    }

    setState(() {
      _calculation = _calculateResult();
    });
  }

  void _handleReset() {
    setState(() {
      _nomController.text = widget.userName;
      _ageController.clear();
      _tailleController.clear();
      _poidsActuelController.clear();
      _poidsSouhaiteController.clear();
      _delaiJoursController.clear();
      _sexe = '';
      _niveauActivite = '';
      _objectifType = '';
      _regimeAlimentaire = '';
      _fieldErrors = {};
      _errorMessage = null;
      _successMessage = null;
      _calculation = null;
    });
  }

  String _extractMessage(dynamic data, String fallback) {
    if (data is Map) {
      final message = data['message'];
      if (message != null && message.toString().trim().isNotEmpty) {
        return message.toString();
      }

      final errors = data['errors'];
      if (errors is Map) {
        final messages = <String>[];
        for (final value in errors.values) {
          if (value is Iterable) {
            messages.addAll(value.map((item) => item.toString()));
          } else if (value != null) {
            messages.add(value.toString());
          }
        }
        if (messages.isNotEmpty) {
          return messages.join(' ');
        }
      }
    }

    return fallback;
  }

  Future<void> _handleSave() async {
    setState(() {
      _fieldErrors = _validateForm();
      _errorMessage = null;
      _successMessage = null;
    });

    if (_fieldErrors.isNotEmpty) {
      return;
    }

    final computed = _calculation ?? _calculateResult();

    final token = await _storage.read(key: 'token');
    if (token == null) {
      setState(() {
        _errorMessage = 'Session invalide. Reconnecte-toi.';
      });
      return;
    }

    setState(() {
      _saving = true;
    });

    try {
      final body = {
        'nom': _nomController.text.trim(),
        'sexe': _sexe,
        'age': _toNumber(_ageController.text)!.toInt(),
        'taille': _toNumber(_tailleController.text),
        'poids': _toNumber(_poidsActuelController.text),
        'poids_souhaite_kg': _toNumber(_poidsSouhaiteController.text),
        'delai_objectif_jours': _toNumber(_delaiJoursController.text)!.toInt(),
        'niveau_activite': _niveauActivite,
        'objectif_type': _objectifType,
        'objectif': _objectifType,
        'regime_alimentaire': _regimeAlimentaire,
        'calories_cibles': computed.targetCalories,
        'proteines_cibles': computed.proteinsGrams,
        'glucides_cibles': computed.carbsGrams,
        'lipides_cibles': computed.fatsGrams,
      };

      await _dio.put(
        '/profile',
        data: body,
        options: Options(headers: {'Authorization': 'Bearer $token'}),
      );

      setState(() {
        _calculation = computed;
        _successMessage = 'Profil et calcul nutritionnel enregistres avec succes.';
      });
    } on DioException catch (e) {
      setState(() {
        _errorMessage = _extractMessage(e.response?.data, 'Impossible d enregistrer le profil.');
      });
    } catch (e) {
      setState(() {
        _errorMessage = e.toString();
      });
    } finally {
      if (mounted) {
        setState(() {
          _saving = false;
        });
      }
    }
  }

  Widget _textField({
    required String label,
    required TextEditingController controller,
    TextInputType keyboardType = TextInputType.text,
    String? errorKey,
  }) {
    return _FieldWrap(
      label: label,
      error: errorKey == null ? null : _fieldErrors[errorKey],
      child: TextField(
        controller: controller,
        keyboardType: keyboardType,
        decoration: const InputDecoration(),
      ),
    );
  }

  Widget _dropdownField({
    required String label,
    required String value,
    required List<DropdownMenuItem<String>> items,
    required ValueChanged<String?> onChanged,
    String? errorKey,
  }) {
    return _FieldWrap(
      label: label,
      error: errorKey == null ? null : _fieldErrors[errorKey],
      child: DropdownButtonFormField<String>(
        initialValue: value.isEmpty ? null : value,
        items: items,
        onChanged: onChanged,
        decoration: const InputDecoration(),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
      children: [
        Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(28),
            border: Border.all(color: const Color(0xFFD9EBD9)),
            boxShadow: const [
              BoxShadow(
                color: Color(0x140F172A),
                blurRadius: 20,
                offset: Offset(0, 12),
              ),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Calculateur nutritionnel',
                style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: Color(0xFF020617)),
              ),
              const SizedBox(height: 6),
              const Text(
                'Renseigne ton profil, calcule tes cibles, puis sauvegarde les resultats.',
                style: TextStyle(color: Color(0xFF475569), height: 1.4),
              ),
              if (_loading)
                const Padding(
                  padding: EdgeInsets.only(top: 10),
                  child: Text('Chargement du profil...', style: TextStyle(fontSize: 12, color: Color(0xFF64748B))),
                ),
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: const Color(0xFFF0FDF4),
                  borderRadius: BorderRadius.circular(24),
                  border: Border.all(color: const Color(0xFFD9EBD9)),
                ),
                child: LayoutBuilder(
                  builder: (context, constraints) {
                    final columns = constraints.maxWidth >= 720 ? 2 : 1;
                    final itemWidth = columns == 2 ? (constraints.maxWidth - 12) / 2 : constraints.maxWidth;

                    return Wrap(
                      spacing: 12,
                      runSpacing: 12,
                      children: [
                        SizedBox(
                          width: itemWidth,
                          child: _textField(label: 'Nom', controller: _nomController, errorKey: 'nom'),
                        ),
                        SizedBox(
                          width: itemWidth,
                          child: _dropdownField(
                            label: 'Sexe',
                            value: _sexe,
                            errorKey: 'sexe',
                            onChanged: (value) => setState(() => _sexe = value ?? ''),
                            items: const [
                              DropdownMenuItem(value: 'homme', child: Text('Homme')),
                              DropdownMenuItem(value: 'femme', child: Text('Femme')),
                            ],
                          ),
                        ),
                        SizedBox(
                          width: itemWidth,
                          child: _textField(
                            label: 'Age',
                            controller: _ageController,
                            keyboardType: TextInputType.number,
                            errorKey: 'age',
                          ),
                        ),
                        SizedBox(
                          width: itemWidth,
                          child: _textField(
                            label: 'Taille (cm)',
                            controller: _tailleController,
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            errorKey: 'taille',
                          ),
                        ),
                        SizedBox(
                          width: itemWidth,
                          child: _textField(
                            label: 'Poids actuel (kg)',
                            controller: _poidsActuelController,
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            errorKey: 'poids_actuel',
                          ),
                        ),
                        SizedBox(
                          width: itemWidth,
                          child: _textField(
                            label: 'Poids souhaite (kg)',
                            controller: _poidsSouhaiteController,
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            errorKey: 'poids_souhaite',
                          ),
                        ),
                        SizedBox(
                          width: itemWidth,
                          child: _textField(
                            label: 'Nombre de jours',
                            controller: _delaiJoursController,
                            keyboardType: TextInputType.number,
                            errorKey: 'delai',
                          ),
                        ),
                        SizedBox(
                          width: itemWidth,
                          child: _dropdownField(
                            label: 'Niveau d activite',
                            value: _niveauActivite,
                            errorKey: 'niveau_activite',
                            onChanged: (value) => setState(() => _niveauActivite = value ?? ''),
                            items: const [
                              DropdownMenuItem(value: 'sedentaire', child: Text('Sedentaire')),
                              DropdownMenuItem(value: 'leger', child: Text('Leger')),
                              DropdownMenuItem(value: 'modere', child: Text('Modere')),
                              DropdownMenuItem(value: 'eleve', child: Text('Eleve')),
                              DropdownMenuItem(value: 'tres_eleve', child: Text('Tres eleve')),
                            ],
                          ),
                        ),
                        SizedBox(
                          width: itemWidth,
                          child: _dropdownField(
                            label: 'Objectif',
                            value: _objectifType,
                            errorKey: 'objectif_type',
                            onChanged: (value) => setState(() => _objectifType = value ?? ''),
                            items: const [
                              DropdownMenuItem(value: 'perdre', child: Text('Perdre du poids')),
                              DropdownMenuItem(value: 'maintenir', child: Text('Maintenir')),
                              DropdownMenuItem(value: 'prendre', child: Text('Prendre du poids')),
                            ],
                          ),
                        ),
                        SizedBox(
                          width: itemWidth,
                          child: _dropdownField(
                            label: 'Regime alimentaire',
                            value: _regimeAlimentaire,
                            errorKey: 'regime_alimentaire',
                            onChanged: (value) => setState(() => _regimeAlimentaire = value ?? ''),
                            items: const [
                              DropdownMenuItem(value: 'omnivore', child: Text('Omnivore')),
                              DropdownMenuItem(value: 'vegetarien', child: Text('Vegetarien')),
                              DropdownMenuItem(value: 'vegan', child: Text('Vegan')),
                              DropdownMenuItem(value: 'keto', child: Text('Keto')),
                              DropdownMenuItem(value: 'low_carb', child: Text('Low carb')),
                              DropdownMenuItem(value: 'mediterraneen', child: Text('Mediterraneen')),
                              DropdownMenuItem(value: 'halal', child: Text('Halal')),
                              DropdownMenuItem(value: 'sans_gluten', child: Text('Sans gluten')),
                              DropdownMenuItem(value: 'autre', child: Text('Autre')),
                            ],
                          ),
                        ),
                      ],
                    );
                  },
                ),
              ),
              const SizedBox(height: 14),
              Wrap(
                spacing: 10,
                runSpacing: 10,
                children: [
                  ElevatedButton(
                    onPressed: _loading ? null : _handleCalculate,
                    child: const Text('Calculer'),
                  ),
                  OutlinedButton(
                    onPressed: _loading ? null : _handleReset,
                    child: const Text('Reinitialiser'),
                  ),
                  FilledButton.tonal(
                    onPressed: _loading || _saving ? null : _handleSave,
                    child: Text(_saving ? 'Enregistrement...' : 'Enregistrer'),
                  ),
                ],
              ),
              if (_errorMessage != null)
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: Text(
                    _errorMessage!,
                    style: const TextStyle(color: Color(0xFFBE123C), fontWeight: FontWeight.w600),
                  ),
                ),
              if (_successMessage != null)
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: Text(
                    _successMessage!,
                    style: const TextStyle(color: Color(0xFF047857), fontWeight: FontWeight.w600),
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        if (_calculation != null) _ResultPanel(result: _calculation!),
      ],
    );
  }
}

class _FieldWrap extends StatelessWidget {
  final String label;
  final String? error;
  final Widget child;

  const _FieldWrap({required this.label, required this.child, this.error});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: Color(0xFF334155))),
        const SizedBox(height: 6),
        child,
        if (error != null)
          Padding(
            padding: const EdgeInsets.only(top: 5),
            child: Text(error!, style: const TextStyle(fontSize: 12, color: Color(0xFFBE123C))),
          ),
      ],
    );
  }
}

class _ResultPanel extends StatelessWidget {
  final _CalculationResult result;

  const _ResultPanel({required this.result});

  @override
  Widget build(BuildContext context) {
    final weeklyNormalized = (result.weeklyVariationKg.abs() / 1).clamp(0, 1).toDouble();

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: const Color(0xFFE2E8F0)),
        boxShadow: const [
          BoxShadow(
            color: Color(0x140F172A),
            blurRadius: 20,
            offset: Offset(0, 10),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Resultats du calcul', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: Color(0xFF020617))),
          const SizedBox(height: 12),
          LayoutBuilder(
            builder: (context, constraints) {
              final dual = constraints.maxWidth >= 600;
              final width = dual ? (constraints.maxWidth - 12) / 2 : constraints.maxWidth;

              return Wrap(
                spacing: 12,
                runSpacing: 12,
                children: [
                  SizedBox(
                    width: width,
                    child: _ResultCard(
                      title: 'BMR',
                      value: '${result.bmr} kcal',
                      subtitle: 'Metabolisme de base',
                    ),
                  ),
                  SizedBox(
                    width: width,
                    child: _ResultCard(
                      title: 'Maintenance',
                      value: '${result.maintenanceCalories} kcal',
                      subtitle: 'Calories de maintien',
                    ),
                  ),
                  SizedBox(
                    width: width,
                    child: _ResultCard(
                      title: 'Objectif calorique',
                      value: '${result.targetCalories} kcal',
                      subtitle: 'Cible quotidienne',
                    ),
                  ),
                  SizedBox(
                    width: width,
                    child: _ResultCard(
                      title: 'Ajustement / jour',
                      value: '${result.adjustmentPerDay > 0 ? '+' : ''}${result.adjustmentPerDay} kcal',
                      subtitle: 'Impact du delai',
                    ),
                  ),
                ],
              );
            },
          ),
          const SizedBox(height: 14),
          const Text('Repartition des macros', style: TextStyle(fontWeight: FontWeight.w700, color: Color(0xFF0F172A))),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(child: _MacroPill(label: 'Proteines', grams: result.proteinsGrams, color: const Color(0xFF10B981))),
              const SizedBox(width: 10),
              Expanded(child: _MacroPill(label: 'Lipides', grams: result.fatsGrams, color: const Color(0xFFF59E0B))),
              const SizedBox(width: 10),
              Expanded(child: _MacroPill(label: 'Glucides', grams: result.carbsGrams, color: const Color(0xFF0EA5E9))),
            ],
          ),
          const SizedBox(height: 14),
          const Text('Variation hebdomadaire', style: TextStyle(fontWeight: FontWeight.w700, color: Color(0xFF0F172A))),
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              minHeight: 10,
              value: weeklyNormalized,
              backgroundColor: const Color(0xFFE2E8F0),
              valueColor: const AlwaysStoppedAnimation<Color>(Color(0xFF0F5B43)),
            ),
          ),
          const SizedBox(height: 6),
          Text(
            '${result.weeklyVariationKg > 0 ? '+' : ''}${result.weeklyVariationKg} kg/semaine · variation totale ${result.variationKg > 0 ? '+' : ''}${result.variationKg} kg',
            style: const TextStyle(color: Color(0xFF475569)),
          ),
          const SizedBox(height: 12),
          Text(result.summary, style: const TextStyle(fontWeight: FontWeight.w600, color: Color(0xFF0F172A))),
          const SizedBox(height: 6),
          Text(result.explanation, style: const TextStyle(color: Color(0xFF475569), height: 1.45)),
          if (result.warning != null)
            Container(
              margin: const EdgeInsets.only(top: 12),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: const Color(0xFFFFF7ED),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFFED7AA)),
              ),
              child: Text(result.warning!, style: const TextStyle(color: Color(0xFF9A3412), fontWeight: FontWeight.w600)),
            ),
          const SizedBox(height: 8),
          Text(
            'Seuil minimal de securite: ${result.floorCalories} kcal',
            style: const TextStyle(fontSize: 12, color: Color(0xFF64748B)),
          ),
        ],
      ),
    );
  }
}

class _ResultCard extends StatelessWidget {
  final String title;
  final String value;
  final String subtitle;

  const _ResultCard({required this.title, required this.value, required this.subtitle});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: const TextStyle(fontSize: 12, color: Color(0xFF64748B))),
          const SizedBox(height: 4),
          Text(value, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: Color(0xFF0F172A))),
          const SizedBox(height: 2),
          Text(subtitle, style: const TextStyle(fontSize: 12, color: Color(0xFF64748B))),
        ],
      ),
    );
  }
}

class _MacroPill extends StatelessWidget {
  final String label;
  final int grams;
  final Color color;

  const _MacroPill({required this.label, required this.grams, required this.color});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: TextStyle(fontSize: 12, color: color, fontWeight: FontWeight.w600)),
          const SizedBox(height: 4),
          Text('$grams g', style: const TextStyle(fontWeight: FontWeight.w800, color: Color(0xFF0F172A))),
        ],
      ),
    );
  }
}

class _CalculationResult {
  final int bmr;
  final int maintenanceCalories;
  final int targetCalories;
  final int proteinsGrams;
  final int fatsGrams;
  final int carbsGrams;
  final int adjustmentPerDay;
  final double weeklyVariationKg;
  final double variationKg;
  final int floorCalories;
  final String? warning;
  final String summary;
  final String explanation;

  const _CalculationResult({
    required this.bmr,
    required this.maintenanceCalories,
    required this.targetCalories,
    required this.proteinsGrams,
    required this.fatsGrams,
    required this.carbsGrams,
    required this.adjustmentPerDay,
    required this.weeklyVariationKg,
    required this.variationKg,
    required this.floorCalories,
    required this.warning,
    required this.summary,
    required this.explanation,
  });
}
