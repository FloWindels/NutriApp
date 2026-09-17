import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../core/strings.dart';
import '../../models/sport.dart';
import '../../services/sport_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/error_state.dart';
import '../../widgets/loading_state.dart';
import '../../widgets/status_banner.dart';
import 'sport_icons.dart';

/// Searchable sport catalog (addendum §C.1) with a « Créer « … » » row.
///
/// Returns the chosen (or newly created) [Sport], or null.
class SportPickerSheet extends StatefulWidget {
  const SportPickerSheet({super.key, this.config, this.service});

  final SportConfig? config;
  final SportService? service;

  static Future<Sport?> show(BuildContext context, {SportConfig? config, SportService? service}) {
    return showModalBottomSheet<Sport>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => SportPickerSheet(config: config, service: service),
    );
  }

  @override
  State<SportPickerSheet> createState() => _SportPickerSheetState();
}

class _SportPickerSheetState extends State<SportPickerSheet> {
  SportService get _service => widget.service ?? SportService();

  final TextEditingController _search = TextEditingController();

  List<Sport> _sports = const [];
  bool _loading = true;
  String? _error;
  String _query = '';
  bool _creating = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final sports = await _service.sports();
      if (!mounted) return;
      setState(() {
        _sports = sports;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    }
  }

  List<Sport> get _filtered {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return _sports;
    return _sports.where((s) => s.name.toLowerCase().contains(q)).toList();
  }

  List<VocabItem> get _categories => widget.config?.categoriesSport ?? AppStrings.sportCategories;

  Future<void> _openCreateForm(String initialName) async {
    setState(() => _creating = true);
    final created = await showModalBottomSheet<Sport>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _CreateSportForm(
        initialName: initialName,
        categories: _categories,
        service: _service,
      ),
    );
    if (!mounted) return;
    setState(() => _creating = false);
    if (created != null) Navigator.of(context).pop(created);
  }

  @override
  Widget build(BuildContext context) {
    final height = (MediaQuery.sizeOf(context).height * 0.88).clamp(420.0, 760.0);
    final filtered = _filtered;
    final query = _query.trim();

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
                'Choisis ton sport',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 12),
              child: TextField(
                controller: _search,
                autofocus: false,
                textInputAction: TextInputAction.search,
                onChanged: (value) => setState(() => _query = value),
                decoration: InputDecoration(
                  hintText: 'Rechercher un sport',
                  prefixIcon: const Icon(Icons.search_rounded),
                  suffixIcon: query.isEmpty
                      ? null
                      : IconButton(
                          tooltip: 'Effacer',
                          onPressed: () {
                            _search.clear();
                            setState(() => _query = '');
                          },
                          icon: const Icon(Icons.close_rounded),
                        ),
                ),
              ),
            ),
            Expanded(
              child: Builder(
                builder: (context) {
                  if (_loading) return const LoadingState();
                  if (_error != null && _sports.isEmpty) {
                    return ErrorState(message: _error!, onRetry: _load, compact: true);
                  }
                  return ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(12, 0, 12, 24),
                    children: [
                      if (query.isNotEmpty)
                        ListTile(
                          minVerticalPadding: 12,
                          leading: Container(
                            width: 40,
                            height: 40,
                            decoration: BoxDecoration(
                              color: MaviohColors.tint(MaviohColors.accent, 0.18),
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: const Icon(Icons.add_rounded, color: MaviohColors.primary),
                          ),
                          title: Text(
                            'Créer « $query »',
                            style: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
                          ),
                          subtitle: const Text('Ajoute un sport qui manque au catalogue'),
                          onTap: _creating ? null : () => _openCreateForm(query),
                        ),
                      if (filtered.isEmpty && query.isEmpty)
                        const Padding(
                          padding: EdgeInsets.all(24),
                          child: Text('Aucun sport disponible pour le moment.', textAlign: TextAlign.center),
                        ),
                      ..._buildGroups(filtered),
                    ],
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
  }

  List<Widget> _buildGroups(List<Sport> sports) {
    final widgets = <Widget>[];
    final order = _categories.map((c) => c.key).toList();
    final grouped = <String, List<Sport>>{};
    for (final sport in sports) {
      grouped.putIfAbsent(sport.category, () => <Sport>[]).add(sport);
    }
    final keys = grouped.keys.toList()
      ..sort((a, b) {
        final ia = order.indexOf(a);
        final ib = order.indexOf(b);
        return (ia < 0 ? 99 : ia).compareTo(ib < 0 ? 99 : ib);
      });

    for (final key in keys) {
      final list = grouped[key]!..sort((a, b) => a.name.compareTo(b.name));
      widgets.add(
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 14, 12, 6),
          child: Text(
            AppStrings.vocabLabel(_categories, key).toUpperCase(),
            style: const TextStyle(fontSize: 11, letterSpacing: 1.1, fontWeight: FontWeight.w800, color: MaviohColors.muted),
          ),
        ),
      );
      for (final sport in list) {
        widgets.add(
          ListTile(
            minVerticalPadding: 10,
            leading: Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: MaviohColors.tint(MaviohColors.primary),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(sportIconFor(sport.icon, category: sport.category), color: MaviohColors.primary, size: 20),
            ),
            title: Text(sport.name, style: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text)),
            subtitle: Text('MET ${fmtDecimal(sport.metModeree)} · modérée'),
            trailing: sport.isMine
                ? const Chip(label: Text('Mon sport'), visualDensity: VisualDensity.compact)
                : null,
            onTap: () => Navigator.of(context).pop(sport),
          ),
        );
      }
    }
    return widgets;
  }
}

/// Mini form « Créer un sport » (`POST /sport/sports`).
class _CreateSportForm extends StatefulWidget {
  const _CreateSportForm({required this.initialName, required this.categories, required this.service});

  final String initialName;
  final List<VocabItem> categories;
  final SportService service;

  @override
  State<_CreateSportForm> createState() => _CreateSportFormState();
}

class _CreateSportFormState extends State<_CreateSportForm> {
  late final TextEditingController _name = TextEditingController(text: widget.initialName);
  final TextEditingController _met = TextEditingController();

  String _category = 'autre';
  bool _saving = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  @override
  void initState() {
    super.initState();
    if (widget.categories.isNotEmpty && !widget.categories.any((c) => c.key == 'autre')) {
      _category = widget.categories.first.key;
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _met.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final name = _name.text.trim();
    if (name.length < 2) {
      setState(() => _fieldErrors = {'name': 'Donne un nom d’au moins 2 caractères.'});
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
      _fieldErrors = const {};
    });
    try {
      final sport = await widget.service.createSport(
        name: name,
        category: _category,
        metModeree: parseDecimal(_met.text),
      );
      if (!mounted) return;
      Navigator.of(context).pop(sport);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
        _fieldErrors = {
          'name': ?e.fieldError('name'),
          'category': ?e.fieldError('category'),
          'met_moderee': ?e.fieldError('met_moderee'),
        };
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'Créer un sport',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
            ),
            const SizedBox(height: 4),
            const Text(
              'Il sera visible uniquement par toi.',
              style: TextStyle(color: MaviohColors.muted),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              StatusBanner.error(_error!),
            ],
            const SizedBox(height: 16),
            TextField(
              controller: _name,
              textCapitalization: TextCapitalization.sentences,
              decoration: InputDecoration(labelText: 'Nom du sport', errorText: _fieldErrors['name']),
            ),
            const SizedBox(height: 14),
            const Text(
              'Catégorie',
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: widget.categories
                  .map(
                    (c) => ChoiceChip(
                      label: Text(c.label),
                      selected: _category == c.key,
                      onSelected: (_) => setState(() => _category = c.key),
                    ),
                  )
                  .toList(),
            ),
            if (_fieldErrors['category'] != null) ...[
              const SizedBox(height: 6),
              Text(_fieldErrors['category']!, style: const TextStyle(color: MaviohColors.error, fontSize: 12)),
            ],
            const SizedBox(height: 14),
            TextField(
              controller: _met,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(
                labelText: 'MET (optionnel)',
                helperText: 'Intensité modérée, entre 1,5 et 15. Laisse vide si tu ne sais pas.',
                errorText: _fieldErrors['met_moderee'],
              ),
            ),
            const SizedBox(height: 20),
            SizedBox(
              height: 50,
              child: FilledButton(
                onPressed: _saving ? null : _submit,
                child: _saving
                    ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                    : const Text('Créer le sport'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
