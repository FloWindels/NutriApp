import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/strings.dart';
import '../../models/sport.dart';
import '../../services/sport_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/confirm_dialog.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/error_state.dart';
import '../../widgets/loading_state.dart';
import '../../widgets/macro_pill.dart';
import '../../widgets/status_banner.dart';

/// Icône d'un type de lieu.
IconData lieuTypeIcon(String type) {
  switch (type) {
    case 'exterieur':
      return Icons.park_outlined;
    case 'salle_publique':
      return Icons.fitness_center_rounded;
    case 'salle_privee':
      return Icons.business_outlined;
    default:
      return Icons.home_outlined;
  }
}

/// « Mes lieux » (Sport v2, § 1) : liste, création, modification, suppression, lieu par défaut.
///
/// Renvoie `true` quand la liste a changé via « Fermer » ; fermée autrement (glisser, retour),
/// elle renvoie null : l'appelant recharge alors ses lieux par prudence.
class LieuxSheet extends StatefulWidget {
  const LieuxSheet({super.key, this.config, this.service});

  final SportConfig? config;
  final SportService? service;

  static Future<bool?> show(BuildContext context, {SportConfig? config, SportService? service}) {
    return showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => LieuxSheet(config: config, service: service),
    );
  }

  @override
  State<LieuxSheet> createState() => _LieuxSheetState();
}

class _LieuxSheetState extends State<LieuxSheet> {
  SportService get _service => widget.service ?? SportService();

  late SportConfig _config = widget.config ?? const SportConfig();
  List<SportLieu>? _lieux;
  bool _loading = true;
  bool _busy = false;
  bool _changed = false;
  String? _error;
  String? _actionError;

  @override
  void initState() {
    super.initState();
    _load();
    if (widget.config == null) _loadConfig();
  }

  Future<void> _loadConfig() async {
    try {
      final config = await _service.config();
      if (mounted) setState(() => _config = config);
    } on ApiException {
      // Le catalogue local suffit.
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = _lieux == null;
      _error = null;
    });
    try {
      final lieux = await _service.lieux();
      if (!mounted) return;
      setState(() {
        _lieux = lieux;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (_lieux == null) {
          _error = e.message;
        } else {
          _actionError = e.message;
        }
      });
    }
  }

  Future<void> _edit([SportLieu? lieu]) async {
    final saved = await LieuFormSheet.show(
      context,
      config: _config,
      service: _service,
      lieu: lieu,
      premier: (_lieux ?? const []).isEmpty,
    );
    if (saved == null || !mounted) return;
    _changed = true;
    await _load();
  }

  Future<void> _setDefault(SportLieu lieu) async {
    setState(() {
      _busy = true;
      _actionError = null;
    });
    try {
      await _service.updateLieu(lieu.id, nom: lieu.nom, type: lieu.type, materiel: lieu.materiel, parDefaut: true);
      _changed = true;
      await _load();
    } on ApiException catch (e) {
      if (mounted) setState(() => _actionError = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _delete(SportLieu lieu) async {
    final ok = await ConfirmDialog.show(
      context,
      title: 'Supprimer « ${lieu.nom} » ?',
      message: lieu.parDefaut
          ? 'C’est ton lieu par défaut : le plus ancien de tes autres lieux le remplacera.'
          : 'Tes séances passées restent dans ton historique.',
      confirmLabel: AppStrings.delete,
      destructive: true,
      icon: Icons.delete_outline_rounded,
    );
    if (!ok || !mounted) return;
    setState(() {
      _busy = true;
      _actionError = null;
    });
    try {
      await _service.deleteLieu(lieu.id);
      _changed = true;
      await _load();
    } on ApiException catch (e) {
      if (mounted) setState(() => _actionError = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final height = (MediaQuery.sizeOf(context).height * 0.85).clamp(420.0, 800.0);
    return SizedBox(
        height: height,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 12, 0),
              child: Row(
                children: [
                  const Expanded(
                    child: Text(
                      'Mes lieux d’entraînement',
                      style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
                    ),
                  ),
                  IconButton(
                    tooltip: AppStrings.close,
                    onPressed: () => Navigator.of(context).pop(_changed),
                    icon: const Icon(Icons.close_rounded),
                  ),
                ],
              ),
            ),
            const Padding(
              padding: EdgeInsets.fromLTRB(20, 0, 20, 8),
              child: Text(
                'Chaque lieu a son matériel : la séance se génère avec ce que tu as sous la main.',
                style: TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.4),
              ),
            ),
            Expanded(child: _body()),
            SafeArea(
              minimum: const EdgeInsets.fromLTRB(20, 4, 20, 16),
              child: SizedBox(
                height: 50,
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: _busy || _lieux == null ? null : () => _edit(),
                  icon: const Icon(Icons.add_location_alt_outlined),
                  label: const Text('Ajouter un lieu'),
                ),
              ),
            ),
          ],
        ),
    );
  }

  Widget _body() {
    if (_loading && _lieux == null) return const LoadingState();
    if (_error != null && _lieux == null) return ErrorState(message: _error!, onRetry: _load, compact: true);
    final lieux = _lieux ?? const <SportLieu>[];
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(20, 4, 20, 12),
      children: [
        if (_actionError != null)
          StatusBanner.error(
            _actionError!,
            margin: const EdgeInsets.only(bottom: 12),
            onClose: () => setState(() => _actionError = null),
          ),
        if (lieux.isEmpty)
          const EmptyState(
            icon: Icons.place_outlined,
            title: 'Aucun lieu pour l’instant',
            message: 'Ajoute ta maison, ta salle ou ton parc : Mavi’oh saura quel matériel tu as.',
            compact: true,
          )
        else
          for (final lieu in lieux) _LieuTile(
            lieu: lieu,
            catalogue: _config.materielCatalogue,
            enabled: !_busy,
            onEdit: () => _edit(lieu),
            onDefault: () => _setDefault(lieu),
            onDelete: () => _delete(lieu),
          ),
      ],
    );
  }
}

class _LieuTile extends StatelessWidget {
  const _LieuTile({
    required this.lieu,
    required this.catalogue,
    required this.enabled,
    required this.onEdit,
    required this.onDefault,
    required this.onDelete,
  });

  final SportLieu lieu;
  final List<MaterielItem> catalogue;
  final bool enabled;
  final VoidCallback onEdit;
  final VoidCallback onDefault;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      elevation: 0,
      color: Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(18),
        side: BorderSide(color: lieu.parDefaut ? MaviohColors.primary.withValues(alpha: 0.4) : MaviohColors.border),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: enabled ? onEdit : null,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 4, 12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: MaviohColors.tint(MaviohColors.primary),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(lieuTypeIcon(lieu.type), color: MaviohColors.primary, size: 20),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Wrap(
                      spacing: 8,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text(
                          lieu.nom,
                          style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                        ),
                        if (lieu.parDefaut)
                          const TonePill(label: 'Par défaut', tone: MaviohColors.primary, icon: Icons.star_rounded),
                      ],
                    ),
                    const SizedBox(height: 3),
                    Text(
                      '${lieu.typeLabel} · ${resumeMateriel(lieu.materiel, catalogue)}',
                      style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.35),
                    ),
                  ],
                ),
              ),
              PopupMenuButton<String>(
                tooltip: 'Actions sur ${lieu.nom}',
                enabled: enabled,
                onSelected: (value) {
                  if (value == 'edit') onEdit();
                  if (value == 'default') onDefault();
                  if (value == 'delete') onDelete();
                },
                itemBuilder: (_) => [
                  const PopupMenuItem<String>(value: 'edit', child: Text(AppStrings.edit)),
                  if (!lieu.parDefaut)
                    const PopupMenuItem<String>(value: 'default', child: Text('Définir par défaut')),
                  const PopupMenuItem<String>(value: 'delete', child: Text(AppStrings.delete)),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Création / modification d'un lieu : nom, type (avec son préréglage), matériel par catégorie,
/// lieu par défaut. Ne se ferme qu'après un 2xx ; renvoie le lieu enregistré.
class LieuFormSheet extends StatefulWidget {
  const LieuFormSheet({super.key, required this.config, this.service, this.lieu, this.premier = false});

  final SportConfig config;
  final SportService? service;
  final SportLieu? lieu;

  /// Premier lieu du compte : il sera le lieu par défaut.
  final bool premier;

  static Future<SportLieu?> show(
    BuildContext context, {
    SportConfig? config,
    SportService? service,
    SportLieu? lieu,
    bool premier = false,
  }) {
    return showModalBottomSheet<SportLieu>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => LieuFormSheet(
        config: config ?? const SportConfig(),
        service: service,
        lieu: lieu,
        premier: premier,
      ),
    );
  }

  @override
  State<LieuFormSheet> createState() => _LieuFormSheetState();
}

class _LieuFormSheetState extends State<LieuFormSheet> {
  SportService get _service => widget.service ?? SportService();

  late final TextEditingController _nom = TextEditingController(text: widget.lieu?.nom ?? '');
  late String _type = widget.lieu?.type ?? 'maison';
  late final Set<String> _materiel = widget.lieu == null
      ? widget.config.presetPour(widget.lieu?.type ?? 'maison').toSet()
      : widget.lieu!.materiel.toSet();
  late bool _parDefaut = widget.lieu?.parDefaut ?? widget.premier;

  /// Le matériel a été retouché à la main : changer de type ne l'écrase plus.
  late bool _materielTouche = widget.lieu != null;

  bool _saving = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  @override
  void dispose() {
    _nom.dispose();
    super.dispose();
  }

  List<VocabItem> get _types => widget.config.lieux;

  void _selectType(String type) {
    setState(() {
      final ancienLibelle = AppStrings.vocabLabel(_types, _type);
      _type = type;
      if (!_materielTouche) {
        _materiel
          ..clear()
          ..addAll(widget.config.presetPour(type));
      }
      // Un nom vide ou resté sur le libellé du type suit le type.
      if (_nom.text.trim().isEmpty || _nom.text.trim() == ancienLibelle) {
        _nom.text = AppStrings.vocabLabel(_types, type);
      }
    });
  }

  void _applyPreset() {
    setState(() {
      _materiel
        ..clear()
        ..addAll(widget.config.presetPour(_type));
      _materielTouche = false;
    });
  }

  void _toggle(String valeur) {
    setState(() {
      _materielTouche = true;
      if (!_materiel.add(valeur)) _materiel.remove(valeur);
    });
  }

  Future<void> _save() async {
    final nom = _nom.text.trim();
    final errors = <String, String>{};
    if (nom.isEmpty) errors['nom'] = 'Donne un nom à ce lieu.';
    if (nom.length > 60) errors['nom'] = '60 caractères au maximum.';
    if (_materiel.isEmpty) errors['materiel'] = 'Choisis au moins un équipement (ou « Poids du corps »).';
    if (errors.isNotEmpty) {
      setState(() => _fieldErrors = errors);
      return;
    }
    // Ordre stable : celui du catalogue.
    final ordre = widget.config.materielCatalogue.map((m) => m.valeur).toList();
    final materiel = _materiel.toList()
      ..sort((a, b) {
        final ia = ordre.indexOf(a);
        final ib = ordre.indexOf(b);
        return (ia < 0 ? 999 : ia).compareTo(ib < 0 ? 999 : ib);
      });
    setState(() {
      _saving = true;
      _error = null;
      _fieldErrors = const {};
    });
    try {
      final lieu = widget.lieu;
      final saved = lieu == null
          ? await _service.createLieu(nom: nom, type: _type, materiel: materiel, parDefaut: _parDefaut)
          : await _service.updateLieu(lieu.id, nom: nom, type: _type, materiel: materiel, parDefaut: _parDefaut);
      if (!mounted) return;
      Navigator.of(context).pop(saved);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
        _fieldErrors = {
          'nom': ?e.fieldError('nom'),
          'type': ?e.fieldError('type'),
          'materiel': ?e.fieldError('materiel'),
        };
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final height = (MediaQuery.sizeOf(context).height * 0.9).clamp(440.0, 880.0);
    final catalogue = widget.config.materielCatalogue;
    final typeLabel = AppStrings.vocabLabel(_types, _type);
    return SizedBox(
      height: height,
      child: Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 0),
              child: Text(
                widget.lieu == null ? 'Nouveau lieu' : 'Modifier le lieu',
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
              ),
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 12),
                children: [
                  if (_error != null) StatusBanner.error(_error!, margin: const EdgeInsets.only(bottom: 12)),
                  TextField(
                    controller: _nom,
                    maxLength: 60,
                    textCapitalization: TextCapitalization.sentences,
                    decoration: InputDecoration(
                      labelText: 'Nom du lieu',
                      hintText: 'Ex. : Maison, Basic-Fit Lyon 3, Parc de la Tête d’Or',
                      errorText: _fieldErrors['nom'],
                    ),
                  ),
                  const SizedBox(height: 8),
                  const _Label('Type de lieu'),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final t in _types)
                        ChoiceChip(
                          avatar: Icon(lieuTypeIcon(t.key), size: 16),
                          label: Text(t.label),
                          selected: _type == t.key,
                          onSelected: (_) => _selectType(t.key),
                        ),
                    ],
                  ),
                  if (_fieldErrors['type'] != null) _FieldError(_fieldErrors['type']!),
                  const SizedBox(height: 18),
                  const _Label('Matériel disponible'),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton.icon(
                      onPressed: _applyPreset,
                      icon: const Icon(Icons.restart_alt_rounded, size: 18),
                      label: Text('Remettre le préréglage « $typeLabel »'),
                    ),
                  ),
                  if (_fieldErrors['materiel'] != null) _FieldError(_fieldErrors['materiel']!),
                  for (final cat in categoriesMateriel) ...[
                    if (catalogue.any((m) => m.categorie == cat.key)) ...[
                      const SizedBox(height: 6),
                      Text(
                        cat.label.toUpperCase(),
                        style: const TextStyle(
                          fontSize: 11,
                          letterSpacing: 1.1,
                          fontWeight: FontWeight.w800,
                          color: MaviohColors.muted,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          for (final m in catalogue.where((m) => m.categorie == cat.key))
                            FilterChip(
                              label: Text(m.libelle),
                              selected: _materiel.contains(m.valeur),
                              onSelected: (_) => _toggle(m.valeur),
                            ),
                        ],
                      ),
                      const SizedBox(height: 8),
                    ],
                  ],
                  // Matériel inconnu du catalogue (ancienne valeur) : affiché pour pouvoir le retirer.
                  if (_materiel.any((v) => !catalogue.any((m) => m.valeur == v)))
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        for (final v in _materiel.where((v) => !catalogue.any((m) => m.valeur == v)))
                          FilterChip(label: Text(v), selected: true, onSelected: (_) => _toggle(v)),
                      ],
                    ),
                  const SizedBox(height: 8),
                  SwitchListTile.adaptive(
                    contentPadding: EdgeInsets.zero,
                    value: _parDefaut,
                    onChanged: (widget.lieu?.parDefaut ?? false) ? null : (v) => setState(() => _parDefaut = v),
                    title: const Text('Lieu par défaut', style: TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: const Text(
                      'Présélectionné quand tu génères une séance.',
                      style: TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                    ),
                  ),
                ],
              ),
            ),
            SafeArea(
              minimum: const EdgeInsets.fromLTRB(20, 4, 20, 16),
              child: SizedBox(
                height: 52,
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: _saving ? null : _save,
                  icon: _saving
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                        )
                      : const Icon(Icons.check_rounded),
                  label: const Text(AppStrings.save),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

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

class _FieldError extends StatelessWidget {
  const _FieldError(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 6, bottom: 4),
      child: Text(text, style: const TextStyle(color: MaviohColors.error, fontSize: 12)),
    );
  }
}
