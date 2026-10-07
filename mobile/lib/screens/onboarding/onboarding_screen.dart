import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../models/profile.dart';
import '../../services/profile_service.dart';
import '../../services/settings_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/status_banner.dart';

/// Questionnaire de bienvenue : le profil nutritionnel, une question à la fois.
///
/// Écrit ce qu'écrit l'écran Profil (PUT /profile, `objectif_calcul_auto` à vrai) et ne calcule
/// rien : le récapitulatif vient de POST /profile/preview. Le rythme de perte intense n'est pas
/// proposé ici, il demande un avertissement détaillé que l'écran Profil présente.
///
/// Renvoie `true` (via `Navigator.pop`) quand le profil est enregistré, `false` sur « Plus tard ».
class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({super.key, this.profileService, this.settingsService});

  final ProfileService? profileService;
  final SettingsService? settingsService;

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

enum _Etape { identite, mesures, activite, objectif, alimentation, accord, recap }

const Map<_Etape, (String, String)> _textes = {
  _Etape.identite: ('Faisons connaissance', 'Ton âge et ton sexe servent à estimer ce que ton corps dépense au repos.'),
  _Etape.mesures: ('Ta taille et ton poids', 'Ton point de départ. Tu pourras suivre ton poids au fil des semaines.'),
  _Etape.activite: ('Ton activité au quotidien', 'Hors séances de sport : celles-ci s’ajoutent quand tu les enregistres.'),
  _Etape.objectif: ('Ton objectif', 'Mavi’oh ajuste tes calories pour t’y emmener à un rythme raisonnable.'),
  _Etape.alimentation: ('Ta façon de manger', 'Ton régime oriente tes macros, tes recettes et les conseils du coach.'),
  _Etape.accord: ('Ton accord', 'Ces informations sont des données de santé : on te demande la permission.'),
  _Etape.recap: ('Tes objectifs', 'Calculés à partir de tes réponses. Tu pourras les modifier depuis ton profil.'),
};

/// Champs serveur portés par chaque étape, pour y revenir quand le serveur en refuse un.
const Map<_Etape, List<String>> _champsParEtape = {
  _Etape.identite: ['nom', 'sexe', 'age', 'consentement_parental'],
  _Etape.mesures: ['taille', 'poids'],
  _Etape.activite: ['niveau_activite'],
  _Etape.objectif: ['objectif_type', 'poids_souhaite_kg', 'delai_objectif_jours'],
  _Etape.alimentation: ['regime_alimentaire', 'situation_particuliere'],
  _Etape.accord: ['consentement_sante'],
  _Etape.recap: [],
};

const Map<String, String> _niveauAide = {
  'sedentaire': 'Travail assis, peu de marche',
  'leger': 'Debout ou en marche une partie de la journée',
  'modere': 'Actif au quotidien (hors séances enregistrées)',
  'eleve': 'Travail physique ou très actif',
  'tres_eleve': 'Travail très physique',
};

const Set<String> _regimesAdultes = {'keto', 'low_carb', 'jeune_intermittent', 'montignac'};

const List<(int, String)> _delais = [(30, '1 mois'), (90, '3 mois'), (180, '6 mois'), (365, '1 an')];

class _OnboardingScreenState extends State<OnboardingScreen> {
  late final ProfileService _profiles = widget.profileService ?? ProfileService();
  late final SettingsService _settings = widget.settingsService ?? SettingsService();

  final _nom = TextEditingController();
  final _age = TextEditingController();
  final _taille = TextEditingController();
  final _poids = TextEditingController();
  final _poidsSouhaite = TextEditingController();
  final _delai = TextEditingController();
  String? _sexe;
  String? _niveau;
  String? _objectif;
  String? _regime;
  String _situation = 'aucune';
  bool _consentParental = false;
  bool _accord = false;
  bool _accordDonne = false;

  bool _chargement = true;
  int _index = 0;
  Map<String, String> _erreurs = {};
  String? _erreurGlobale;
  bool _enregistrement = false;

  Besoins? _apercu;
  bool _apercuEnCours = false;
  String? _erreurApercu;

  List<_Etape> get _etapes => _Etape.values;
  _Etape get _etape => _etapes[_index];

  @override
  void initState() {
    super.initState();
    _nom.text = Session.instance.user?.name ?? '';
    _charger();
  }

  @override
  void dispose() {
    for (final c in [_nom, _age, _taille, _poids, _poidsSouhaite, _delai]) {
      c.dispose();
    }
    super.dispose();
  }

  /// Pré-remplit depuis le profil existant : on peut revenir au questionnaire plus tard.
  Future<void> _charger() async {
    try {
      final p = await _profiles.get();
      if (!mounted) return;
      setState(() {
        if (p.nom != null && p.nom!.isNotEmpty) _nom.text = p.nom!;
        if (p.age != null) _age.text = '${p.age}';
        if (p.taille != null) _taille.text = fmtDecimal(p.taille, decimals: 0);
        if (p.poids != null) _poids.text = '${p.poids}';
        if (p.poidsSouhaiteKg != null) _poidsSouhaite.text = '${p.poidsSouhaiteKg}';
        if (p.delaiObjectifJours != null) _delai.text = '${p.delaiObjectifJours}';
        _sexe = p.sexe;
        _niveau = p.niveauActivite;
        _objectif = p.objectifType;
        _regime = p.regimeAlimentaire;
        _situation = p.situationParticuliere;
        _consentParental = p.consentementParental;
        _accordDonne = p.consentementSante || (Session.instance.user?.consentementSante ?? false);
        _chargement = false;
      });
    } on ApiException {
      if (!mounted) return;
      setState(() {
        _accordDonne = Session.instance.user?.consentementSante ?? false;
        _chargement = false;
      });
    }
  }

  int? get _ageValeur => int.tryParse(_age.text.trim());
  bool get _mineur => (_ageValeur ?? 99) < 18;
  bool get _objectifPoids => _objectif == 'perdre' || _objectif == 'prendre';

  Map<String, dynamic> _corps() {
    final age = _ageValeur;
    return {
      'nom': _nom.text.trim(),
      'sexe': _sexe,
      'age': age,
      'taille': parseDecimal(_taille.text),
      'poids': parseDecimal(_poids.text),
      'poids_souhaite_kg': _objectifPoids ? parseDecimal(_poidsSouhaite.text) : null,
      'delai_objectif_jours': _objectifPoids ? int.tryParse(_delai.text.trim()) : null,
      'niveau_activite': _niveau,
      'objectif_type': _objectif,
      'regime_alimentaire': _regime,
      'situation_particuliere': _situation,
      if (age != null && age < 18) 'consentement_parental': _consentParental,
      'objectif_calcul_auto': true,
    };
  }

  Map<String, String> _verifier(_Etape etape) {
    final e = <String, String>{};
    switch (etape) {
      case _Etape.identite:
        if (_nom.text.trim().isEmpty) e['nom'] = 'Dis-nous comment t’appeler.';
        if (_sexe == null) e['sexe'] = 'Choisis une réponse.';
        final age = _ageValeur;
        if (age == null || age < 12 || age > 120) {
          e['age'] = 'Mavi’oh s’adresse aux personnes de 12 à 120 ans.';
        } else if (age < 15 && !_consentParental) {
          e['consentement_parental'] = 'Avant 15 ans, l’accord d’un parent ou tuteur est obligatoire.';
        }
      case _Etape.mesures:
        final taille = parseDecimal(_taille.text);
        final poids = parseDecimal(_poids.text);
        if (taille == null || taille < 100 || taille > 300) e['taille'] = 'Entre 100 et 300 cm.';
        if (poids == null || poids < 20 || poids > 500) e['poids'] = 'Entre 20 et 500 kg.';
      case _Etape.activite:
        if (_niveau == null) e['niveau_activite'] = 'Choisis le niveau qui te ressemble le plus.';
      case _Etape.objectif:
        if (_objectif == null) {
          e['objectif_type'] = 'Choisis un objectif.';
        } else if (_objectifPoids) {
          final vise = parseDecimal(_poidsSouhaite.text);
          final poids = parseDecimal(_poids.text);
          if (vise == null || vise < 20 || vise > 500) {
            e['poids_souhaite_kg'] = 'Renseigne le poids que tu vises.';
          } else if (poids != null && _objectif == 'perdre' && vise >= poids) {
            e['poids_souhaite_kg'] = 'Pour perdre du poids, vise un poids inférieur à ton poids actuel.';
          } else if (poids != null && _objectif == 'prendre' && vise <= poids) {
            e['poids_souhaite_kg'] = 'Pour prendre du poids, vise un poids supérieur à ton poids actuel.';
          }
          final delai = int.tryParse(_delai.text.trim());
          if (delai == null || delai < 1 || delai > 2000) {
            e['delai_objectif_jours'] = 'Choisis un délai, ou indique-le en jours.';
          }
        }
      case _Etape.alimentation:
        if (_regime == null) {
          e['regime_alimentaire'] = 'Choisis ton régime, « Omnivore » si tu manges de tout.';
        } else if (_mineur && _regimesAdultes.contains(_regime)) {
          e['regime_alimentaire'] = 'Ce régime n’est pas proposé avant 18 ans.';
        }
      case _Etape.accord:
        if (!_accordDonne && !_accord) {
          e['consentement_sante'] = 'Sans ton accord, Mavi’oh ne peut pas calculer tes objectifs.';
        }
      case _Etape.recap:
        break;
    }
    return e;
  }

  void _suivant() {
    FocusScope.of(context).unfocus();
    if (_etape == _Etape.recap) {
      _enregistrer();
      return;
    }
    final e = _verifier(_etape);
    setState(() {
      _erreurs = e;
      _erreurGlobale = null;
    });
    if (e.isNotEmpty) return;
    setState(() => _index++);
    if (_etape == _Etape.recap) _calculer();
  }

  void _precedent() {
    setState(() {
      _erreurs = {};
      _erreurGlobale = null;
      if (_index > 0) _index--;
    });
  }

  Future<void> _calculer() async {
    setState(() {
      _apercuEnCours = true;
      _erreurApercu = null;
    });
    try {
      final apercu = await _profiles.preview(_corps());
      if (!mounted) return;
      setState(() => _apercu = apercu.besoins);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _erreurApercu = e.message);
    } finally {
      if (mounted) setState(() => _apercuEnCours = false);
    }
  }

  Future<void> _enregistrer() async {
    setState(() {
      _enregistrement = true;
      _erreurGlobale = null;
    });
    final corps = _corps();
    if (!_accordDonne) corps['consentement_sante'] = true;
    try {
      await _profiles.update(corps);
      // Le profil est enregistré : l'échec de cette seconde écriture ne doit pas l'annuler.
      try {
        await _settings.update({'onboarding_termine': true});
      } on ApiException {
        // Sans gravité : le profil complet suffit à ne plus reproposer le questionnaire.
      }
      final me = Session.instance.user;
      if (me != null) Session.instance.updateUser(me.copyWith(hasProfile: true, consentementSante: true));
      Session.instance.invalidate('profile');
      Session.instance.invalidate('dashboard');
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      final erreurs = <String, String>{};
      e.fieldErrors.forEach((champ, messages) {
        if (messages.isNotEmpty) erreurs[champ] = messages.first;
      });
      final cible = _etapes.indexWhere((etape) => _champsParEtape[etape]!.any(erreurs.containsKey));
      setState(() {
        _erreurs = erreurs;
        _erreurGlobale = e.message;
        if (cible >= 0) _index = cible;
      });
    } finally {
      if (mounted) setState(() => _enregistrement = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final (titre, sousTitre) = _textes[_etape]!;
    final derniere = _etape == _Etape.recap;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        if (_index > 0) {
          _precedent();
        } else {
          Navigator.of(context).pop(false);
        }
      },
      child: Scaffold(
        backgroundColor: MaviohColors.background,
        appBar: AppBar(
          title: const Text('Bienvenue sur ${AppStrings.brand}'),
          automaticallyImplyLeading: false,
          actions: [
            TextButton(
              onPressed: _enregistrement ? null : () => Navigator.of(context).pop(false),
              child: const Text('Plus tard'),
            ),
          ],
        ),
        body: _chargement
            ? const Center(child: CircularProgressIndicator())
            : SafeArea(
                child: Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(20, 4, 20, 0),
                      child: Row(
                        children: [
                          Text('Étape ${_index + 1} sur ${_etapes.length}',
                              style: const TextStyle(fontSize: 12, color: MaviohColors.muted, fontWeight: FontWeight.w600)),
                          const SizedBox(width: 12),
                          Expanded(
                            child: ClipRRect(
                              borderRadius: BorderRadius.circular(8),
                              child: LinearProgressIndicator(
                                value: (_index + 1) / _etapes.length,
                                minHeight: 6,
                                backgroundColor: MaviohColors.border,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                    Expanded(
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
                        children: [
                          Semantics(
                            header: true,
                            child: Text(titre,
                                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: MaviohColors.text)),
                          ),
                          const SizedBox(height: 6),
                          Text(sousTitre, style: const TextStyle(color: MaviohColors.textTertiary, height: 1.4)),
                          const SizedBox(height: 20),
                          ..._contenu(),
                          if (_erreurGlobale != null) ...[
                            const SizedBox(height: 16),
                            StatusBanner.error(_erreurGlobale!),
                          ],
                        ],
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.fromLTRB(20, 12, 20, 12),
                      decoration: const BoxDecoration(
                        color: Colors.white,
                        border: Border(top: BorderSide(color: MaviohColors.border)),
                      ),
                      child: Row(
                        children: [
                          if (_index > 0)
                            OutlinedButton(
                              onPressed: _enregistrement ? null : _precedent,
                              child: const Text('Précédent'),
                            ),
                          const Spacer(),
                          FilledButton(
                            onPressed: _enregistrement || (derniere && (_apercuEnCours || _erreurApercu != null))
                                ? null
                                : _suivant,
                            child: _enregistrement
                                ? const SizedBox(
                                    width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                                : Text(derniere ? 'Enregistrer' : 'Suivant'),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
      ),
    );
  }

  List<Widget> _contenu() {
    switch (_etape) {
      case _Etape.identite:
        final age = _ageValeur;
        return [
          _champ(_nom, 'Comment veux-tu qu’on t’appelle ?', 'nom', clavier: TextInputType.name),
          const SizedBox(height: 16),
          _choix(
            'Sexe',
            const {'homme': 'Homme', 'femme': 'Femme'},
            _sexe,
            (v) => _sexe = v,
            'sexe',
            enLigne: true,
          ),
          const SizedBox(height: 16),
          _champ(_age, 'Âge', 'age', clavier: TextInputType.number, onChanged: (_) => setState(() {})),
          if (age != null && age >= 12 && age < 15) ...[
            const SizedBox(height: 12),
            _caseAccord(
              'Un parent ou tuteur autorise l’usage de ces données. Cet accord est obligatoire avant 15 ans.',
              _consentParental,
              (v) => _consentParental = v,
              'consentement_parental',
            ),
          ],
        ];
      case _Etape.mesures:
        return [
          _champ(_taille, 'Taille (cm)', 'taille', clavier: TextInputType.number),
          const SizedBox(height: 16),
          _champ(_poids, 'Poids actuel (kg)', 'poids', clavier: const TextInputType.numberWithOptions(decimal: true)),
        ];
      case _Etape.activite:
        return [
          _choix('Niveau d’activité', AppStrings.niveauActiviteLabels, _niveau, (v) => _niveau = v, 'niveau_activite',
              aides: _niveauAide),
        ];
      case _Etape.objectif:
        return [
          _choix('Objectif', AppStrings.objectifTypeLabels, _objectif, (v) => _objectif = v, 'objectif_type'),
          if (_objectifPoids) ...[
            const SizedBox(height: 16),
            _champ(_poidsSouhaite, 'Poids visé (kg)', 'poids_souhaite_kg',
                clavier: const TextInputType.numberWithOptions(decimal: true)),
            const SizedBox(height: 16),
            const Text('En combien de temps ?', style: TextStyle(fontWeight: FontWeight.w600, color: MaviohColors.textSecondary)),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final (jours, libelle) in _delais)
                  ChoiceChip(
                    label: Text(libelle),
                    selected: _delai.text.trim() == '$jours',
                    onSelected: (_) => setState(() {
                      _delai.text = '$jours';
                      _erreurs = Map.of(_erreurs)..remove('delai_objectif_jours');
                    }),
                  ),
              ],
            ),
            const SizedBox(height: 12),
            _champ(_delai, 'Ou un délai précis, en jours', 'delai_objectif_jours',
                clavier: TextInputType.number,
                aide: 'Si ce délai est trop court pour ta santé, Mavi’oh te proposera un rythme plus sûr.',
                onChanged: (_) => setState(() {})),
          ],
        ];
      case _Etape.alimentation:
        return [
          const Text('Régime alimentaire', style: TextStyle(fontWeight: FontWeight.w600, color: MaviohColors.textSecondary)),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final entry in AppStrings.regimeLabels.entries)
                ChoiceChip(
                  label: Text(entry.value),
                  selected: _regime == entry.key,
                  onSelected: _mineur && _regimesAdultes.contains(entry.key)
                      ? null
                      : (_) => setState(() {
                            _regime = entry.key;
                            _erreurs = Map.of(_erreurs)..remove('regime_alimentaire');
                          }),
                ),
            ],
          ),
          _erreur('regime_alimentaire') ??
              (_mineur
                  ? const Padding(
                      padding: EdgeInsets.only(top: 6),
                      child: Text('Certains régimes restrictifs ne sont pas proposés avant 18 ans.',
                          style: TextStyle(fontSize: 12, color: MaviohColors.muted)),
                    )
                  : const SizedBox.shrink()),
          const SizedBox(height: 20),
          _choix('Une situation particulière ?', AppStrings.situationLabels, _situation, (v) => _situation = v ?? 'aucune',
              'situation_particuliere',
              enLigne: true,
              aide: 'Grossesse, allaitement ou suivi médical : Mavi’oh garde alors ton poids stable.'),
        ];
      case _Etape.accord:
        if (_accordDonne) {
          return const [
            StatusBanner.success(
                'Tu as déjà donné ton accord. Tu peux le retirer à tout moment en supprimant ton compte depuis les paramètres.'),
          ];
        }
        return [
          const Text(
            '• Ton âge, ton sexe, ta taille et ton poids servent uniquement à calculer tes objectifs.\n'
            '• Ils restent sur le serveur de Mavi’oh et ne sont ni vendus ni partagés.\n'
            '• Tu peux les exporter ou supprimer ton compte depuis les paramètres, à tout moment.',
            style: TextStyle(color: MaviohColors.textTertiary, height: 1.5),
          ),
          const SizedBox(height: 16),
          _caseAccord(
            'J’autorise Mavi’oh à utiliser mon âge, mon sexe, ma taille et mon poids pour calculer mes objectifs nutritionnels.',
            _accord,
            (v) => _accord = v,
            'consentement_sante',
          ),
        ];
      case _Etape.recap:
        return [_recap()];
    }
  }

  Widget _recap() {
    if (_erreurApercu != null) {
      return StatusBanner.error(
        _erreurApercu!,
        action: TextButton(onPressed: _calculer, child: const Text(AppStrings.retry)),
      );
    }
    final b = _apercu;
    if (_apercuEnCours || b == null) {
      return const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()));
    }
    final variation = b.variationHebdoKg ?? 0;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(color: MaviohColors.primary, borderRadius: BorderRadius.circular(20)),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('CALORIES PAR JOUR', style: TextStyle(color: Colors.white70, fontSize: 11, letterSpacing: 1.2)),
              const SizedBox(height: 4),
              Text(fmtKcal(b.caloriesRecommandees),
                  style: const TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.w800)),
              const SizedBox(height: 4),
              Text('Dépense estimée ${fmtKcal(b.tdee)} · plancher ${fmtKcal(b.plancherKcal)}',
                  style: const TextStyle(color: Colors.white70, fontSize: 12)),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(child: _macro('Protéines', b.proteinesG)),
            const SizedBox(width: 8),
            Expanded(child: _macro('Glucides', b.glucidesG)),
            const SizedBox(width: 8),
            Expanded(child: _macro('Lipides', b.lipidesG)),
          ],
        ),
        if (variation != 0) ...[
          const SizedBox(height: 12),
          Text(
            'Rythme prévu : ${variation > 0 ? '+' : ''}${fmtDecimal(variation, decimals: 2)} kg par semaine'
            '${b.joursRestants != null ? ', sur ${b.joursRestants} jours' : ''}.',
            style: const TextStyle(color: MaviohColors.textTertiary),
          ),
        ],
        for (final avertissement in b.avertissements) ...[
          const SizedBox(height: 10),
          StatusBanner.warning(avertissement),
        ],
        if (b.avertissements.isNotEmpty && b.delaiConseilleJours != null) ...[
          const SizedBox(height: 10),
          OutlinedButton(
            onPressed: () {
              setState(() => _delai.text = '${b.delaiConseilleJours}');
              _calculer();
            },
            child: Text('Utiliser le délai conseillé (${b.delaiConseilleJours} jours)'),
          ),
        ],
        if (b.rythmeIntensePossible) ...[
          const SizedBox(height: 10),
          const Text(
            'Un rythme de perte plus rapide est possible avec ton accord explicite : il se choisit depuis ton profil, après lecture de ses risques.',
            style: TextStyle(fontSize: 12, color: MaviohColors.muted),
          ),
        ],
        if (b.mention.isNotEmpty) ...[
          const SizedBox(height: 10),
          Text(b.mention, style: const TextStyle(fontSize: 12, color: MaviohColors.muted)),
        ],
      ],
    );
  }

  Widget _macro(String libelle, double? grammes) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: MaviohColors.border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(libelle, maxLines: 1, overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 11, color: MaviohColors.muted)),
            Text('${fmtInt(grammes)} g', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          ],
        ),
      );

  Widget? _erreur(String champ) {
    final message = _erreurs[champ];
    if (message == null) return null;
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Text(message, style: const TextStyle(fontSize: 12, color: MaviohColors.error, fontWeight: FontWeight.w600)),
    );
  }

  Widget _champ(
    TextEditingController controller,
    String libelle,
    String champ, {
    TextInputType? clavier,
    String? aide,
    ValueChanged<String>? onChanged,
  }) {
    return TextField(
      controller: controller,
      keyboardType: clavier,
      textInputAction: TextInputAction.next,
      onChanged: (v) {
        if (_erreurs.containsKey(champ)) setState(() => _erreurs = Map.of(_erreurs)..remove(champ));
        onChanged?.call(v);
      },
      decoration: InputDecoration(labelText: libelle, errorText: _erreurs[champ], helperText: aide, helperMaxLines: 2),
    );
  }

  Widget _choix(
    String libelle,
    Map<String, String> options,
    String? valeur,
    void Function(String?) onChange,
    String champ, {
    Map<String, String>? aides,
    String? aide,
    bool enLigne = false,
  }) {
    final cases = [
      for (final entry in options.entries)
        _CaseChoix(
          libelle: entry.value,
          aide: aides?[entry.key],
          actif: valeur == entry.key,
          onTap: () => setState(() {
            onChange(entry.key);
            _erreurs = Map.of(_erreurs)..remove(champ);
          }),
        ),
    ];
    return Semantics(
      label: libelle,
      container: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(libelle, style: const TextStyle(fontWeight: FontWeight.w600, color: MaviohColors.textSecondary)),
          const SizedBox(height: 8),
          if (enLigne)
            Wrap(spacing: 8, runSpacing: 8, children: cases)
          else
            for (final c in cases) Padding(padding: const EdgeInsets.only(bottom: 8), child: c),
          _erreur(champ) ??
              (aide != null
                  ? Text(aide, style: const TextStyle(fontSize: 12, color: MaviohColors.muted))
                  : const SizedBox.shrink()),
        ],
      ),
    );
  }

  Widget _caseAccord(String texte, bool valeur, void Function(bool) onChange, String champ) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          decoration: BoxDecoration(
            color: MaviohColors.warningBg,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: const Color(0xFFFDE68A)),
          ),
          child: CheckboxListTile(
            value: valeur,
            controlAffinity: ListTileControlAffinity.leading,
            onChanged: (v) => setState(() {
              onChange(v ?? false);
              _erreurs = Map.of(_erreurs)..remove(champ);
            }),
            title: Text(texte, style: const TextStyle(fontSize: 14, color: MaviohColors.warning)),
          ),
        ),
        ?_erreur(champ),
      ],
    );
  }
}

/// Grosse case de choix, confortable au pouce.
class _CaseChoix extends StatelessWidget {
  const _CaseChoix({required this.libelle, required this.actif, required this.onTap, this.aide});

  final String libelle;
  final String? aide;
  final bool actif;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: actif,
      child: Material(
        color: actif ? const Color(0xFFECFDF5) : Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: BorderSide(color: actif ? MaviohColors.primary : MaviohColors.border, width: actif ? 2 : 1),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: onTap,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 48),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(actif ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded,
                      size: 20, color: actif ? MaviohColors.primary : MaviohColors.muted),
                  const SizedBox(width: 10),
                  Flexible(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(libelle, style: const TextStyle(fontWeight: FontWeight.w600, color: MaviohColors.text)),
                        if (aide != null) Text(aide!, style: const TextStyle(fontSize: 12, color: MaviohColors.muted)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
