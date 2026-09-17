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

/// « J’ai fait cette séance » — logs a calendar plan (`POST /sport/calendar/{id}/log`).
class LogSheet extends StatefulWidget {
  const LogSheet({super.key, required this.plan, this.config, this.service});

  final SportPlan plan;
  final SportConfig? config;
  final SportService? service;

  static Future<PlanLogResult?> show(
    BuildContext context, {
    required SportPlan plan,
    SportConfig? config,
    SportService? service,
  }) {
    return showModalBottomSheet<PlanLogResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => LogSheet(plan: plan, config: config, service: service),
    );
  }

  @override
  State<LogSheet> createState() => _LogSheetState();
}

class _LogSheetState extends State<LogSheet> {
  SportService get _service => widget.service ?? SportService();

  late final TextEditingController _duration =
      TextEditingController(text: widget.plan.plannedDurationMin.toString());
  final TextEditingController _distance = TextEditingController();
  final TextEditingController _notes = TextEditingController();

  String _intensity = 'moderee';
  double? _calories;
  bool _manualCalories = false;
  bool _saving = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  @override
  void dispose() {
    _duration.dispose();
    _distance.dispose();
    _notes.dispose();
    super.dispose();
  }

  int get _durationMin => parseDecimal(_duration.text)?.round() ?? 0;

  List<VocabItem> get _intensities => widget.config?.intensites ?? AppStrings.sportIntensites;

  Future<void> _submit() async {
    final duration = _durationMin;
    if (duration < 5 || duration > 600) {
      setState(() => _fieldErrors = {'duration_min': 'Indique une durée entre 5 et 600 minutes.'});
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
      _fieldErrors = const {};
    });
    try {
      final result = await _service.logPlan(
        widget.plan.id,
        durationMin: duration,
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
          'duration_min': ?e.fieldError('duration_min'),
          'intensity': ?e.fieldError('intensity'),
          'distance_km': ?e.fieldError('distance_km'),
          'calories_burned': ?e.fieldError('calories_burned'),
          'notes': ?e.fieldError('notes'),
        };
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final plan = widget.plan;
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 28),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              children: [
                Container(
                  width: 42,
                  height: 42,
                  decoration: BoxDecoration(
                    color: MaviohColors.tint(MaviohColors.primary),
                    borderRadius: BorderRadius.circular(13),
                  ),
                  child: Icon(sportIconFor(plan.sportIcon), color: MaviohColors.primary, size: 20),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        plan.sportName,
                        style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: MaviohColors.text),
                      ),
                      Text(
                        '${capitalize(fmtRelativeDay(plan.date))} · prévu ${fmtMinutes(plan.plannedDurationMin)}',
                        style: const TextStyle(color: MaviohColors.muted, fontSize: 12.5),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            if (_error != null) ...[
              const SizedBox(height: 14),
              StatusBanner.error(_error!),
            ],
            const SizedBox(height: 18),
            TextField(
              controller: _duration,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(
                labelText: 'Durée réelle (min)',
                errorText: _fieldErrors['duration_min'],
              ),
            ),
            const SizedBox(height: 16),
            const Text(
              'Intensité',
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
            ),
            const SizedBox(height: 8),
            SegmentedButton<String>(
              segments: _intensities
                  .map((i) => ButtonSegment<String>(value: i.key, label: Text(i.label)))
                  .toList(),
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
              durationMin: _durationMin,
              sportId: plan.sportId,
              sportName: plan.sportId == null ? plan.sportName : null,
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
              maxLines: 3,
              textCapitalization: TextCapitalization.sentences,
              decoration: InputDecoration(
                labelText: 'Notes — optionnel',
                errorText: _fieldErrors['notes'],
              ),
            ),
            const SizedBox(height: 22),
            SizedBox(
              height: 52,
              child: FilledButton.icon(
                onPressed: _saving ? null : _submit,
                icon: _saving
                    ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                    : const Icon(Icons.check_rounded),
                label: const Text('Enregistrer la séance'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
