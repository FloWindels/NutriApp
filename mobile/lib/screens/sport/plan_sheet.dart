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
import 'sport_icons.dart';
import 'sport_picker_sheet.dart';

/// « Planifier » (addendum §C.2) — creates or edits a calendar plan.
///
/// Returns `true` once the API answered 2xx (the sheet never pops before).
class PlanSheet extends StatefulWidget {
  const PlanSheet({super.key, required this.date, this.plan, this.config, this.service});

  final DateTime date;
  final SportPlan? plan;
  final SportConfig? config;
  final SportService? service;

  static Future<bool?> show(
    BuildContext context, {
    required DateTime date,
    SportPlan? plan,
    SportConfig? config,
    SportService? service,
  }) {
    return showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => PlanSheet(date: date, plan: plan, config: config, service: service),
    );
  }

  @override
  State<PlanSheet> createState() => _PlanSheetState();
}

class _PlanSheetState extends State<PlanSheet> {
  SportService get _service => widget.service ?? SportService();

  static const List<int> _durations = [20, 30, 45, 60, 90];

  final TextEditingController _customDuration = TextEditingController();
  late final TextEditingController _notes = TextEditingController(text: widget.plan?.notes ?? '');

  late DateTime _date = widget.date;
  int? _sportId;
  String? _sportName;
  String? _sportIcon;
  String? _sportCategory;
  int _duration = 45;
  TimeOfDay? _time;
  String? _lieu;
  bool _repeat = false;
  int _weeks = 4;

  bool _saving = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  @override
  void initState() {
    super.initState();
    final plan = widget.plan;
    if (plan != null) {
      _date = plan.date;
      _sportId = plan.sportId;
      _sportName = plan.sportName;
      _sportIcon = plan.sportIcon;
      _duration = plan.plannedDurationMin;
      _lieu = plan.lieu;
      final parts = plan.plannedAt?.split(':');
      if (parts != null && parts.length >= 2) {
        final h = int.tryParse(parts[0]);
        final m = int.tryParse(parts[1]);
        if (h != null && m != null) _time = TimeOfDay(hour: h, minute: m);
      }
      if (!_durations.contains(_duration)) _customDuration.text = _duration.toString();
    }
  }

  @override
  void dispose() {
    _customDuration.dispose();
    _notes.dispose();
    super.dispose();
  }

  bool get _isEdit => widget.plan != null;

  List<VocabItem> get _lieux => widget.config?.lieux ?? AppStrings.sportLieux;

  Future<void> _pickSport() async {
    final sport = await SportPickerSheet.show(context, config: widget.config, service: _service);
    if (sport == null || !mounted) return;
    setState(() {
      _sportId = sport.id > 0 ? sport.id : null;
      _sportName = sport.name;
      _sportIcon = sport.icon;
      _sportCategory = sport.category;
    });
  }

  Future<void> _pickDate() async {
    final now = today();
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: now.subtract(const Duration(days: 365)),
      lastDate: now.add(const Duration(days: 365)),
      helpText: 'Date de la séance',
    );
    if (picked == null || !mounted) return;
    setState(() => _date = picked);
  }

  Future<void> _pickTime() async {
    final picked = await showTimePicker(
      context: context,
      initialTime: _time ?? const TimeOfDay(hour: 18, minute: 0),
      helpText: 'Heure de la séance',
    );
    if (picked == null || !mounted) return;
    setState(() => _time = picked);
  }

  String? get _plannedAt => _time == null
      ? null
      : '${_time!.hour.toString().padLeft(2, '0')}:${_time!.minute.toString().padLeft(2, '0')}';

  Future<void> _submit() async {
    if (_sportName == null) {
      setState(() => _fieldErrors = {'sport_id': 'Choisis d’abord un sport.'});
      return;
    }
    if (_duration < 5 || _duration > 600) {
      setState(() => _fieldErrors = {'planned_duration_min': 'Indique une durée entre 5 et 600 minutes.'});
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
      _fieldErrors = const {};
    });
    try {
      final notes = _notes.text.trim();
      if (_isEdit) {
        await _service.updatePlan(widget.plan!.id, <String, dynamic>{
          'date': isoDate(_date),
          'sport_id': ?_sportId,
          if (_sportId == null) 'sport_name': _sportName,
          'planned_duration_min': _duration,
          'planned_at': _plannedAt,
          'lieu': _lieu,
          'notes': notes.isEmpty ? null : notes,
        });
      } else if (_repeat) {
        await _service.createRecurringPlan(
          weekday: _date.weekday,
          sportId: _sportId,
          sportName: _sportId == null ? _sportName : null,
          plannedDurationMin: _duration,
          plannedAt: _plannedAt,
          lieu: _lieu,
          notes: notes,
          weeks: _weeks,
          startDate: _date,
        );
      } else {
        await _service.createPlan(
          date: _date,
          sportId: _sportId,
          sportName: _sportId == null ? _sportName : null,
          plannedDurationMin: _duration,
          plannedAt: _plannedAt,
          lieu: _lieu,
          notes: notes,
        );
      }
      if (!mounted) return;
      Session.instance.invalidatePrefix('sport');
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
        _fieldErrors = {
          'sport_id': ?e.fieldError('sport_id'),
          'sport_name': ?e.fieldError('sport_name'),
          'planned_duration_min': ?e.fieldError('planned_duration_min'),
          'planned_at': ?e.fieldError('planned_at'),
          'weeks': ?e.fieldError('weeks'),
        };
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final height = (MediaQuery.sizeOf(context).height * 0.9).clamp(440.0, 860.0);
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
                _isEdit ? 'Modifier la séance prévue' : 'Planifier une séance',
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
              ),
            ),
            Expanded(
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.fromLTRB(20, 14, 20, 12),
                children: [
                  if (_error != null) ...[
                    StatusBanner.error(_error!, margin: const EdgeInsets.only(bottom: 14)),
                  ],
                  SizedBox(
                    height: 56,
                    child: OutlinedButton.icon(
                      onPressed: _pickSport,
                      icon: Icon(
                        _sportName == null
                            ? Icons.search_rounded
                            : sportIconFor(_sportIcon, category: _sportCategory),
                      ),
                      label: Align(
                        alignment: Alignment.centerLeft,
                        child: Text(_sportName ?? 'Choisir un sport'),
                      ),
                    ),
                  ),
                  if (_fieldErrors['sport_id'] != null || _fieldErrors['sport_name'] != null) ...[
                    const SizedBox(height: 6),
                    Text(
                      _fieldErrors['sport_id'] ?? _fieldErrors['sport_name']!,
                      style: const TextStyle(color: MaviohColors.error, fontSize: 12),
                    ),
                  ],
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: _pickDate,
                          icon: const Icon(Icons.calendar_today_outlined, size: 18),
                          label: Text(capitalize(fmtDay(_date))),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: _pickTime,
                          icon: const Icon(Icons.schedule_outlined, size: 18),
                          label: Text(_plannedAt ?? 'Heure'),
                        ),
                      ),
                    ],
                  ),
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
                          decoration: const InputDecoration(isDense: true, hintText: 'Autre', suffixText: 'min'),
                        ),
                      ),
                    ],
                  ),
                  if (_fieldErrors['planned_duration_min'] != null) ...[
                    const SizedBox(height: 6),
                    Text(
                      _fieldErrors['planned_duration_min']!,
                      style: const TextStyle(color: MaviohColors.error, fontSize: 12),
                    ),
                  ],
                  const SizedBox(height: 18),
                  const Text(
                    'Lieu',
                    style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
                  ),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: _lieux
                        .map(
                          (item) => ChoiceChip(
                            label: Text(item.label),
                            selected: _lieu == item.key,
                            onSelected: (_) => setState(() => _lieu = _lieu == item.key ? null : item.key),
                          ),
                        )
                        .toList(),
                  ),
                  const SizedBox(height: 18),
                  TextField(
                    controller: _notes,
                    maxLines: 2,
                    textCapitalization: TextCapitalization.sentences,
                    decoration: const InputDecoration(labelText: 'Notes — optionnel'),
                  ),
                  if (!_isEdit) ...[
                    const SizedBox(height: 10),
                    SwitchListTile.adaptive(
                      contentPadding: EdgeInsets.zero,
                      value: _repeat,
                      onChanged: (value) => setState(() => _repeat = value),
                      title: const Text('Répéter chaque semaine', style: TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text(
                        'Chaque ${fmtWeekdayShort(_date)} pendant $_weeks semaine${_weeks > 1 ? 's' : ''}',
                        style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                      ),
                    ),
                    if (_repeat)
                      Slider(
                        value: _weeks.toDouble(),
                        min: 1,
                        max: 12,
                        divisions: 11,
                        label: '$_weeks semaines',
                        onChanged: (value) => setState(() => _weeks = value.round()),
                      ),
                    if (_fieldErrors['weeks'] != null)
                      Text(_fieldErrors['weeks']!, style: const TextStyle(color: MaviohColors.error, fontSize: 12)),
                  ],
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 20),
              child: SizedBox(
                height: 52,
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: _saving ? null : _submit,
                  icon: _saving
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                        )
                      : const Icon(Icons.event_available_rounded),
                  label: Text(_isEdit ? 'Enregistrer' : 'Planifier'),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
