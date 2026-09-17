import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../models/sport.dart';
import '../../services/sport_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/status_banner.dart';
import 'calories_field.dart';
import 'sport_icons.dart';
import 'sport_picker_sheet.dart';

/// « Activité rapide » (addendum §C.3) — logs a finished activity.
class ActivitySheet extends StatefulWidget {
  const ActivitySheet({super.key, this.config, this.service, this.date, this.sport});

  final SportConfig? config;
  final SportService? service;
  final DateTime? date;
  final Sport? sport;

  static Future<ActivityResult?> show(
    BuildContext context, {
    SportConfig? config,
    SportService? service,
    DateTime? date,
    Sport? sport,
  }) {
    return showModalBottomSheet<ActivityResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => ActivitySheet(config: config, service: service, date: date, sport: sport),
    );
  }

  @override
  State<ActivitySheet> createState() => _ActivitySheetState();
}

class _ActivitySheetState extends State<ActivitySheet> {
  SportService get _service => widget.service ?? SportService();

  static const List<int> _durations = [20, 30, 45, 60, 90];

  final TextEditingController _customDuration = TextEditingController();
  final TextEditingController _distance = TextEditingController();
  final TextEditingController _notes = TextEditingController();

  Sport? _sport;
  int _duration = 30;
  String _intensity = 'moderee';
  double? _calories;
  bool _manualCalories = false;
  bool _saving = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  @override
  void initState() {
    super.initState();
    _sport = widget.sport;
  }

  @override
  void dispose() {
    _customDuration.dispose();
    _distance.dispose();
    _notes.dispose();
    super.dispose();
  }

  List<VocabItem> get _intensities => widget.config?.intensites ?? AppStrings.sportIntensites;

  Future<void> _pickSport() async {
    final sport = await SportPickerSheet.show(context, config: widget.config, service: _service);
    if (sport == null || !mounted) return;
    setState(() => _sport = sport);
  }

  Future<void> _submit() async {
    final sport = _sport;
    if (sport == null) {
      setState(() => _fieldErrors = {'sport_id': 'Choisis d’abord un sport.'});
      return;
    }
    if (_duration < 5 || _duration > 600) {
      setState(() => _fieldErrors = {'duration_min': 'Indique une durée entre 5 et 600 minutes.'});
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
      _fieldErrors = const {};
    });
    try {
      final result = await _service.logActivity(
        date: widget.date,
        sportId: sport.id > 0 ? sport.id : null,
        sportName: sport.id > 0 ? null : sport.name,
        durationMin: _duration,
        intensity: _intensity,
        distanceKm: parseDecimal(_distance.text),
        caloriesBurned: _manualCalories ? _calories : null,
        notes: _notes.text,
      );
      if (!mounted) return;
      Session.instance.invalidatePrefix('sport');
      Session.instance.invalidate('dashboard');
      Session.instance.invalidatePrefix('meals');
      Navigator.of(context).pop(result);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
        _fieldErrors = {
          'sport_id': ?e.fieldError('sport_id'),
          'duration_min': ?e.fieldError('duration_min'),
          'intensity': ?e.fieldError('intensity'),
          'distance_km': ?e.fieldError('distance_km'),
          'calories_burned': ?e.fieldError('calories_burned'),
        };
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final sport = _sport;
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 28),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'Activité rapide',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
            ),
            const SizedBox(height: 4),
            const Text(
              'Enregistre ce que tu viens de faire : les calories brûlées seront réintégrées à ton budget.',
              style: TextStyle(color: MaviohColors.muted, height: 1.4),
            ),
            if (_error != null) ...[
              const SizedBox(height: 14),
              StatusBanner.error(_error!),
            ],
            const SizedBox(height: 18),
            SizedBox(
              height: 56,
              child: OutlinedButton.icon(
                onPressed: _pickSport,
                icon: Icon(sport == null ? Icons.search_rounded : sportIconFor(sport.icon, category: sport.category)),
                label: Align(
                  alignment: Alignment.centerLeft,
                  child: Text(sport?.name ?? 'Choisir un sport'),
                ),
              ),
            ),
            if (_fieldErrors['sport_id'] != null) ...[
              const SizedBox(height: 6),
              Text(_fieldErrors['sport_id']!, style: const TextStyle(color: MaviohColors.error, fontSize: 12)),
            ],
            const SizedBox(height: 18),
            const Text(
              'Durée',
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                ..._durations.map(
                  (d) => ChoiceChip(
                    label: Text('$d min'),
                    selected: _duration == d && _customDuration.text.isEmpty,
                    onSelected: (_) {
                      _customDuration.clear();
                      setState(() => _duration = d);
                    },
                  ),
                ),
                SizedBox(
                  width: 110,
                  child: TextField(
                    controller: _customDuration,
                    keyboardType: TextInputType.number,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    onChanged: (value) {
                      final parsed = int.tryParse(value.trim());
                      if (parsed != null) setState(() => _duration = parsed);
                    },
                    decoration: const InputDecoration(
                      isDense: true,
                      hintText: 'Autre',
                      suffixText: 'min',
                    ),
                  ),
                ),
              ],
            ),
            if (_fieldErrors['duration_min'] != null) ...[
              const SizedBox(height: 6),
              Text(_fieldErrors['duration_min']!, style: const TextStyle(color: MaviohColors.error, fontSize: 12)),
            ],
            const SizedBox(height: 18),
            const Text(
              'Intensité',
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
            ),
            const SizedBox(height: 8),
            SegmentedButton<String>(
              segments: _intensities.map((i) => ButtonSegment<String>(value: i.key, label: Text(i.label))).toList(),
              selected: {_intensity},
              showSelectedIcon: false,
              onSelectionChanged: (value) => setState(() => _intensity = value.first),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _distance,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
              decoration: InputDecoration(
                labelText: 'Distance (km) — optionnel',
                errorText: _fieldErrors['distance_km'],
              ),
            ),
            const SizedBox(height: 18),
            SportCaloriesField(
              durationMin: _duration,
              sportId: (sport != null && sport.id > 0) ? sport.id : null,
              sportName: (sport != null && sport.id <= 0) ? sport.name : null,
              intensity: _intensity,
              service: _service,
              errorText: _fieldErrors['calories_burned'],
              onChanged: (calories, manual) {
                _calories = calories;
                _manualCalories = manual;
              },
            ),
            const SizedBox(height: 18),
            TextField(
              controller: _notes,
              maxLines: 2,
              textCapitalization: TextCapitalization.sentences,
              decoration: const InputDecoration(labelText: 'Notes — optionnel'),
            ),
            const SizedBox(height: 22),
            SizedBox(
              height: 52,
              child: FilledButton.icon(
                onPressed: _saving ? null : _submit,
                icon: _saving
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                      )
                    : const Icon(Icons.check_rounded),
                label: const Text('Enregistrer l’activité'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
