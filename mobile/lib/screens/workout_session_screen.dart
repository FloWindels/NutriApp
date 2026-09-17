import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/sport.dart';
import '../services/sport_service.dart';
import '../theme/app_theme.dart';
import '../widgets/app_card.dart';
import '../widgets/confirm_dialog.dart';
import '../widgets/error_state.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/status_banner.dart';
import 'sport/sport_icons.dart';

/// Secure-storage key holding the session the user is currently doing.
const String activeWorkoutSessionKey = 'active_workout_session_id';

/// Séance en cours (§16.4, addendum §D) — pushed screen, owns its Scaffold.
///
/// Pops `'budget'` when the user asks to see the daily budget, `true` after a
/// completion, or null otherwise.
class WorkoutSessionScreen extends StatefulWidget {
  const WorkoutSessionScreen({super.key, required this.sessionId});

  /// Identifiant de la séance (`workout_sessions.id`).
  final int sessionId;

  @override
  State<WorkoutSessionScreen> createState() => _WorkoutSessionScreenState();
}

class _WorkoutSessionScreenState extends State<WorkoutSessionScreen> {
  final SportService _service = SportService();

  WorkoutSession? _session;
  List<WorkoutExercise> _exercises = const [];
  SessionCompleteResult? _result;

  bool _loading = true;
  bool _busy = false;
  String? _error;
  String? _actionError;

  Timer? _ticker;
  Timer? _restTimer;
  Duration _elapsed = Duration.zero;
  int _restLeft = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _restTimer?.cancel();
    super.dispose();
  }

  // ----- Loading -------------------------------------------------------------

  Future<void> _load() async {
    setState(() {
      _loading = _session == null;
      _error = null;
    });
    try {
      final session = await _service.session(widget.sessionId);
      if (!mounted) return;
      setState(() {
        _session = session;
        _exercises = List<WorkoutExercise>.from(session.exercises);
        _loading = false;
        _error = null;
      });
      _syncTicker();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (_session == null) {
          _error = e.message;
        } else {
          _actionError = e.message;
        }
      });
    }
  }

  void _syncTicker() {
    _ticker?.cancel();
    final session = _session;
    if (session == null || session.startedAt == null || !session.isInProgress) {
      setState(() => _elapsed = Duration(minutes: session?.durationMin ?? 0));
      return;
    }
    _tick();
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) => _tick());
  }

  void _tick() {
    final startedAt = _session?.startedAt;
    if (startedAt == null || !mounted) return;
    final elapsed = DateTime.now().difference(startedAt);
    setState(() => _elapsed = elapsed.isNegative ? Duration.zero : elapsed);
  }

  /// Persists (or clears) the active session id — storage failures are ignored.
  Future<void> _rememberActiveSession(int? id) async {
    try {
      if (id == null) {
        await ApiClient.instance.deleteSecure(activeWorkoutSessionKey);
      } else {
        await ApiClient.instance.writeSecure(activeWorkoutSessionKey, id.toString());
      }
    } catch (_) {
      // Secure storage unavailable: the API remains the source of truth.
    }
  }

  void _invalidateCaches() {
    Session.instance.invalidatePrefix('sport');
    Session.instance.invalidate('dashboard');
    Session.instance.invalidatePrefix('meals');
  }

  // ----- Actions -------------------------------------------------------------

  Future<void> _start() async {
    setState(() {
      _busy = true;
      _actionError = null;
    });
    try {
      final session = await _service.start(widget.sessionId);
      await _rememberActiveSession(session.id);
      _invalidateCaches();
      if (!mounted) return;
      setState(() {
        _session = session;
        _busy = false;
      });
      _syncTicker();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _actionError = e.message;
      });
    }
  }

  void _toggleExercise(int index, bool value) {
    final exercise = _exercises[index];
    setState(() {
      _exercises[index] = exercise.copyWith(completed: value);
    });
    if (value && exercise.restSec != null && exercise.restSec! > 0) {
      _startRest(exercise.restSec!);
    }
  }

  void _startRest(int seconds) {
    _restTimer?.cancel();
    setState(() => _restLeft = seconds);
    HapticFeedback.selectionClick();
    _restTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      setState(() => _restLeft -= 1);
      if (_restLeft <= 0) {
        timer.cancel();
        HapticFeedback.mediumImpact();
      }
    });
  }

  void _stopRest() {
    _restTimer?.cancel();
    setState(() => _restLeft = 0);
  }

  Future<void> _editExercise(int index) async {
    final exercise = _exercises[index];
    final edited = await showModalBottomSheet<_ExerciseEdit>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _ExerciseEditSheet(exercise: exercise),
    );
    if (edited == null || !mounted) return;
    setState(() {
      _exercises[index] = exercise.copyWith(reps: edited.reps, weightKg: edited.weightKg);
    });
  }

  Future<void> _complete() async {
    final minutes = _elapsed.inMinutes > 0 ? _elapsed.inMinutes : (_session?.durationMin ?? 30);
    final input = await showModalBottomSheet<_CompleteInput>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _CompleteSheet(
        initialDurationMin: minutes,
        onSubmit: (duration, rpe) => _service.complete(
          widget.sessionId,
          durationMin: duration,
          rpe: rpe,
          exercises: _exercises
              .map((e) => <String, dynamic>{
                    'id': e.id,
                    'completed': e.completed,
                    'sets': ?e.sets,
                    'reps': ?e.reps,
                    'weight_kg': ?e.weightKg,
                  })
              .toList(),
        ),
      ),
    );
    if (input == null || !mounted) return;
    _ticker?.cancel();
    _stopRest();
    await _rememberActiveSession(null);
    _invalidateCaches();
    if (!mounted) return;
    setState(() {
      _result = input.result;
      _session = input.result.session;
    });
  }

  Future<void> _confirmLeave() async {
    final leave = await ConfirmDialog.show(
      context,
      title: 'Quitter ?',
      message: 'La séance restera « en cours ». Tu pourras la reprendre depuis l’onglet Sport.',
      confirmLabel: 'Quitter',
      cancelLabel: 'Rester',
      icon: Icons.logout_rounded,
    );
    if (leave && mounted) Navigator.of(context).pop();
  }

  // ----- Build ---------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final session = _session;
    final canPop = _result != null || session == null || !session.isInProgress;

    return PopScope<Object?>(
      canPop: canPop,
      onPopInvokedWithResult: (didPop, result) {
        if (didPop) return;
        _confirmLeave();
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(session?.title ?? 'Séance'),
          actions: [
            if (session != null && session.isInProgress && _result == null)
              IconButton(
                tooltip: 'Terminer la séance',
                onPressed: _busy ? null : _complete,
                icon: const Icon(Icons.flag_outlined),
              ),
          ],
        ),
        body: _body(),
        bottomNavigationBar: _bottomBar(),
      ),
    );
  }

  Widget _body() {
    if (_loading && _session == null) return const LoadingState();
    if (_error != null && _session == null) return ErrorState(message: _error!, onRetry: _load);
    final session = _session!;
    if (_result != null) return _ResultView(result: _result!, onBudget: () => Navigator.of(context).pop('budget'));

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
        children: [
          _TimerCard(session: session, elapsed: _elapsed),
          if (_actionError != null) ...[
            const SizedBox(height: 12),
            StatusBanner.error(_actionError!, onClose: () => setState(() => _actionError = null)),
          ],
          if (_restLeft > 0) ...[
            const SizedBox(height: 12),
            StatusBanner.info(
              'Repos : ${fmtDuration(Duration(seconds: _restLeft))}',
              action: TextButton(onPressed: _stopRest, child: const Text('Passer le repos')),
            ),
          ],
          const SizedBox(height: 14),
          if (_exercises.isEmpty)
            const AppCard(
              child: Text(
                'Cette séance n’a pas d’exercice détaillé : suis ton plan et enregistre la durée réelle à la fin.',
                style: TextStyle(color: MaviohColors.textTertiary, height: 1.45),
              ),
            )
          else
            ..._buildBlocks(),
        ],
      ),
    );
  }

  List<Widget> _buildBlocks() {
    final widgets = <Widget>[];
    final keys = <String>[];
    for (final exercise in _exercises) {
      if (!keys.contains(exercise.block)) keys.add(exercise.block);
    }
    keys.sort((a, b) {
      const order = ['echauffement', 'principal', 'retour_au_calme'];
      final ia = order.indexOf(a);
      final ib = order.indexOf(b);
      return (ia < 0 ? 99 : ia).compareTo(ib < 0 ? 99 : ib);
    });

    for (final key in keys) {
      widgets.add(
        AppCard(
          margin: const EdgeInsets.only(bottom: 14),
          padding: const EdgeInsets.fromLTRB(8, 12, 8, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(10, 0, 10, 6),
                child: Row(
                  children: [
                    Icon(blockIcon(key), size: 18, color: MaviohColors.primary),
                    const SizedBox(width: 8),
                    Text(
                      AppStrings.blockLabels[key] ?? key,
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                    ),
                  ],
                ),
              ),
              for (var i = 0; i < _exercises.length; i++)
                if (_exercises[i].block == key) _exerciseTile(i),
            ],
          ),
        ),
      );
    }
    return widgets;
  }

  Widget _exerciseTile(int index) {
    final exercise = _exercises[index];
    final editable = _session?.isInProgress ?? false;
    return CheckboxListTile(
      value: exercise.completed,
      onChanged: editable ? (value) => _toggleExercise(index, value ?? false) : null,
      controlAffinity: ListTileControlAffinity.leading,
      contentPadding: const EdgeInsets.symmetric(horizontal: 8),
      title: Text(
        exercise.name,
        style: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
      ),
      subtitle: Padding(
        padding: const EdgeInsets.only(top: 6),
        child: Wrap(
          spacing: 8,
          runSpacing: 6,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            if (exercise.prescription.isNotEmpty)
              TonePill(label: exercise.prescription, tone: MaviohColors.slate, icon: Icons.repeat_rounded),
            if (exercise.weightKg != null)
              TonePill(
                label: '${fmtDecimal(exercise.weightKg)} kg',
                tone: MaviohColors.primary,
                icon: Icons.fitness_center,
              ),
            if (exercise.restSec != null)
              TonePill(label: 'repos ${exercise.restSec} s', tone: MaviohColors.sky, icon: Icons.timer_outlined),
          ],
        ),
      ),
      secondary: IconButton(
        tooltip: 'Modifier répétitions et charge',
        onPressed: () => _editExercise(index),
        icon: const Icon(Icons.tune_rounded, size: 20),
        constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
      ),
    );
  }

  Widget? _bottomBar() {
    final session = _session;
    if (session == null || _result != null) return null;
    if (session.isDone || session.isCancelled) return null;

    return SafeArea(
      minimum: const EdgeInsets.fromLTRB(20, 8, 20, 12),
      child: SizedBox(
        height: 52,
        child: session.isInProgress
            ? FilledButton.icon(
                onPressed: _busy ? null : _complete,
                icon: const Icon(Icons.flag_rounded),
                label: const Text('Terminer'),
              )
            : FilledButton.icon(
                onPressed: _busy ? null : _start,
                icon: _busy
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                      )
                    : const Icon(Icons.play_arrow_rounded),
                label: const Text('Démarrer la séance'),
              ),
      ),
    );
  }
}

/// Sticky elapsed timer computed from `started_at`.
class _TimerCard extends StatelessWidget {
  const _TimerCard({required this.session, required this.elapsed});

  final WorkoutSession session;
  final Duration elapsed;

  @override
  Widget build(BuildContext context) {
    return AppCard.hero(
      child: Row(
        children: [
          Container(
            width: 46,
            height: 46,
            decoration: BoxDecoration(
              color: MaviohColors.tint(MaviohColors.primary),
              borderRadius: BorderRadius.circular(14),
            ),
            child: const Icon(Icons.timer_outlined, color: MaviohColors.primary),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  session.isInProgress ? 'Temps écoulé' : session.statusLabel,
                  style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: MaviohColors.muted),
                ),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(
                    fmtDuration(elapsed),
                    style: const TextStyle(fontSize: 30, fontWeight: FontWeight.w800, color: MaviohColors.text),
                  ),
                ),
                Text(
                  'Prévu : ${fmtMinutes(session.durationMin)}',
                  style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Result card shown after `POST /sport/sessions/{id}/complete`.
class _ResultView extends StatelessWidget {
  const _ResultView({required this.result, required this.onBudget});

  final SessionCompleteResult result;
  final VoidCallback onBudget;

  @override
  Widget build(BuildContext context) {
    final burned = result.session.caloriesBurned ?? result.nutrition.caloriesBurned;
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
      children: [
        AppCard.hero(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    width: 46,
                    height: 46,
                    decoration: BoxDecoration(
                      color: MaviohColors.tint(MaviohColors.success),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: const Icon(Icons.check_circle_outline_rounded, color: MaviohColors.success),
                  ),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Text(
                      'Séance terminée !',
                      style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800, color: MaviohColors.text),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              Text(
                '${fmtKcal(burned)} brûlées · +${fmtKcal(result.nutrition.caloriesBonus)} dans ton budget du jour',
                style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: MaviohColors.text, height: 1.4),
              ),
              const SizedBox(height: 8),
              const EstimatePill(),
              if (result.nutrition.explication != null) ...[
                const SizedBox(height: 12),
                Text(
                  result.nutrition.explication!,
                  style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.45),
                ),
              ],
            ],
          ),
        ),
        if (result.recoPost != null) ...[
          const SizedBox(height: 14),
          StatusBanner.info(result.recoPost!, title: 'Après la séance'),
        ],
        const SizedBox(height: 18),
        SizedBox(
          height: 52,
          child: FilledButton.icon(
            onPressed: onBudget,
            icon: const Icon(Icons.pie_chart_outline_rounded),
            label: const Text('Voir mon budget'),
          ),
        ),
        const SizedBox(height: 10),
        TextButton(
          onPressed: () => Navigator.of(context).pop(true),
          child: const Text(AppStrings.close),
        ),
      ],
    );
  }
}

/// Inline edition of reps + weight for one exercise.
class _ExerciseEdit {
  final int? reps;
  final double? weightKg;

  const _ExerciseEdit(this.reps, this.weightKg);
}

class _ExerciseEditSheet extends StatefulWidget {
  const _ExerciseEditSheet({required this.exercise});

  final WorkoutExercise exercise;

  @override
  State<_ExerciseEditSheet> createState() => _ExerciseEditSheetState();
}

class _ExerciseEditSheetState extends State<_ExerciseEditSheet> {
  late final TextEditingController _reps = TextEditingController(text: widget.exercise.reps?.toString() ?? '');
  late final TextEditingController _weight =
      TextEditingController(text: widget.exercise.weightKg == null ? '' : fmtDecimal(widget.exercise.weightKg));

  @override
  void dispose() {
    _reps.dispose();
    _weight.dispose();
    super.dispose();
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
            Text(
              widget.exercise.name,
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _reps,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: const InputDecoration(labelText: 'Répétitions'),
            ),
            const SizedBox(height: 14),
            TextField(
              controller: _weight,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
              decoration: const InputDecoration(labelText: 'Charge (kg)'),
            ),
            const SizedBox(height: 20),
            SizedBox(
              height: 50,
              child: FilledButton(
                onPressed: () => Navigator.of(context).pop(
                  _ExerciseEdit(int.tryParse(_reps.text.trim()), parseDecimal(_weight.text)),
                ),
                child: const Text(AppStrings.save),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Wraps the `complete` response so the sheet only pops after a 2xx.
class _CompleteInput {
  final SessionCompleteResult result;

  const _CompleteInput(this.result);
}

class _CompleteSheet extends StatefulWidget {
  const _CompleteSheet({required this.initialDurationMin, required this.onSubmit});

  final int initialDurationMin;
  final Future<SessionCompleteResult> Function(int durationMin, int rpe) onSubmit;

  @override
  State<_CompleteSheet> createState() => _CompleteSheetState();
}

class _CompleteSheetState extends State<_CompleteSheet> {
  late final TextEditingController _duration =
      TextEditingController(text: widget.initialDurationMin.clamp(1, 600).toString());

  double _rpe = 5;
  bool _saving = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  @override
  void dispose() {
    _duration.dispose();
    super.dispose();
  }

  static String rpeLabel(int rpe) {
    if (rpe <= 2) return 'très facile';
    if (rpe <= 4) return 'facile';
    if (rpe <= 6) return 'modéré';
    if (rpe <= 8) return 'difficile';
    if (rpe == 9) return 'très difficile';
    return 'maximal';
  }

  Future<void> _submit() async {
    final duration = int.tryParse(_duration.text.trim()) ?? 0;
    if (duration < 1 || duration > 600) {
      setState(() => _fieldErrors = {'duration_min': 'Indique une durée entre 1 et 600 minutes.'});
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
      _fieldErrors = const {};
    });
    try {
      final result = await widget.onSubmit(duration, _rpe.round());
      if (!mounted) return;
      Navigator.of(context).pop(_CompleteInput(result));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
        _fieldErrors = {
          'duration_min': ?e.fieldError('duration_min'),
          'rpe': ?e.fieldError('rpe'),
        };
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final rpe = _rpe.round();
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 28),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'Terminer la séance',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              StatusBanner.error(_error!),
            ],
            const SizedBox(height: 16),
            TextField(
              controller: _duration,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: InputDecoration(
                labelText: 'Durée réelle (min)',
                errorText: _fieldErrors['duration_min'],
              ),
            ),
            const SizedBox(height: 18),
            Row(
              children: [
                const Text(
                  'Ressenti (RPE)',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
                ),
                const Spacer(),
                Text(
                  '$rpe · ${rpeLabel(rpe)}',
                  style: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
                ),
              ],
            ),
            Slider(
              value: _rpe,
              min: 1,
              max: 10,
              divisions: 9,
              label: '$rpe · ${rpeLabel(rpe)}',
              onChanged: (value) => setState(() => _rpe = value),
            ),
            const Row(
              children: [
                Text('1 · facile', style: TextStyle(fontSize: 11.5, color: MaviohColors.muted)),
                Spacer(),
                Text('10 · maximal', style: TextStyle(fontSize: 11.5, color: MaviohColors.muted)),
              ],
            ),
            if (_fieldErrors['rpe'] != null) ...[
              const SizedBox(height: 6),
              Text(_fieldErrors['rpe']!, style: const TextStyle(color: MaviohColors.error, fontSize: 12)),
            ],
            const SizedBox(height: 20),
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
                label: const Text('Enregistrer la séance'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
