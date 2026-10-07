import 'package:dio/dio.dart' show CancelToken;
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/strings.dart';
import '../../models/profile.dart';
import '../../models/sport.dart';
import '../../services/profile_service.dart';
import '../../services/sport_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/status_banner.dart';
import 'lieux_sheet.dart';

/// Result of [GenerateSheet]: the proposal and the request that produced it
/// (so « Régénérer » can replay it with another seed).
class GeneratedProposal {
  final SessionProposal proposal;
  final Map<String, dynamic> request;

  const GeneratedProposal(this.proposal, this.request);
}

/// « Générer une séance » (addendum §D, Sport v2 § 6) — lieu (et donc matériel), sport, objectif,
/// durée, niveau, zones à éviter, notes. Envoie `lieu_id` ; sans lieux (ancien serveur ou
/// aucun lieu créé), retombe sur le choix du type de lieu et du matériel.
///
/// The sheet never pops before a 2xx: on failure it stays open with a
/// [StatusBanner.error]; the IA call (20–60 s) shows a cancellable progress state.
class GenerateSheet extends StatefulWidget {
  const GenerateSheet({
    super.key,
    this.config,
    this.profile,
    this.service,
    this.initialDurationMin,
    this.initialSportType,
    this.initialLieu,
  });

  final SportConfig? config;
  final Profile? profile;
  final SportService? service;
  final int? initialDurationMin;
  final String? initialSportType;
  final String? initialLieu;

  static Future<GeneratedProposal?> show(
    BuildContext context, {
    SportConfig? config,
    Profile? profile,
    SportService? service,
    int? initialDurationMin,
    String? initialSportType,
    String? initialLieu,
  }) {
    return showModalBottomSheet<GeneratedProposal>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      isDismissible: true,
      builder: (_) => GenerateSheet(
        config: config,
        profile: profile,
        service: service,
        initialDurationMin: initialDurationMin,
        initialSportType: initialSportType,
        initialLieu: initialLieu,
      ),
    );
  }

  @override
  State<GenerateSheet> createState() => _GenerateSheetState();
}

class _GenerateSheetState extends State<GenerateSheet> {
  SportService get _service => widget.service ?? SportService();

  static const List<int> _durations = [15, 20, 30, 45, 60, 90];

  /// Equipment preselected when the user trains in a public gym.
  static const List<String> _publicGymEquipment = ['machine', 'barre', 'halteres', 'banc'];

  final TextEditingController _notes = TextEditingController();

  bool _withAi = false;
  String? _sportType;
  String? _lieu;
  String _goal = 'forme';
  String _level = 'debutant';
  int _duration = 30;
  final Set<String> _equipment = <String>{};
  final Set<String> _focus = <String>{};
  final Set<String> _zones = <String>{};

  /// Sport v2 : lieux du compte (null tant que non chargés ou si le serveur ne les connaît pas).
  List<SportLieu>? _lieuxV2;
  int? _lieuId;

  bool _generating = false;
  bool _cancelled = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};
  CancelToken? _cancelToken;

  @override
  void initState() {
    super.initState();
    _withAi = widget.config?.iaDisponible ?? false;
    _duration = widget.initialDurationMin ?? 30;
    _sportType = widget.initialSportType;
    _lieu = widget.initialLieu;
    _loadLieux();
    final profile = widget.profile;
    if (profile != null) {
      _applyProfile(profile);
    } else {
      _loadProfile();
    }
  }

  @override
  void dispose() {
    _cancelToken?.cancel('dispose');
    _notes.dispose();
    super.dispose();
  }

  Future<void> _loadProfile() async {
    try {
      final profile = await ProfileService().get();
      if (!mounted) return;
      setState(() => _applyProfile(profile));
    } on ApiException {
      // Prefill is a convenience: keep the defaults when the profile is unavailable.
    }
  }

  SportConfig get _config => widget.config ?? const SportConfig();

  SportLieu? get _selectedLieu {
    for (final lieu in _lieuxV2 ?? const <SportLieu>[]) {
      if (lieu.id == _lieuId) return lieu;
    }
    return null;
  }

  /// Charge les lieux et présélectionne : le lieu du type demandé (plan), sinon le lieu par défaut.
  Future<void> _loadLieux({int? select}) async {
    try {
      final lieux = await _service.lieux();
      if (!mounted) return;
      setState(() {
        _lieuxV2 = lieux;
        if (select != null && lieux.any((l) => l.id == select)) {
          _lieuId = select;
        } else if (!lieux.any((l) => l.id == _lieuId)) {
          _lieuId = _preselect(lieux)?.id;
        }
      });
    } on ApiException {
      // Serveur sans lieux (ou hors ligne) : on garde type de lieu + matériel.
    }
  }

  SportLieu? _preselect(List<SportLieu> lieux) {
    if (lieux.isEmpty) return null;
    final type = widget.initialLieu;
    if (type != null) {
      final sameType = lieux.where((l) => l.type == type).toList();
      if (sameType.isNotEmpty) return sameType.firstWhere((l) => l.parDefaut, orElse: () => sameType.first);
    }
    return lieux.firstWhere((l) => l.parDefaut, orElse: () => lieux.first);
  }

  Future<void> _addLieu() async {
    final saved = await LieuFormSheet.show(
      context,
      config: _config,
      service: _service,
      premier: (_lieuxV2 ?? const []).isEmpty,
    );
    if (saved == null || !mounted) return;
    setState(() => _lieuId = saved.id);
    await _loadLieux(select: saved.id);
  }

  Future<void> _manageLieux() async {
    await LieuxSheet.show(context, config: _config, service: _service);
    if (!mounted) return;
    await _loadLieux();
  }

  void _applyProfile(Profile profile) {
    _level = profile.sportNiveau ?? _level;
    _goal = profile.sportObjectif ?? _goal;
    _lieu = widget.initialLieu ?? profile.sportLieu ?? _lieu;
    if (widget.initialDurationMin == null && profile.sportTempsDispoMin != null) {
      _duration = profile.sportTempsDispoMin!.clamp(10, 180);
    }
    if (_equipment.isEmpty) _equipment.addAll(profile.sportMateriel);
    if (_focus.isEmpty) _focus.addAll(profile.sportFocus);
    if (_zones.isEmpty) _zones.addAll(profile.sportZonesAEviter);
    if (_notes.text.isEmpty && profile.sportNotes != null) _notes.text = profile.sportNotes!;
  }

  List<VocabItem> get _lieux => widget.config?.lieux ?? AppStrings.sportLieux;

  List<VocabItem> get _objectifs => widget.config?.objectifs ?? AppStrings.sportObjectifs;

  List<VocabItem> get _niveaux => widget.config?.niveaux ?? AppStrings.sportNiveaux;

  List<VocabItem> get _materiel => widget.config?.materiel ?? AppStrings.sportMateriel;

  List<VocabItem> get _focusVocab => widget.config?.focus ?? AppStrings.sportFocus;

  List<VocabItem> get _zonesVocab => widget.config?.zones ?? AppStrings.sportZones;

  void _toggleEquipment(String key) {
    setState(() {
      if (key == 'aucun') {
        _equipment
          ..clear()
          ..add('aucun');
        return;
      }
      _equipment.remove('aucun');
      if (!_equipment.add(key)) _equipment.remove(key);
    });
  }

  void _selectLieu(String key) {
    setState(() {
      _lieu = key;
      if (key == 'salle_publique') {
        _equipment
          ..remove('aucun')
          ..addAll(_publicGymEquipment);
      }
    });
  }

  Map<String, dynamic> _buildRequest({int? seed}) {
    final notes = _notes.text.trim();
    final lieu = _selectedLieu;
    final body = <String, dynamic>{
      'mode': _withAi ? 'ia' : 'regles',
      'goal': _goal,
      'level': _level,
      'duration_min': _duration,
      'sport_type': ?_sportType,
      // Le lieu choisi l'emporte côté serveur ; type et matériel suivent pour les anciens serveurs.
      'lieu_id': ?lieu?.id,
      'lieu': ?(lieu?.type ?? _lieu),
      'equipment': lieu?.materiel ?? _equipment.toList(),
      'focus': _focus.toList(),
      'zones_a_eviter': _zones.toList(),
      if (notes.isNotEmpty) 'notes': notes,
      'seed': ?seed,
    };
    return body;
  }

  Future<void> _submit() async {
    final request = _buildRequest();
    final token = CancelToken();
    setState(() {
      _generating = true;
      _cancelled = false;
      _error = null;
      _fieldErrors = const {};
      _cancelToken = token;
    });
    try {
      final proposal = await _service.generate(request, cancelToken: token);
      if (!mounted) return;
      Navigator.of(context).pop(GeneratedProposal(proposal, request));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _generating = false;
        _cancelToken = null;
        _error = _cancelled ? null : e.message;
        _fieldErrors = {
          'duration_min': ?e.fieldError('duration_min'),
          'goal': ?e.fieldError('goal'),
          'level': ?e.fieldError('level'),
          'notes': ?e.fieldError('notes'),
        };
      });
    }
  }

  void _cancelGeneration() {
    _cancelled = true;
    _cancelToken?.cancel('utilisateur');
    setState(() {
      _generating = false;
      _cancelToken = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final height = (MediaQuery.sizeOf(context).height * 0.92).clamp(440.0, 900.0);
    return SizedBox(
      height: height,
      child: Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Padding(
              padding: EdgeInsets.fromLTRB(20, 4, 20, 0),
              child: Text(
                'Générer une séance',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
              ),
            ),
            Expanded(
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 12),
                children: [
                  if (widget.config?.iaDisponible ?? false)
                    SwitchListTile.adaptive(
                      contentPadding: EdgeInsets.zero,
                      value: _withAi,
                      onChanged: _generating ? null : (value) => setState(() => _withAi = value),
                      title: const Text('Avec l’IA', style: TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text(
                        widget.config?.llmModel == null
                            ? 'Le coach Mavi’oh adapte la séance à ton contexte.'
                            : 'Le coach Mavi’oh adapte la séance à ton contexte (${widget.config!.llmModel}).',
                        style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                      ),
                    ),
                  if (_error != null) ...[
                    StatusBanner.error(_error!, margin: const EdgeInsets.only(bottom: 12)),
                  ],
                  ..._lieuSection(),
                  const SizedBox(height: 16),
                  const _Label('Type de sport'),
                  _ChipWrap(
                    items: AppStrings.sportTypes,
                    selected: _sportType == null ? const {} : {_sportType!},
                    onTap: (key) => setState(() => _sportType = _sportType == key ? null : key),
                  ),
                  const SizedBox(height: 16),
                  const _Label('Objectif'),
                  _ChipWrap(
                    items: _objectifs,
                    selected: {_goal},
                    onTap: (key) => setState(() => _goal = key),
                  ),
                  const SizedBox(height: 16),
                  const _Label('Durée'),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: _durations
                        .map(
                          (d) => ChoiceChip(
                            label: Text('$d min'),
                            selected: _duration == d,
                            onSelected: (_) => setState(() => _duration = d),
                          ),
                        )
                        .toList(),
                  ),
                  if (_fieldErrors['duration_min'] != null) ...[
                    const SizedBox(height: 6),
                    Text(
                      _fieldErrors['duration_min']!,
                      style: const TextStyle(color: MaviohColors.error, fontSize: 12),
                    ),
                  ],
                  const SizedBox(height: 16),
                  const _Label('Niveau'),
                  _ChipWrap(
                    items: _niveaux,
                    selected: {_level},
                    onTap: (key) => setState(() => _level = key),
                  ),
                  if (_selectedLieu == null) ...[
                    const SizedBox(height: 16),
                    const _Label('Matériel'),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: _materiel
                          .map(
                            (item) => FilterChip(
                              label: Text(item.label),
                              selected: _equipment.contains(item.key),
                              onSelected: (_) => _toggleEquipment(item.key),
                            ),
                          )
                          .toList(),
                    ),
                  ],
                  const SizedBox(height: 16),
                  const _Label('Focus'),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: _focusVocab
                        .map(
                          (item) => FilterChip(
                            label: Text(item.label),
                            selected: _focus.contains(item.key),
                            onSelected: (_) => setState(() {
                              if (!_focus.add(item.key)) _focus.remove(item.key);
                            }),
                          ),
                        )
                        .toList(),
                  ),
                  const SizedBox(height: 16),
                  const _Label('Zones à éviter'),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: _zonesVocab
                        .map(
                          (item) => FilterChip(
                            label: Text(item.label),
                            selected: _zones.contains(item.key),
                            onSelected: (_) => setState(() {
                              if (!_zones.add(item.key)) _zones.remove(item.key);
                            }),
                          ),
                        )
                        .toList(),
                  ),
                  const SizedBox(height: 18),
                  TextField(
                    controller: _notes,
                    maxLines: 3,
                    maxLength: 500,
                    textCapitalization: TextCapitalization.sentences,
                    decoration: InputDecoration(
                      labelText: 'Notes pour le coach',
                      hintText: 'Ex. : j’ai mal au genou droit, je veux travailler les fessiers',
                      errorText: _fieldErrors['notes'],
                    ),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 20),
              child: _generating ? _progress() : _cta(),
            ),
          ],
        ),
      ),
    );
  }

  /// Choix du lieu : puces des lieux (matériel résumé dessous), « + Ajouter un lieu », « Gérer ».
  /// Sans lieux côté serveur : l'ancien choix du type de lieu.
  List<Widget> _lieuSection() {
    final lieux = _lieuxV2;
    if (lieux == null) {
      return [
        const _Label('Lieu'),
        _ChipWrap(items: _lieux, selected: _lieu == null ? const {} : {_lieu!}, onTap: _selectLieu),
      ];
    }
    final selected = _selectedLieu;
    return [
      Row(
        children: [
          const Expanded(child: _Label('Lieu')),
          if (lieux.isNotEmpty)
            TextButton.icon(
              onPressed: _generating ? null : _manageLieux,
              icon: const Icon(Icons.edit_location_alt_outlined, size: 18),
              label: const Text('Gérer mes lieux'),
            ),
        ],
      ),
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final lieu in lieux)
            ChoiceChip(
              avatar: Icon(lieuTypeIcon(lieu.type), size: 16),
              label: Text(lieu.nom),
              tooltip: resumeMateriel(lieu.materiel, _config.materielCatalogue, max: 99),
              selected: lieu.id == _lieuId,
              onSelected: _generating ? null : (_) => setState(() => _lieuId = lieu.id == _lieuId ? null : lieu.id),
            ),
          ActionChip(
            avatar: const Icon(Icons.add_rounded, size: 16),
            label: const Text('Ajouter un lieu'),
            onPressed: _generating ? null : _addLieu,
          ),
        ],
      ),
      if (selected != null) ...[
        const SizedBox(height: 8),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Padding(
              padding: EdgeInsets.only(top: 1, right: 6),
              child: Icon(Icons.fitness_center_rounded, size: 14, color: MaviohColors.muted),
            ),
            Expanded(
              child: Text(
                '${selected.typeLabel} · ${resumeMateriel(selected.materiel, _config.materielCatalogue, max: 8)}',
                style: const TextStyle(fontSize: 12.5, color: MaviohColors.textTertiary, height: 1.4),
              ),
            ),
          ],
        ),
      ] else if (lieux.isEmpty) ...[
        const SizedBox(height: 8),
        const Text(
          'Crée ton premier lieu pour que la séance colle à ton matériel. En attendant, choisis-le ci-dessous.',
          style: TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.4),
        ),
        const SizedBox(height: 8),
        _ChipWrap(items: _lieux, selected: _lieu == null ? const {} : {_lieu!}, onTap: _selectLieu),
      ],
    ];
  }

  Widget _cta() {
    return SizedBox(
      height: 52,
      width: double.infinity,
      child: FilledButton.icon(
        onPressed: _submit,
        icon: const Icon(Icons.auto_awesome_rounded),
        label: const Text('Proposer une séance'),
      ),
    );
  }

  Widget _progress() {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          children: [
            const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.4)),
            const SizedBox(width: 12),
            const Expanded(
              child: Text(
                'Le coach prépare ta séance…',
                style: TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
              ),
            ),
            TextButton(onPressed: _cancelGeneration, child: const Text(AppStrings.cancel)),
          ],
        ),
        const SizedBox(height: 4),
        const Text(
          'Cela peut prendre 20 à 60 secondes.',
          style: TextStyle(fontSize: 12, color: MaviohColors.muted),
        ),
      ],
    );
  }
}

/// Small bold section label used inside the sport sheets.
class _Label extends StatelessWidget {
  const _Label(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Text(
        text,
        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
      ),
    );
  }
}

/// Single-choice chip row over a vocabulary.
class _ChipWrap extends StatelessWidget {
  const _ChipWrap({required this.items, required this.selected, required this.onTap});

  final List<VocabItem> items;
  final Set<String> selected;
  final ValueChanged<String> onTap;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: items
          .map(
            (item) => ChoiceChip(
              label: Text(item.label),
              selected: selected.contains(item.key),
              onSelected: (_) => onTap(item.key),
            ),
          )
          .toList(),
    );
  }
}
