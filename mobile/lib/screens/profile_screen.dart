import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/profile.dart';
import '../services/profile_service.dart';
import '../theme/app_theme.dart';
import '../widgets/app_card.dart';
import '../widgets/error_state.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/status_banner.dart';

/// Profil (§16.4, §2, addendum §A.4/§D).
///
/// Server-driven: every number comes from `POST /profile/preview` (debounced
/// 600 ms) and `PUT /profile`. No local nutrition calculator.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key, this.userName, this.onNavigate});

  /// Kept for backward compatibility with the previous signature (unused:
  /// the name comes from `GET /profile`).
  final String? userName;

  /// Quick navigation to another section (slug).
  final ValueChanged<String>? onNavigate;

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  static const String _cacheKey = 'profile';

  /// Régimes refusés aux moins de 18 ans (§2.3 règle 3).
  static const Set<String> _adultOnlyRegimes = {'keto', 'low_carb', 'jeune_intermittent', 'montignac'};

  final ProfileService _service = ProfileService();

  // ----- Controllers --------------------------------------------------------
  final _nom = TextEditingController();
  final _age = TextEditingController();
  final _taille = TextEditingController();
  final _poids = TextEditingController();
  final _poidsSouhaite = TextEditingController();
  final _delaiJours = TextEditingController();
  final _sportNotes = TextEditingController();
  final _sportTempsDispo = TextEditingController();
  final _sportJours = TextEditingController();
  final _caloriesCibles = TextEditingController();
  final _proteinesCibles = TextEditingController();
  final _glucidesCibles = TextEditingController();
  final _lipidesCibles = TextEditingController();

  // ----- Selections ---------------------------------------------------------
  String? _sexe;
  String? _objectifType;
  String? _niveauActivite;
  String? _regime;
  String _situation = 'aucune';
  String? _sportNiveau;
  String? _sportObjectif;
  String? _sportLieu;
  int _sportCoef = 100;
  final Set<String> _sportMateriel = <String>{};
  final Set<String> _sportFocus = <String>{};
  final Set<String> _sportZones = <String>{};
  List<String> _allergenes = <String>[];
  List<String> _alimentsExclus = <String>[];
  List<String> _aime = <String>[];
  List<String> _evite = <String>[];
  bool _override = false;
  bool _consentSante = false;
  bool _consentParental = false;

  // ----- Screen state -------------------------------------------------------
  Profile? _profile;
  bool _loading = true;
  bool _saving = false;
  String? _error;
  String? _saveError;
  String? _successMessage;
  Map<String, String> _fieldErrors = <String, String>{};

  ProfilePreview? _preview;
  bool _previewing = false;
  String? _previewError;
  Timer? _previewTimer;
  int _previewSeq = 0;

  @override
  void initState() {
    super.initState();
    final cached = Session.instance.cached(_cacheKey);
    if (cached != null) {
      _applyProfile(Profile.fromJson(cached.data));
      _loading = false;
      if (cached.isStale()) _load(silent: true);
    } else {
      _load();
    }
  }

  @override
  void dispose() {
    _previewTimer?.cancel();
    for (final c in [
      _nom,
      _age,
      _taille,
      _poids,
      _poidsSouhaite,
      _delaiJours,
      _sportNotes,
      _sportTempsDispo,
      _sportJours,
      _caloriesCibles,
      _proteinesCibles,
      _glucidesCibles,
      _lipidesCibles,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  // ----- Loading ------------------------------------------------------------

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = _profile == null;
        _error = null;
      });
    }
    try {
      final raw = await _service.raw();
      if (!mounted) return;
      Session.instance.put(_cacheKey, raw);
      setState(() {
        _applyProfile(Profile.fromJson(raw));
        _loading = false;
        _error = null;
      });
      _schedulePreview(immediate: true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (_profile == null) {
          _error = e.message;
        } else if (!silent) {
          ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(e.message)));
        }
      });
    }
  }

  /// Fills every control from the server payload.
  void _applyProfile(Profile p) {
    _profile = p;
    _nom.text = p.nom ?? Session.instance.user?.name ?? '';
    _age.text = p.age?.toString() ?? '';
    _taille.text = p.taille == null ? '' : fmtDecimal(p.taille, decimals: 0);
    _poids.text = p.poids == null ? '' : fmtDecimal(p.poids);
    _poidsSouhaite.text = p.poidsSouhaiteKg == null ? '' : fmtDecimal(p.poidsSouhaiteKg);
    _delaiJours.text = p.delaiObjectifJours?.toString() ?? '';
    _sportNotes.text = p.sportNotes ?? '';
    _sportTempsDispo.text = p.sportTempsDispoMin?.toString() ?? '';
    _sportJours.text = p.sportJoursSemaine?.toString() ?? '';
    _caloriesCibles.text = p.caloriesCibles == null ? '' : fmtDecimal(p.caloriesCibles, decimals: 0);
    _proteinesCibles.text = p.proteinesCibles == null ? '' : fmtDecimal(p.proteinesCibles, decimals: 0);
    _glucidesCibles.text = p.glucidesCibles == null ? '' : fmtDecimal(p.glucidesCibles, decimals: 0);
    _lipidesCibles.text = p.lipidesCibles == null ? '' : fmtDecimal(p.lipidesCibles, decimals: 0);

    _sexe = _oneOf(p.sexe, const ['homme', 'femme']);
    _objectifType = _oneOf(p.objectifType, AppStrings.objectifTypeLabels.keys.toList());
    _niveauActivite = _oneOf(p.niveauActivite, AppStrings.niveauActiviteLabels.keys.toList());
    _regime = _oneOf(p.regimeAlimentaire, AppStrings.regimeLabels.keys.toList());
    _situation = AppStrings.situationLabels.containsKey(p.situationParticuliere) ? p.situationParticuliere : 'aucune';
    _sportNiveau = _oneOf(p.sportNiveau, AppStrings.sportNiveaux.map((v) => v.key).toList());
    _sportObjectif = _oneOf(p.sportObjectif, AppStrings.sportObjectifs.map((v) => v.key).toList());
    _sportLieu = _oneOf(p.sportLieu, AppStrings.sportLieux.map((v) => v.key).toList());
    _sportCoef = const [50, 75, 100].contains(p.sportCoefCalories) ? p.sportCoefCalories : 100;
    _sportMateriel
      ..clear()
      ..addAll(p.sportMateriel.where((k) => AppStrings.sportMateriel.any((v) => v.key == k)));
    _sportFocus
      ..clear()
      ..addAll(p.sportFocus.where((k) => AppStrings.sportFocus.any((v) => v.key == k)));
    _sportZones
      ..clear()
      ..addAll(p.sportZonesAEviter.where((k) => AppStrings.sportZones.any((v) => v.key == k)));
    _allergenes = List<String>.from(p.allergenes);
    _alimentsExclus = List<String>.from(p.alimentsExclus);
    _aime = List<String>.from(p.preferencesAime);
    _evite = List<String>.from(p.preferencesEvite);
    _override = !p.objectifCalculAuto;
    _consentSante = p.consentementSante;
    _consentParental = p.consentementParental;
    if (p.besoins != null) {
      _preview = ProfilePreview(
        besoins: p.besoins!,
        ciblesEffectives: p.ciblesEffectives ?? const CiblesEffectives(),
        imc: p.imc,
        imcCible: p.imcCible,
      );
    }
  }

  static String? _oneOf(String? value, List<String> allowed) =>
      value != null && allowed.contains(value) ? value : null;

  // ----- Derived ------------------------------------------------------------

  int? get _ageValue => int.tryParse(_age.text.trim());

  bool get _isMinor => (_ageValue ?? 18) < 18;

  bool get _needsParentalConsent => (_ageValue ?? 99) < 15;

  bool get _hasSituation => _situation != 'aucune';

  /// Poids souhaité / délai are only relevant for an adult perte/prise goal.
  bool get _goalFieldsVisible =>
      (_objectifType == 'perdre' || _objectifType == 'prendre') && !_isMinor && !_hasSituation;

  bool get _consentNeeded => !_consentSante;

  // ----- Body & validation --------------------------------------------------

  Map<String, dynamic> _body({required bool forSave}) {
    final goal = _goalFieldsVisible;
    final body = <String, dynamic>{
      'nom': _nom.text.trim(),
      'sexe': _sexe,
      'age': _ageValue,
      'taille': parseDecimal(_taille.text),
      'poids': parseDecimal(_poids.text),
      'niveau_activite': _niveauActivite,
      'objectif_type': _objectifType,
      'objectif': _objectifType,
      'regime_alimentaire': _regime,
      'poids_souhaite_kg': goal ? parseDecimal(_poidsSouhaite.text) : null,
      'delai_objectif_jours': goal ? int.tryParse(_delaiJours.text.trim()) : null,
      'allergenes': _allergenes,
      'aliments_exclus': _alimentsExclus,
      'preferences': {'aime': _aime, 'evite': _evite},
      'sport_niveau': _sportNiveau,
      'sport_objectif': _sportObjectif,
      'sport_materiel': _sportMateriel.toList(),
      'sport_temps_dispo_min': int.tryParse(_sportTempsDispo.text.trim()),
      'sport_jours_semaine': int.tryParse(_sportJours.text.trim()),
      'sport_lieu': _sportLieu,
      'sport_zones_a_eviter': _sportZones.toList(),
      'sport_focus': _sportFocus.toList(),
      'sport_notes': _sportNotes.text.trim().isEmpty ? null : _sportNotes.text.trim(),
      'sport_coef_calories': _sportCoef,
      'situation_particuliere': _situation,
      'consentement_parental': _consentParental,
      'objectif_calcul_auto': !_override,
    };
    if (_override) {
      body['calories_cibles'] = parseDecimal(_caloriesCibles.text);
      body['proteines_cibles'] = parseDecimal(_proteinesCibles.text);
      body['glucides_cibles'] = parseDecimal(_glucidesCibles.text);
      body['lipides_cibles'] = parseDecimal(_lipidesCibles.text);
    }
    if (forSave && _consentNeeded) {
      body['consentement_sante'] = _consentSante;
    }
    return body;
  }

  /// Local completeness check (the server owns the business rules).
  Map<String, String> _localErrors() {
    final errors = <String, String>{};
    if (_nom.text.trim().isEmpty) errors['nom'] = 'Ton prénom est obligatoire.';
    if (_sexe == null) errors['sexe'] = 'Choisis une option.';
    final age = _ageValue;
    if (age == null || age < 12 || age > 120) errors['age'] = 'Indique un âge entre 12 et 120 ans.';
    final taille = parseDecimal(_taille.text);
    if (taille == null || taille < 100 || taille > 300) errors['taille'] = 'Indique une taille entre 100 et 300 cm.';
    final poids = parseDecimal(_poids.text);
    if (poids == null || poids < 20 || poids > 500) errors['poids'] = 'Indique un poids entre 20 et 500 kg.';
    if (_niveauActivite == null) errors['niveau_activite'] = 'Choisis ton niveau d’activité.';
    if (_objectifType == null) errors['objectif_type'] = 'Choisis ton objectif.';
    if (_regime == null) errors['regime_alimentaire'] = 'Choisis ton régime alimentaire.';
    if (_goalFieldsVisible) {
      final souhaite = parseDecimal(_poidsSouhaite.text);
      if (souhaite == null || souhaite < 20 || souhaite > 500) {
        errors['poids_souhaite_kg'] = 'Indique le poids que tu vises.';
      }
      final delai = int.tryParse(_delaiJours.text.trim());
      if (delai == null || delai < 1 || delai > 2000) {
        errors['delai_objectif_jours'] = 'Indique un délai entre 1 et 2 000 jours.';
      }
    }
    final temps = _sportTempsDispo.text.trim();
    if (temps.isNotEmpty) {
      final value = int.tryParse(temps);
      if (value == null || value < 5 || value > 600) {
        errors['sport_temps_dispo_min'] = 'Entre 5 et 600 minutes.';
      }
    }
    final jours = _sportJours.text.trim();
    if (jours.isNotEmpty) {
      final value = int.tryParse(jours);
      if (value == null || value < 0 || value > 7) errors['sport_jours_semaine'] = 'Entre 0 et 7 jours.';
    }
    if (_override) {
      if (parseDecimal(_caloriesCibles.text) == null) errors['calories_cibles'] = 'Valeur obligatoire.';
      if (parseDecimal(_proteinesCibles.text) == null) errors['proteines_cibles'] = 'Valeur obligatoire.';
      if (parseDecimal(_glucidesCibles.text) == null) errors['glucides_cibles'] = 'Valeur obligatoire.';
      if (parseDecimal(_lipidesCibles.text) == null) errors['lipides_cibles'] = 'Valeur obligatoire.';
    }
    if (_needsParentalConsent && !_consentParental) {
      errors['consentement_parental'] = 'L’accord d’un parent est requis pour les moins de 15 ans.';
    }
    return errors;
  }

  bool get _formValid => _localErrors().isEmpty;

  // ----- Live preview -------------------------------------------------------

  void _onChanged() {
    setState(() {
      _successMessage = null;
      _saveError = null;
    });
    _schedulePreview();
  }

  void _schedulePreview({bool immediate = false}) {
    _previewTimer?.cancel();
    if (!_formValid) {
      if (_previewing || _previewError != null) {
        setState(() {
          _previewing = false;
          _previewError = null;
        });
      }
      return;
    }
    _previewTimer = Timer(Duration(milliseconds: immediate ? 0 : 600), _runPreview);
  }

  Future<void> _runPreview() async {
    if (!mounted || !_formValid) return;
    final seq = ++_previewSeq;
    setState(() {
      _previewing = true;
      _previewError = null;
    });
    try {
      final preview = await _service.preview(_body(forSave: false));
      if (!mounted || seq != _previewSeq) return;
      setState(() {
        _preview = preview;
        _previewing = false;
        _previewError = null;
        _fieldErrors = <String, String>{};
      });
    } on ApiException catch (e) {
      if (!mounted || seq != _previewSeq) return;
      setState(() {
        _previewing = false;
        _previewError = e.message;
        if (e.kind == ApiErrorKind.validation) {
          _fieldErrors = e.fieldErrors.map((key, value) => MapEntry(key, value.first));
        }
      });
    }
  }

  // ----- Save ---------------------------------------------------------------

  Future<void> _save() async {
    FocusScope.of(context).unfocus();
    final local = _localErrors();
    if (_consentNeeded && !_consentSante) {
      local['consentement_sante'] = 'Ton accord est nécessaire pour calculer des objectifs à partir de tes données de santé.';
    }
    if (local.isNotEmpty) {
      setState(() {
        _fieldErrors = local;
        _saveError = 'Vérifie les champs signalés avant d’enregistrer.';
        _successMessage = null;
      });
      return;
    }
    setState(() {
      _saving = true;
      _fieldErrors = <String, String>{};
      _saveError = null;
      _successMessage = null;
    });
    try {
      final raw = await _service.updateRaw(_body(forSave: true));
      if (!mounted) return;
      Session.instance.put(_cacheKey, raw);
      Session.instance.invalidate('dashboard');
      Session.instance.invalidatePrefix('meals');
      Session.instance.invalidate('recommendations');
      Session.instance.invalidate('regime-reconnu');
      Session.instance.invalidatePrefix('sport');
      setState(() {
        _applyProfile(Profile.fromJson(raw));
        _saving = false;
        _successMessage = parseString(raw['message']) ?? 'Profil mis à jour.';
      });
      try {
        await Session.instance.refreshMe();
      } on ApiException {
        // The profile is saved; a failed /me refresh is not blocking.
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _saveError = e.message;
        _fieldErrors = e.fieldErrors.map((key, value) => MapEntry(key, value.first));
      });
    }
  }

  // ----- Build --------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    if (_loading && _profile == null) return const LoadingState(skeleton: true, skeletonCount: 4);
    if (_error != null && _profile == null) return ErrorState(message: _error!, onRetry: _load);

    final profile = _profile!;
    return RefreshIndicator(
      onRefresh: () => _load(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 100),
        children: [
          if (!profile.hasProfile)
            StatusBanner.info(
              'Complète ton profil pour obtenir tes objectifs personnalisés : Mavi’oh calcule tout côté serveur.',
              title: 'Profil incomplet',
              margin: const EdgeInsets.only(bottom: 14),
            ),
          if (_successMessage != null)
            StatusBanner.success(
              _successMessage!,
              margin: const EdgeInsets.only(bottom: 14),
              onClose: () => setState(() => _successMessage = null),
            ),
          if (_saveError != null)
            StatusBanner.error(
              _saveError!,
              margin: const EdgeInsets.only(bottom: 14),
              onClose: () => setState(() => _saveError = null),
            ),
          _previewPanel(),
          const SizedBox(height: 14),
          _identiteSection(),
          const SizedBox(height: 14),
          _objectifSection(),
          const SizedBox(height: 14),
          _activiteSection(),
          const SizedBox(height: 14),
          _regimeSection(),
          const SizedBox(height: 14),
          _allergenesSection(),
          const SizedBox(height: 14),
          _preferencesSection(),
          const SizedBox(height: 14),
          _sportSection(),
          const SizedBox(height: 14),
          _situationSection(),
          const SizedBox(height: 14),
          _overrideSection(),
          const SizedBox(height: 18),
          if (_consentNeeded) ...[
            _consentCard(),
            const SizedBox(height: 14),
          ],
          SizedBox(
            height: 52,
            child: FilledButton.icon(
              onPressed: _saving ? null : _save,
              icon: _saving
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.2, color: Colors.white))
                  : const Icon(Icons.check_rounded),
              label: Text(_saving ? 'Enregistrement…' : 'Enregistrer mon profil'),
            ),
          ),
          const SizedBox(height: 12),
          const Text(
            AppStrings.disclaimer,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 11.5, color: MaviohColors.muted, height: 1.4),
          ),
        ],
      ),
    );
  }

  // ----- Preview panel ------------------------------------------------------

  Widget _previewPanel() {
    final preview = _preview;
    if (preview == null) {
      return AppCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const _CardHeader(icon: Icons.insights_outlined, tone: MaviohColors.teal, title: 'Tes besoins'),
            const SizedBox(height: 8),
            Text(
              _previewing
                  ? 'Calcul de tes besoins…'
                  : 'Renseigne ton identité, ton objectif et ton activité : Mavi’oh calcule aussitôt tes besoins.',
              style: const TextStyle(color: MaviohColors.muted, height: 1.45),
            ),
            if (_previewError != null) ...[
              const SizedBox(height: 10),
              StatusBanner.error(_previewError!),
            ],
          ],
        ),
      );
    }

    final b = preview.besoins;
    final cibles = preview.ciblesEffectives;
    return AppCard.hero(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Expanded(
                child: _CardHeader(icon: Icons.insights_outlined, tone: MaviohColors.teal, title: 'Tes besoins'),
              ),
              if (_previewing)
                const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
              else if (b.isEstimate)
                const EstimatePill(),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Objectif calorique',
                      style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: MaviohColors.muted, letterSpacing: 0.4),
                    ),
                    const SizedBox(height: 2),
                    FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerLeft,
                      child: Text(
                        fmtKcal(cibles.calories > 0 ? cibles.calories : b.caloriesRecommandees),
                        style: const TextStyle(fontSize: 30, fontWeight: FontWeight.w800, color: MaviohColors.primary),
                      ),
                    ),
                  ],
                ),
              ),
              TonePill(
                label: cibles.isManual ? 'Cibles manuelles' : 'Calculé par Mavi’oh',
                tone: cibles.isManual ? MaviohColors.violet : MaviohColors.emerald,
                icon: cibles.isManual ? Icons.tune_rounded : Icons.auto_awesome_rounded,
              ),
            ],
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              MacroPill.proteins(value: cibles.proteines > 0 ? cibles.proteines : b.proteinesG),
              MacroPill.carbs(value: cibles.glucides > 0 ? cibles.glucides : b.glucidesG),
              MacroPill.fat(value: cibles.lipides > 0 ? cibles.lipides : b.lipidesG),
            ],
          ),
          const SizedBox(height: 14),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              _MiniStat(label: 'Métabolisme de base', value: fmtKcal(b.bmr)),
              _MiniStat(label: 'Dépense quotidienne', value: fmtKcal(b.tdee)),
              _MiniStat(
                label: 'Variation hebdo',
                value: b.variationHebdoKg == null ? '—' : '${fmtDecimal(b.variationHebdoKg, decimals: 2)}${nbsp}kg',
              ),
              _MiniStat(label: 'IMC', value: b.imc == null ? '—' : fmtDecimal(b.imc)),
              if (b.imcCible != null) _MiniStat(label: 'IMC visé', value: fmtDecimal(b.imcCible)),
              if (b.joursRestants != null) _MiniStat(label: 'Jours restants', value: fmtInt(b.joursRestants)),
            ],
          ),
          if (b.avertissements.isNotEmpty) ...[
            const SizedBox(height: 14),
            for (final warning in b.avertissements)
              StatusBanner.warning(warning, margin: const EdgeInsets.only(bottom: 8)),
          ],
          if (_previewError != null) ...[
            const SizedBox(height: 6),
            StatusBanner.error(_previewError!),
          ],
          if (b.mention.isNotEmpty) ...[
            const SizedBox(height: 12),
            Text(
              b.mention,
              style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted, height: 1.45),
            ),
          ],
        ],
      ),
    );
  }

  // ----- Sections -----------------------------------------------------------

  Widget _identiteSection() {
    return _Section(
      icon: Icons.badge_outlined,
      tone: MaviohColors.indigo,
      title: 'Identité',
      children: [
        _textField(label: 'Prénom', controller: _nom, errorKey: 'nom', capitalize: true),
        const SizedBox(height: 14),
        _segmented<String>(
          label: 'Sexe',
          errorKey: 'sexe',
          value: _sexe,
          options: const [MapEntry('homme', 'Homme'), MapEntry('femme', 'Femme')],
          onChanged: (value) {
            setState(() => _sexe = value);
            _onChanged();
          },
        ),
        const SizedBox(height: 14),
        Row(
          children: [
            Expanded(child: _numberField(label: 'Âge', suffix: 'ans', controller: _age, errorKey: 'age', decimal: false)),
            const SizedBox(width: 12),
            Expanded(child: _numberField(label: 'Taille', suffix: 'cm', controller: _taille, errorKey: 'taille')),
          ],
        ),
        const SizedBox(height: 14),
        _numberField(label: 'Poids actuel', suffix: 'kg', controller: _poids, errorKey: 'poids'),
      ],
    );
  }

  Widget _objectifSection() {
    return _Section(
      icon: Icons.flag_outlined,
      tone: MaviohColors.emerald,
      title: 'Objectif',
      subtitle: _isMinor
          ? 'Avant 18 ans, Mavi’oh ne propose que le maintien du poids.'
          : (_hasSituation ? 'Dans ta situation, seul le maintien du poids est proposé.' : null),
      children: [
        _segmented<String>(
          label: 'Je souhaite',
          errorKey: 'objectif_type',
          value: _objectifType,
          options: AppStrings.objectifTypeLabels.entries
              .map((e) => MapEntry(e.key, e.key == 'perdre' ? 'Perdre' : (e.key == 'prendre' ? 'Prendre' : 'Maintenir')))
              .toList(),
          onChanged: (value) {
            setState(() => _objectifType = value);
            _onChanged();
          },
        ),
        if (_goalFieldsVisible) ...[
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: _numberField(
                  label: 'Poids souhaité',
                  suffix: 'kg',
                  controller: _poidsSouhaite,
                  errorKey: 'poids_souhaite_kg',
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _numberField(
                  label: 'Délai',
                  suffix: 'jours',
                  controller: _delaiJours,
                  errorKey: 'delai_objectif_jours',
                  decimal: false,
                ),
              ),
            ],
          ),
        ],
      ],
    );
  }

  Widget _activiteSection() {
    return _Section(
      icon: Icons.directions_walk_rounded,
      tone: MaviohColors.sky,
      title: 'Activité',
      subtitle: 'Ton activité quotidienne hors séances enregistrées',
      children: [
        _dropdown(
          label: 'Niveau d’activité',
          errorKey: 'niveau_activite',
          value: _niveauActivite,
          entries: AppStrings.niveauActiviteLabels.entries.map((e) => _Option(e.key, e.value)).toList(),
          onChanged: (value) {
            setState(() => _niveauActivite = value);
            _onChanged();
          },
        ),
      ],
    );
  }

  Widget _regimeSection() {
    final minor = _isMinor;
    return _Section(
      icon: Icons.local_dining_outlined,
      tone: MaviohColors.lime,
      title: 'Régime',
      subtitle: minor ? 'Certains régimes ne sont pas proposés aux moins de 18 ans.' : null,
      children: [
        _dropdown(
          label: 'Régime alimentaire',
          errorKey: 'regime_alimentaire',
          value: _regime,
          entries: AppStrings.regimeLabels.entries
              .map((e) => _Option(e.key, e.value, enabled: !(minor && _adultOnlyRegimes.contains(e.key))))
              .toList(),
          onChanged: (value) {
            setState(() => _regime = value);
            _onChanged();
          },
        ),
      ],
    );
  }

  Widget _allergenesSection() {
    return _Section(
      icon: Icons.no_food_outlined,
      tone: MaviohColors.rose,
      title: 'Allergènes & aliments exclus',
      subtitle: 'Mavi’oh évite ces aliments dans ses recommandations.',
      children: [
        _ChipInput(
          label: 'Allergènes',
          hint: 'Ex. : arachide',
          values: _allergenes,
          tone: MaviohColors.rose,
          onChanged: (values) {
            setState(() => _allergenes = values);
            _onChanged();
          },
        ),
        const SizedBox(height: 14),
        _ChipInput(
          label: 'Aliments exclus',
          hint: 'Ex. : porc',
          values: _alimentsExclus,
          tone: MaviohColors.orange,
          onChanged: (values) {
            setState(() => _alimentsExclus = values);
            _onChanged();
          },
        ),
      ],
    );
  }

  Widget _preferencesSection() {
    return _Section(
      icon: Icons.favorite_outline_rounded,
      tone: MaviohColors.fuchsia,
      title: 'Préférences',
      children: [
        _ChipInput(
          label: 'J’aime',
          hint: 'Ex. : saumon',
          values: _aime,
          tone: MaviohColors.emerald,
          onChanged: (values) {
            setState(() => _aime = values);
            _onChanged();
          },
        ),
        const SizedBox(height: 14),
        _ChipInput(
          label: 'J’évite',
          hint: 'Ex. : brocoli',
          values: _evite,
          tone: MaviohColors.slate,
          onChanged: (values) {
            setState(() => _evite = values);
            _onChanged();
          },
        ),
      ],
    );
  }

  Widget _sportSection() {
    return _Section(
      icon: Icons.fitness_center_rounded,
      tone: MaviohColors.violet,
      title: 'Sport',
      subtitle: 'Sert à proposer des séances adaptées et à réintégrer tes calories brûlées.',
      children: [
        _dropdown(
          label: 'Niveau',
          errorKey: 'sport_niveau',
          value: _sportNiveau,
          entries: AppStrings.sportNiveaux.map((v) => _Option(v.key, v.label)).toList(),
          onChanged: (value) {
            setState(() => _sportNiveau = value);
            _onChanged();
          },
        ),
        const SizedBox(height: 14),
        _dropdown(
          label: 'Objectif sportif',
          errorKey: 'sport_objectif',
          value: _sportObjectif,
          entries: AppStrings.sportObjectifs.map((v) => _Option(v.key, v.label)).toList(),
          onChanged: (value) {
            setState(() => _sportObjectif = value);
            _onChanged();
          },
        ),
        const SizedBox(height: 14),
        _chipGroup(
          label: 'Matériel disponible',
          errorKey: 'sport_materiel',
          vocab: AppStrings.sportMateriel,
          selected: _sportMateriel,
          tone: MaviohColors.violet,
          exclusiveKey: 'aucun',
        ),
        const SizedBox(height: 14),
        _dropdown(
          label: 'Lieu d’entraînement',
          errorKey: 'sport_lieu',
          value: _sportLieu,
          entries: AppStrings.sportLieux.map((v) => _Option(v.key, v.label)).toList(),
          onChanged: (value) {
            setState(() => _sportLieu = value);
            _onChanged();
          },
        ),
        const SizedBox(height: 14),
        _chipGroup(
          label: 'Focus',
          errorKey: 'sport_focus',
          vocab: AppStrings.sportFocus,
          selected: _sportFocus,
          tone: MaviohColors.teal,
        ),
        const SizedBox(height: 14),
        _chipGroup(
          label: 'Zones à éviter',
          errorKey: 'sport_zones_a_eviter',
          vocab: AppStrings.sportZones,
          selected: _sportZones,
          tone: MaviohColors.rose,
        ),
        const SizedBox(height: 14),
        _textField(
          label: 'Notes libres',
          controller: _sportNotes,
          errorKey: 'sport_notes',
          hint: 'Ex. : j’ai mal au genou droit, je veux travailler les fessiers',
          maxLines: 3,
          maxLength: 1000,
        ),
        const SizedBox(height: 14),
        Row(
          children: [
            Expanded(
              child: _numberField(
                label: 'Temps dispo',
                suffix: 'min',
                controller: _sportTempsDispo,
                errorKey: 'sport_temps_dispo_min',
                decimal: false,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: _numberField(
                label: 'Jours / semaine',
                controller: _sportJours,
                errorKey: 'sport_jours_semaine',
                decimal: false,
              ),
            ),
          ],
        ),
        const SizedBox(height: 14),
        _segmented<int>(
          label: 'Réintégrer les calories brûlées',
          errorKey: 'sport_coef_calories',
          value: _sportCoef,
          options: const [MapEntry(50, '50 %'), MapEntry(75, '75 %'), MapEntry(100, '100 %')],
          onChanged: (value) {
            if (value == null) return;
            setState(() => _sportCoef = value);
            _onChanged();
          },
        ),
        const SizedBox(height: 6),
        const Text(
          'Part des calories brûlées ajoutée à ton budget du jour.',
          style: TextStyle(fontSize: 12, color: MaviohColors.muted, height: 1.4),
        ),
      ],
    );
  }

  Widget _situationSection() {
    return _Section(
      icon: Icons.health_and_safety_outlined,
      tone: MaviohColors.amber,
      title: 'Situation particulière',
      subtitle: 'Grossesse, allaitement ou suivi médical : les objectifs restent au maintien.',
      children: [
        _dropdown(
          label: 'Situation',
          errorKey: 'situation_particuliere',
          value: _situation,
          entries: AppStrings.situationLabels.entries.map((e) => _Option(e.key, e.value)).toList(),
          onChanged: (value) {
            setState(() => _situation = value ?? 'aucune');
            _onChanged();
          },
        ),
        if (_needsParentalConsent) ...[
          const SizedBox(height: 10),
          CheckboxListTile(
            value: _consentParental,
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            title: const Text(
              'Un parent ou tuteur a donné son accord.',
              style: TextStyle(fontSize: 13.5, height: 1.4, fontWeight: FontWeight.w600),
            ),
            subtitle: _fieldErrors['consentement_parental'] == null
                ? null
                : Text(
                    _fieldErrors['consentement_parental']!,
                    style: const TextStyle(color: MaviohColors.error, fontSize: 12),
                  ),
            onChanged: (value) {
              setState(() => _consentParental = value ?? false);
              _onChanged();
            },
          ),
        ],
      ],
    );
  }

  Widget _overrideSection() {
    return _Section(
      icon: Icons.tune_rounded,
      tone: MaviohColors.cyan,
      title: 'Cibles',
      children: [
        SwitchListTile(
          value: _override,
          contentPadding: EdgeInsets.zero,
          title: const Text('Ajuster mes cibles manuellement', style: TextStyle(fontWeight: FontWeight.w700)),
          subtitle: const Text(
            'Mavi’oh vérifie que tes valeurs restent dans une fourchette sûre.',
            style: TextStyle(fontSize: 12.5, height: 1.4),
          ),
          onChanged: (value) {
            setState(() {
              _override = value;
              if (value) _prefillOverrides();
            });
            _onChanged();
          },
        ),
        if (_override) ...[
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: _numberField(
                  label: 'Calories',
                  suffix: 'kcal',
                  controller: _caloriesCibles,
                  errorKey: 'calories_cibles',
                  decimal: false,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _numberField(
                  label: 'Protéines',
                  suffix: 'g',
                  controller: _proteinesCibles,
                  errorKey: 'proteines_cibles',
                  decimal: false,
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: _numberField(
                  label: 'Glucides',
                  suffix: 'g',
                  controller: _glucidesCibles,
                  errorKey: 'glucides_cibles',
                  decimal: false,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _numberField(
                  label: 'Lipides',
                  suffix: 'g',
                  controller: _lipidesCibles,
                  errorKey: 'lipides_cibles',
                  decimal: false,
                ),
              ),
            ],
          ),
        ],
      ],
    );
  }

  void _prefillOverrides() {
    final b = _preview?.besoins;
    final cibles = _preview?.ciblesEffectives;
    if (_caloriesCibles.text.trim().isEmpty) {
      final value = (cibles != null && cibles.calories > 0) ? cibles.calories : b?.caloriesRecommandees;
      if (value != null) _caloriesCibles.text = fmtDecimal(value, decimals: 0);
    }
    if (_proteinesCibles.text.trim().isEmpty) {
      final value = (cibles != null && cibles.proteines > 0) ? cibles.proteines : b?.proteinesG;
      if (value != null) _proteinesCibles.text = fmtDecimal(value, decimals: 0);
    }
    if (_glucidesCibles.text.trim().isEmpty) {
      final value = (cibles != null && cibles.glucides > 0) ? cibles.glucides : b?.glucidesG;
      if (value != null) _glucidesCibles.text = fmtDecimal(value, decimals: 0);
    }
    if (_lipidesCibles.text.trim().isEmpty) {
      final value = (cibles != null && cibles.lipides > 0) ? cibles.lipides : b?.lipidesG;
      if (value != null) _lipidesCibles.text = fmtDecimal(value, decimals: 0);
    }
  }

  Widget _consentCard() {
    final error = _fieldErrors['consentement_sante'];
    return AppCard(
      borderColor: error == null ? null : MaviohColors.error,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CheckboxListTile(
            value: _consentSante,
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            title: const Text(
              'J’accepte que Mavi’oh calcule mes objectifs à partir de mes données de santé',
              style: TextStyle(fontSize: 13.5, height: 1.4, fontWeight: FontWeight.w600),
            ),
            onChanged: (value) => setState(() {
              _consentSante = value ?? false;
              if (_consentSante) _fieldErrors = Map.of(_fieldErrors)..remove('consentement_sante');
            }),
          ),
          if (error != null)
            Text(error, style: const TextStyle(color: MaviohColors.error, fontSize: 12, height: 1.4)),
        ],
      ),
    );
  }

  // ----- Field builders -----------------------------------------------------

  Widget _textField({
    required String label,
    required TextEditingController controller,
    required String errorKey,
    String? hint,
    int maxLines = 1,
    int? maxLength,
    bool capitalize = false,
  }) {
    return TextField(
      controller: controller,
      maxLines: maxLines,
      maxLength: maxLength,
      textCapitalization: capitalize ? TextCapitalization.words : TextCapitalization.sentences,
      decoration: InputDecoration(
        labelText: label,
        hintText: hint,
        errorText: _fieldErrors[errorKey],
        counterText: '',
      ),
      onChanged: (_) => _onChanged(),
    );
  }

  Widget _numberField({
    required String label,
    required TextEditingController controller,
    required String errorKey,
    String? suffix,
    bool decimal = true,
  }) {
    return TextField(
      controller: controller,
      keyboardType: TextInputType.numberWithOptions(decimal: decimal),
      inputFormatters: [
        FilteringTextInputFormatter.allow(decimal ? RegExp(r'[0-9.,]') : RegExp(r'[0-9]')),
      ],
      decoration: InputDecoration(
        labelText: label,
        suffixText: suffix,
        errorText: _fieldErrors[errorKey],
      ),
      onChanged: (_) => _onChanged(),
    );
  }

  Widget _dropdown({
    required String label,
    required String errorKey,
    required String? value,
    required List<_Option> entries,
    required ValueChanged<String?> onChanged,
  }) {
    return DropdownButtonFormField<String>(
      initialValue: value,
      isExpanded: true,
      decoration: InputDecoration(labelText: label, errorText: _fieldErrors[errorKey]),
      items: [
        for (final entry in entries)
          DropdownMenuItem<String>(
            value: entry.key,
            enabled: entry.enabled,
            child: Text(
              entry.enabled ? entry.label : '${entry.label} · dès 18 ans',
              style: TextStyle(color: entry.enabled ? MaviohColors.text : MaviohColors.muted),
              overflow: TextOverflow.ellipsis,
            ),
          ),
      ],
      onChanged: onChanged,
    );
  }

  Widget _segmented<T>({
    required String label,
    required String errorKey,
    required T? value,
    required List<MapEntry<T, String>> options,
    required ValueChanged<T?> onChanged,
  }) {
    final error = _fieldErrors[errorKey];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: MaviohColors.textTertiary),
        ),
        const SizedBox(height: 8),
        SizedBox(
          width: double.infinity,
          child: SegmentedButton<T>(
            segments: [
              for (final option in options) ButtonSegment<T>(value: option.key, label: Text(option.value)),
            ],
            selected: value == null ? <T>{} : <T>{value},
            emptySelectionAllowed: true,
            showSelectedIcon: false,
            onSelectionChanged: (selection) => onChanged(selection.isEmpty ? null : selection.first),
          ),
        ),
        if (error != null) ...[
          const SizedBox(height: 6),
          Text(error, style: const TextStyle(color: MaviohColors.error, fontSize: 12)),
        ],
      ],
    );
  }

  Widget _chipGroup({
    required String label,
    required String errorKey,
    required List<VocabItem> vocab,
    required Set<String> selected,
    required Color tone,
    String? exclusiveKey,
  }) {
    final error = _fieldErrors[errorKey];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: MaviohColors.textTertiary),
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 4,
          children: [
            for (final item in vocab)
              FilterChip(
                label: Text(item.label),
                selected: selected.contains(item.key),
                selectedColor: MaviohColors.tint(tone, 0.18),
                checkmarkColor: tone,
                onSelected: (on) {
                  setState(() {
                    if (on) {
                      if (exclusiveKey != null && item.key == exclusiveKey) {
                        selected.clear();
                      } else if (exclusiveKey != null) {
                        selected.remove(exclusiveKey);
                      }
                      selected.add(item.key);
                    } else {
                      selected.remove(item.key);
                    }
                  });
                  _onChanged();
                },
              ),
          ],
        ),
        if (error != null) ...[
          const SizedBox(height: 6),
          Text(error, style: const TextStyle(color: MaviohColors.error, fontSize: 12)),
        ],
      ],
    );
  }
}

/// A dropdown entry (disabled entries are shown but not selectable).
class _Option {
  final String key;
  final String label;
  final bool enabled;

  const _Option(this.key, this.label, {this.enabled = true});
}

class _CardHeader extends StatelessWidget {
  final IconData icon;
  final Color tone;
  final String title;
  final String? subtitle;

  const _CardHeader({required this.icon, required this.tone, required this.title, this.subtitle});

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 36,
          height: 36,
          decoration: BoxDecoration(color: MaviohColors.tint(tone), borderRadius: BorderRadius.circular(12)),
          child: Icon(icon, color: tone, size: 19),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: MaviohColors.text)),
              if (subtitle != null) ...[
                const SizedBox(height: 3),
                Text(subtitle!, style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.35)),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _Section extends StatelessWidget {
  final IconData icon;
  final Color tone;
  final String title;
  final String? subtitle;
  final List<Widget> children;

  const _Section({
    required this.icon,
    required this.tone,
    required this.title,
    this.subtitle,
    required this.children,
  });

  @override
  Widget build(BuildContext context) {
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _CardHeader(icon: icon, tone: tone, title: title, subtitle: subtitle),
          const SizedBox(height: 14),
          ...children,
        ],
      ),
    );
  }
}

class _MiniStat extends StatelessWidget {
  final String label;
  final String value;

  const _MiniStat({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        color: MaviohColors.surface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: MaviohColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(label, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: MaviohColors.muted)),
          const SizedBox(height: 2),
          Text(value, style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w800, color: MaviohColors.text)),
        ],
      ),
    );
  }
}

/// Free-text chip list (allergènes, aliments exclus, préférences).
class _ChipInput extends StatefulWidget {
  final String label;
  final String hint;
  final List<String> values;
  final Color tone;
  final ValueChanged<List<String>> onChanged;

  const _ChipInput({
    required this.label,
    required this.hint,
    required this.values,
    required this.tone,
    required this.onChanged,
  });

  @override
  State<_ChipInput> createState() => _ChipInputState();
}

class _ChipInputState extends State<_ChipInput> {
  final TextEditingController _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _add() {
    final text = _controller.text.trim();
    if (text.isEmpty) return;
    final lower = text.toLowerCase();
    if (widget.values.any((v) => v.toLowerCase() == lower)) {
      _controller.clear();
      return;
    }
    widget.onChanged([...widget.values, text]);
    _controller.clear();
  }

  void _remove(String value) {
    widget.onChanged(widget.values.where((v) => v != value).toList());
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          widget.label,
          style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: MaviohColors.textTertiary),
        ),
        const SizedBox(height: 8),
        if (widget.values.isNotEmpty) ...[
          Wrap(
            spacing: 8,
            runSpacing: 4,
            children: [
              for (final value in widget.values)
                Chip(
                  label: Text(value),
                  backgroundColor: MaviohColors.tint(widget.tone, 0.12),
                  side: BorderSide(color: MaviohColors.tint(widget.tone, 0.35)),
                  deleteButtonTooltipMessage: 'Retirer $value',
                  onDeleted: () => _remove(value),
                ),
            ],
          ),
          const SizedBox(height: 8),
        ],
        Row(
          children: [
            Expanded(
              child: TextField(
                controller: _controller,
                decoration: InputDecoration(hintText: widget.hint, isDense: true),
                textInputAction: TextInputAction.done,
                onSubmitted: (_) => _add(),
              ),
            ),
            const SizedBox(width: 8),
            SizedBox(
              height: 48,
              width: 48,
              child: IconButton(
                tooltip: 'Ajouter « ${widget.label} »',
                onPressed: _add,
                icon: const Icon(Icons.add_rounded),
              ),
            ),
          ],
        ),
      ],
    );
  }
}
