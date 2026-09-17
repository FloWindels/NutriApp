import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../models/sport.dart';
import '../../services/sport_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/confirm_dialog.dart';
import '../../widgets/macro_pill.dart';
import '../../widgets/status_banner.dart';
import 'sport_icons.dart';

/// What the caller must do after [SessionDetailSheet] closed.
enum SessionDetailAction {
  /// The session changed (completed, cancelled, calories edited…): just refetch.
  changed,

  /// The session was deleted.
  deleted,

  /// The session was started: push `WorkoutSessionScreen`.
  started,
}

/// Outcome of [SessionDetailSheet].
class SessionDetailResult {
  const SessionDetailResult(this.action, this.sessionId);

  final SessionDetailAction action;
  final int sessionId;
}

/// Détail d’une séance (§16.4): démarrer, terminer, annuler, supprimer,
/// corriger les calories (bascule « manuel », addendum §B).
class SessionDetailSheet extends StatefulWidget {
  const SessionDetailSheet({super.key, required this.session, this.service});

  final WorkoutSession session;
  final SportService? service;

  static Future<SessionDetailResult?> show(
    BuildContext context, {
    required WorkoutSession session,
    SportService? service,
  }) {
    return showModalBottomSheet<SessionDetailResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => SessionDetailSheet(session: session, service: service),
    );
  }

  @override
  State<SessionDetailSheet> createState() => _SessionDetailSheetState();
}

class _SessionDetailSheetState extends State<SessionDetailSheet> {
  SportService get _service => widget.service ?? SportService();

  late WorkoutSession _session = widget.session;
  late final TextEditingController _calories =
      TextEditingController(text: _session.caloriesBurned?.round().toString() ?? '');

  bool _busy = false;
  bool _editingCalories = false;
  String? _error;
  String? _caloriesError;

  @override
  void dispose() {
    _calories.dispose();
    super.dispose();
  }

  void _invalidate() {
    Session.instance.invalidatePrefix('sport');
    Session.instance.invalidate('dashboard');
    Session.instance.invalidatePrefix('meals');
  }

  Future<void> _run(Future<WorkoutSession> Function() call, SessionDetailAction action) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final session = await call();
      if (!mounted) return;
      _invalidate();
      Navigator.of(context).pop(SessionDetailResult(action, session.id));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = e.message;
      });
    }
  }

  Future<void> _start() => _run(() => _service.start(_session.id), SessionDetailAction.started);

  Future<void> _complete() => _run(
        () async => (await _service.complete(_session.id, durationMin: _session.durationMin)).session,
        SessionDetailAction.changed,
      );

  Future<void> _cancel() async {
    final ok = await ConfirmDialog.show(
      context,
      title: 'Annuler cette séance ?',
      message: 'Elle restera dans ton historique avec le statut « Annulée ».',
      confirmLabel: 'Annuler la séance',
      cancelLabel: 'Revenir',
      destructive: true,
      icon: Icons.event_busy_outlined,
    );
    if (!ok || !mounted) return;
    await _run(() => _service.cancel(_session.id), SessionDetailAction.changed);
  }

  Future<void> _delete() async {
    final ok = await ConfirmDialog.show(
      context,
      title: 'Supprimer cette séance ?',
      message: 'Elle sera retirée de ton historique et de ton budget calorique.',
      confirmLabel: AppStrings.delete,
      destructive: true,
      icon: Icons.delete_outline_rounded,
    );
    if (!ok || !mounted) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await _service.deleteSession(_session.id);
      if (!mounted) return;
      _invalidate();
      Navigator.of(context).pop(SessionDetailResult(SessionDetailAction.deleted, _session.id));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = e.message;
      });
    }
  }

  Future<void> _saveCalories({required bool auto}) async {
    final value = auto ? null : parseDecimal(_calories.text);
    if (!auto && value == null) {
      setState(() => _caloriesError = 'Indique un nombre de calories.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _caloriesError = null;
    });
    try {
      final session = await _service.setCalories(_session.id, value);
      if (!mounted) return;
      _invalidate();
      setState(() {
        _session = session;
        _busy = false;
        _editingCalories = false;
        _calories.text = session.caloriesBurned?.round().toString() ?? '';
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = e.message;
        _caloriesError = e.fieldError('calories_burned');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = _session;
    final blocks = session.blocks;
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
                  width: 44,
                  height: 44,
                  decoration: BoxDecoration(
                    color: MaviohColors.tint(MaviohColors.primary),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Icon(
                    session.isActivity ? Icons.directions_run : Icons.fitness_center,
                    color: MaviohColors.primary,
                    size: 21,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        session.title,
                        style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: MaviohColors.text),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        '${capitalize(fmtRelativeDay(session.date))} · ${fmtMinutes(session.durationMin)}',
                        style: const TextStyle(color: MaviohColors.muted, fontSize: 12.5),
                      ),
                    ],
                  ),
                ),
                TonePill(label: session.statusLabel, tone: _statusTone(session.status)),
              ],
            ),
            if (_error != null) ...[
              const SizedBox(height: 14),
              StatusBanner.error(_error!),
            ],
            const SizedBox(height: 18),
            Row(
              children: [
                const Text(
                  'Calories brûlées',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
                ),
                const SizedBox(width: 8),
                if (session.isManualCalories)
                  const TonePill(label: 'manuel', tone: MaviohColors.slate, icon: Icons.edit_outlined)
                else
                  const EstimatePill(label: 'auto'),
              ],
            ),
            const SizedBox(height: 6),
            if (_editingCalories) ...[
              TextField(
                controller: _calories,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
                decoration: InputDecoration(
                  labelText: 'Calories brûlées',
                  suffixText: 'kcal',
                  errorText: _caloriesError,
                ),
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: FilledButton(
                      onPressed: _busy ? null : () => _saveCalories(auto: false),
                      child: const Text('Enregistrer'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: OutlinedButton(
                      onPressed: _busy ? null : () => _saveCalories(auto: true),
                      child: const Text('Recalculer'),
                    ),
                  ),
                ],
              ),
            ] else
              Row(
                children: [
                  Text(
                    fmtKcal(session.caloriesBurned),
                    style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: MaviohColors.text),
                  ),
                  const Spacer(),
                  TextButton.icon(
                    onPressed: _busy ? null : () => setState(() => _editingCalories = true),
                    icon: const Icon(Icons.edit_outlined, size: 18),
                    label: const Text('Corriger'),
                  ),
                ],
              ),
            if (session.sportName != null || session.lieu != null || session.distanceKm != null) ...[
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  if (session.sportName != null)
                    TonePill(label: session.sportName!, tone: MaviohColors.primary, icon: sportIconFor(null)),
                  if (session.lieu != null)
                    TonePill(
                      label: AppStrings.vocabLabel(AppStrings.sportLieux, session.lieu),
                      tone: MaviohColors.sky,
                      icon: Icons.place_outlined,
                    ),
                  if (session.distanceKm != null)
                    TonePill(
                      label: '${fmtDecimal(session.distanceKm)} km',
                      tone: MaviohColors.indigo,
                      icon: Icons.straighten_outlined,
                    ),
                  if (session.rpe != null)
                    TonePill(label: 'RPE ${session.rpe}', tone: MaviohColors.amber, icon: Icons.speed_outlined),
                ],
              ),
            ],
            if (blocks.isNotEmpty) ...[
              const SizedBox(height: 18),
              for (final entry in blocks.entries) ...[
                Text(
                  (AppStrings.blockLabels[entry.key] ?? entry.key).toUpperCase(),
                  style: const TextStyle(
                    fontSize: 11,
                    letterSpacing: 1.1,
                    fontWeight: FontWeight.w800,
                    color: MaviohColors.muted,
                  ),
                ),
                const SizedBox(height: 4),
                for (final exercise in entry.value)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 3),
                    child: Row(
                      children: [
                        Icon(
                          exercise.completed ? Icons.check_circle_rounded : Icons.circle_outlined,
                          size: 16,
                          color: exercise.completed ? MaviohColors.primary : MaviohColors.border,
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            exercise.name,
                            style: const TextStyle(fontSize: 13.5, color: MaviohColors.textSecondary),
                          ),
                        ),
                        Text(
                          exercise.prescription,
                          style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: MaviohColors.muted),
                        ),
                      ],
                    ),
                  ),
                const SizedBox(height: 10),
              ],
            ],
            const SizedBox(height: 14),
            if (session.isPlanned)
              SizedBox(
                height: 52,
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: _busy ? null : _start,
                  icon: const Icon(Icons.play_arrow_rounded),
                  label: const Text('Commencer'),
                ),
              )
            else if (session.isInProgress)
              SizedBox(
                height: 52,
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: _busy ? null : _complete,
                  icon: const Icon(Icons.check_rounded),
                  label: const Text('Terminer'),
                ),
              ),
            const SizedBox(height: 10),
            Row(
              children: [
                if (!session.isDone && !session.isCancelled) ...[
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: _busy ? null : _cancel,
                      icon: const Icon(Icons.event_busy_outlined, size: 18),
                      label: const Text('Annuler'),
                    ),
                  ),
                  const SizedBox(width: 10),
                ],
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _busy ? null : _delete,
                    style: OutlinedButton.styleFrom(foregroundColor: MaviohColors.error),
                    icon: const Icon(Icons.delete_outline_rounded, size: 18),
                    label: const Text(AppStrings.delete),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

Color _statusTone(String status) {
  switch (status) {
    case 'terminee':
      return MaviohColors.primary;
    case 'en_cours':
      return MaviohColors.amber;
    case 'annulee':
      return MaviohColors.slate;
    default:
      return MaviohColors.sky;
  }
}
