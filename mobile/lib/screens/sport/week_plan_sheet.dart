import 'package:dio/dio.dart' show CancelToken;
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../core/session.dart';
import '../../models/sport.dart';
import '../../services/sport_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/status_banner.dart';

/// « Planifier ma semaine » (addendum §C.2 `plan-week`).
///
/// Never pops before a 2xx: failures keep the sheet open with a
/// [StatusBanner.error]. The IA mode may take 20 à 60 s and can be cancelled.
class WeekPlanSheet extends StatefulWidget {
  const WeekPlanSheet({super.key, required this.weekStart, this.config, this.service, this.initialDays = const []});

  /// Monday of the week to fill.
  final DateTime weekStart;

  final SportConfig? config;
  final SportService? service;

  /// Weekdays (1 = lundi … 7 = dimanche) preselected.
  final List<int> initialDays;

  static Future<SportCalendar?> show(
    BuildContext context, {
    required DateTime weekStart,
    SportConfig? config,
    SportService? service,
    List<int> initialDays = const [],
  }) {
    return showModalBottomSheet<SportCalendar>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => WeekPlanSheet(
        weekStart: weekStart,
        config: config,
        service: service,
        initialDays: initialDays,
      ),
    );
  }

  @override
  State<WeekPlanSheet> createState() => _WeekPlanSheetState();
}

class _WeekPlanSheetState extends State<WeekPlanSheet> {
  SportService get _service => widget.service ?? SportService();

  /// L M M J V S D (weekday 1 → 7).
  static const List<String> _initials = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
  static const List<String> _names = [
    'Lundi',
    'Mardi',
    'Mercredi',
    'Jeudi',
    'Vendredi',
    'Samedi',
    'Dimanche',
  ];

  final Set<int> _days = <int>{};

  bool _withAi = false;
  bool _replace = false;
  bool _saving = false;
  bool _cancelled = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};
  CancelToken? _cancelToken;

  @override
  void initState() {
    super.initState();
    _withAi = widget.config?.iaDisponible ?? false;
    _days.addAll(widget.initialDays.where((d) => d >= 1 && d <= 7));
  }

  @override
  void dispose() {
    _cancelToken?.cancel('dispose');
    super.dispose();
  }

  Future<void> _submit() async {
    final token = CancelToken();
    setState(() {
      _saving = true;
      _cancelled = false;
      _error = null;
      _fieldErrors = const {};
      _cancelToken = token;
    });
    try {
      final days = _days.toList()..sort();
      final calendar = await _service.planWeek(
        weekStart: widget.weekStart,
        days: days.isEmpty ? null : days,
        mode: _withAi ? 'ia' : 'regles',
        replace: _replace,
        cancelToken: token,
      );
      if (!mounted) return;
      Session.instance.invalidatePrefix('sport');
      Session.instance.invalidate('dashboard');
      Navigator.of(context).pop(calendar);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _cancelToken = null;
        _error = _cancelled ? null : e.message;
        _fieldErrors = {
          'days': ?e.fieldError('days'),
          'week_start': ?e.fieldError('week_start'),
          'mode': ?e.fieldError('mode'),
        };
      });
    }
  }

  void _cancelGeneration() {
    _cancelled = true;
    _cancelToken?.cancel('utilisateur');
    setState(() {
      _saving = false;
      _cancelToken = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final end = widget.weekStart.add(const Duration(days: 6));
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 28),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'Planifier ma semaine',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
            ),
            const SizedBox(height: 4),
            Text(
              'Du ${fmtDayShort(widget.weekStart)} au ${fmtDayShort(end)}',
              style: const TextStyle(color: MaviohColors.muted),
            ),
            if (_error != null) ...[
              const SizedBox(height: 14),
              StatusBanner.error(_error!),
            ],
            const SizedBox(height: 18),
            const Text(
              'Jours d’entraînement',
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (var weekday = 1; weekday <= 7; weekday++)
                  FilterChip(
                    label: Text(_initials[weekday - 1]),
                    tooltip: _names[weekday - 1],
                    selected: _days.contains(weekday),
                    onSelected: _saving
                        ? null
                        : (selected) => setState(() {
                              if (selected) {
                                _days.add(weekday);
                              } else {
                                _days.remove(weekday);
                              }
                            }),
                  ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              _days.isEmpty
                  ? 'Aucun jour choisi : Mavi’oh répartit tes séances selon ton profil.'
                  : '${_days.length} jour${_days.length > 1 ? 's' : ''} sélectionné${_days.length > 1 ? 's' : ''}.',
              style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted, height: 1.35),
            ),
            if (_fieldErrors['days'] != null) ...[
              const SizedBox(height: 6),
              Text(_fieldErrors['days']!, style: const TextStyle(color: MaviohColors.error, fontSize: 12)),
            ],
            const SizedBox(height: 10),
            if (widget.config?.iaDisponible ?? false)
              SwitchListTile.adaptive(
                contentPadding: EdgeInsets.zero,
                value: _withAi,
                onChanged: _saving ? null : (value) => setState(() => _withAi = value),
                title: const Text('Avec l’IA', style: TextStyle(fontWeight: FontWeight.w700)),
                subtitle: const Text(
                  'Le coach Mavi’oh répartit tes séances selon ton objectif et ton historique.',
                  style: TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                ),
              ),
            SwitchListTile.adaptive(
              contentPadding: EdgeInsets.zero,
              value: _replace,
              onChanged: _saving ? null : (value) => setState(() => _replace = value),
              title: const Text('Remplacer', style: TextStyle(fontWeight: FontWeight.w700)),
              subtitle: const Text(
                'Efface les séances déjà prévues cette semaine avant d’ajouter les nouvelles.',
                style: TextStyle(fontSize: 12.5, color: MaviohColors.muted),
              ),
            ),
            if (_fieldErrors['mode'] != null || _fieldErrors['week_start'] != null) ...[
              const SizedBox(height: 6),
              Text(
                _fieldErrors['mode'] ?? _fieldErrors['week_start']!,
                style: const TextStyle(color: MaviohColors.error, fontSize: 12),
              ),
            ],
            const SizedBox(height: 20),
            if (_saving) ...[
              Row(
                children: [
                  const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.4)),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Text(
                      'Le coach prépare ta semaine…',
                      style: TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
                    ),
                  ),
                  TextButton(onPressed: _cancelGeneration, child: const Text('Annuler')),
                ],
              ),
            ] else
              SizedBox(
                height: 52,
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: _submit,
                  icon: const Icon(Icons.auto_awesome_outlined),
                  label: const Text('Planifier ma semaine'),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
