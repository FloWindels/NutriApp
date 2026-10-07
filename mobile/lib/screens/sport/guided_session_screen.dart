import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/ecran_allume.dart';
import '../../core/strings.dart';
import '../../models/sport.dart';
import '../../services/sport_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/confirm_dialog.dart';
import '../../widgets/status_banner.dart';
import 'sport_icons.dart';

/// Une étape de la séance guidée, rattachée à son exercice.
class GuidedStep {
  final Etape etape;
  final int exerciseIndex;
  final String exerciseName;
  final String block;
  final String movement;
  final String? consigne;

  const GuidedStep({
    required this.etape,
    required this.exerciseIndex,
    required this.exerciseName,
    required this.block,
    this.movement = 'generique',
    this.consigne,
  });
}

/// Aplatit les étapes de chaque exercice (`etapes` du serveur, sinon reconstruites).
List<GuidedStep> buildGuidedSteps(List<WorkoutExercise> exercises) {
  final steps = <GuidedStep>[];
  for (var i = 0; i < exercises.length; i++) {
    final e = exercises[i];
    for (final etape in e.etapesOuRepli) {
      steps.add(GuidedStep(
        etape: etape,
        exerciseIndex: i,
        exerciseName: e.name,
        block: e.block,
        movement: e.movement,
        consigne: e.prescriptionV2?.consigne,
      ));
    }
  }
  return steps;
}

/// Séance guidée (Sport v2, § 6) : plein écran, étape en cours en grand, compte à rebours,
/// cible en cours, étape suivante, pause / précédent / passer, signal aux changements.
///
/// Le minuteur est calculé à partir d'horodatages (et non en décomptant des ticks) : après une
/// mise en arrière-plan, l'écran rattrape les étapes écoulées. À la fin, le ressenti (RPE 1–10)
/// est envoyé à `POST /sport/sessions/{id}/complete` ; l'écran renvoie alors le
/// [SessionCompleteResult]. Quitter (après confirmation) renvoie null : la séance reste en cours.
class GuidedSessionScreen extends StatefulWidget {
  const GuidedSessionScreen({super.key, required this.session, this.exercises, this.service, this.clock});

  final WorkoutSession session;

  /// Exercices à jour (répétitions / charges modifiées) ; par défaut ceux de [session].
  final List<WorkoutExercise>? exercises;
  final SportService? service;

  /// Horloge injectable (tests).
  final DateTime Function()? clock;

  @override
  State<GuidedSessionScreen> createState() => _GuidedSessionScreenState();
}

class _GuidedSessionScreenState extends State<GuidedSessionScreen> with WidgetsBindingObserver {
  SportService get _service => widget.service ?? SportService();

  DateTime _now() => (widget.clock ?? DateTime.now)();

  late final List<WorkoutExercise> _exercises = widget.exercises ?? widget.session.exercises;
  late final List<GuidedStep> _steps = buildGuidedSteps(_exercises);
  late final DateTime _guidedStart = _now();

  int _index = 0;
  late DateTime _stepStart = _now();
  Duration _pausedFor = Duration.zero;
  DateTime? _pausedAt;
  bool _finished = false;
  int? _lastCue;
  Timer? _ticker;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    EcranAllume.activer();
    _ticker = Timer.periodic(const Duration(milliseconds: 200), (_) => _tick());
    if (_steps.isEmpty) _finished = true;
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _ticker?.cancel();
    EcranAllume.desactiver();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Retour au premier plan : rattraper les étapes écoulées pendant l'absence.
    if (state == AppLifecycleState.resumed) _tick();
  }

  GuidedStep get _step => _steps[_index];

  bool get _paused => _pausedAt != null;

  Duration _elapsedInStep(DateTime now) {
    var paused = _pausedFor;
    if (_pausedAt != null) paused += now.difference(_pausedAt!);
    final elapsed = now.difference(_stepStart) - paused;
    return elapsed.isNegative ? Duration.zero : elapsed;
  }

  /// Millisecondes restantes de l'étape minutée en cours (null : étape sans durée).
  int? _remainingMs(DateTime now) {
    final duree = _step.etape.dureeSec;
    if (duree == null) return null;
    return duree * 1000 - _elapsedInStep(now).inMilliseconds;
  }

  void _tick() {
    if (!mounted || _finished || _steps.isEmpty) return;
    final now = _now();
    if (_paused) return;
    var changed = false;
    // Étapes minutées écoulées : on enchaîne, chaque étape démarrant à la fin exacte de la précédente.
    while (!_finished) {
      final duree = _step.etape.dureeSec;
      if (duree == null) break;
      final end = _stepStart.add(_pausedFor).add(Duration(seconds: duree));
      if (now.isBefore(end)) break;
      changed = true;
      if (_index >= _steps.length - 1) {
        _finished = true;
        break;
      }
      _index++;
      _stepStart = end;
      _pausedFor = Duration.zero;
      _lastCue = null;
    }
    if (changed) {
      _signalChange(finished: _finished);
    } else {
      final remaining = _remainingMs(now);
      if (remaining != null) {
        final secondsLeft = (remaining / 1000).ceil();
        if (secondsLeft >= 1 && secondsLeft <= 3 && secondsLeft != _lastCue) {
          _lastCue = secondsLeft;
          _signalCountdown();
        }
      }
    }
    setState(() {});
  }

  // ----- Signaux ---------------------------------------------------------------

  void _signalChange({bool finished = false}) {
    _quiet(HapticFeedback.heavyImpact());
    _quiet(SystemSound.play(SystemSoundType.alert));
    if (finished) _quiet(HapticFeedback.vibrate());
  }

  void _signalCountdown() {
    _quiet(HapticFeedback.selectionClick());
    _quiet(SystemSound.play(SystemSoundType.click));
  }

  /// Un signal qui échoue (plateforme sans retour haptique) ne doit rien casser.
  void _quiet(Future<void> future) {
    future.catchError((Object _) {});
  }

  // ----- Commandes -------------------------------------------------------------

  void _goTo(int index) {
    final now = _now();
    setState(() {
      _finished = false;
      _index = index.clamp(0, _steps.length - 1);
      _stepStart = now;
      _pausedFor = Duration.zero;
      if (_pausedAt != null) _pausedAt = now;
      _lastCue = null;
    });
    _signalChange();
  }

  void _next() {
    if (_index >= _steps.length - 1) {
      setState(() {
        _finished = true;
        _pausedAt = null;
      });
      _signalChange(finished: true);
      return;
    }
    _goTo(_index + 1);
  }

  void _previous() {
    // Au-delà de 3 s dans l'étape, « Précédent » la recommence ; sinon il recule d'une étape.
    if (_finished) {
      _goTo(_steps.length - 1);
      return;
    }
    final elapsed = _elapsedInStep(_now());
    _goTo(elapsed.inSeconds >= 3 || _index == 0 ? _index : _index - 1);
  }

  void _togglePause() {
    final now = _now();
    setState(() {
      if (_pausedAt != null) {
        _pausedFor += now.difference(_pausedAt!);
        _pausedAt = null;
      } else {
        _pausedAt = now;
      }
    });
  }

  Future<void> _confirmQuit() async {
    final quit = await ConfirmDialog.show(
      context,
      title: 'Quitter la séance guidée ?',
      message: 'La séance reste « en cours » : tu pourras la reprendre ou la terminer depuis l’onglet Sport.',
      confirmLabel: 'Quitter',
      cancelLabel: 'Continuer',
      icon: Icons.logout_rounded,
    );
    if (quit && mounted) Navigator.of(context).pop();
  }

  /// Exercice terminé quand toutes ses étapes ont été atteintes (ou séance finie).
  List<Map<String, dynamic>> _exercisesPayload() {
    final lastStepOf = <int, int>{};
    for (var i = 0; i < _steps.length; i++) {
      lastStepOf[_steps[i].exerciseIndex] = i;
    }
    final payload = <Map<String, dynamic>>[];
    for (var i = 0; i < _exercises.length; i++) {
      final e = _exercises[i];
      if (e.id == 0) continue;
      final last = lastStepOf[i];
      final done = _finished || (last != null && last < _index) || e.completed;
      payload.add(<String, dynamic>{
        'id': e.id,
        'completed': done,
        'sets': ?e.sets,
        'reps': ?e.reps,
        'weight_kg': ?e.weightKg,
      });
    }
    return payload;
  }

  int _durationMin() {
    final start = widget.session.startedAt ?? _guidedStart;
    final minutes = (_now().difference(start).inSeconds / 60).round();
    return minutes.clamp(1, 600);
  }

  Future<void> _finish() async {
    final wasPaused = _paused;
    if (!wasPaused && !_finished) _togglePause();
    final result = await showModalBottomSheet<SessionCompleteResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _RpeSheet(
        durationMin: _durationMin(),
        onSubmit: (rpe) => _service.complete(
          widget.session.id,
          durationMin: _durationMin(),
          rpe: rpe,
          exercises: _exercisesPayload(),
        ),
      ),
    );
    if (!mounted) return;
    if (result != null) {
      Navigator.of(context).pop(result);
      return;
    }
    if (!wasPaused && !_finished && _paused) _togglePause();
  }

  // ----- Rendu -----------------------------------------------------------------

  Color _tone(GuidedStep? step) {
    if (step == null || _finished) return MaviohColors.primary;
    switch (step.etape.type) {
      case 'effort':
        return const Color(0xFF9F1239);
      case 'recup':
      case 'repos':
        return const Color(0xFF075985);
      default:
        return MaviohColors.primary;
    }
  }

  @override
  Widget build(BuildContext context) {
    final step = _steps.isEmpty ? null : _step;
    final tone = _tone(step);
    return PopScope<Object?>(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _confirmQuit();
      },
      child: AnnotatedRegion<SystemUiOverlayStyle>(
        value: SystemUiOverlayStyle.light,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 350),
          color: tone,
          child: Scaffold(
            backgroundColor: Colors.transparent,
            body: SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(8, 4, 8, 12),
                child: Column(
                  children: [
                    _topBar(),
                    const SizedBox(height: 6),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(99),
                        child: LinearProgressIndicator(
                          value: _steps.isEmpty ? 1 : (_finished ? 1 : _index / _steps.length),
                          minHeight: 6,
                          color: Colors.white,
                          backgroundColor: Colors.white24,
                        ),
                      ),
                    ),
                    Expanded(child: _finished || step == null ? _finishedView() : _stepView(step)),
                    if (!_finished && step != null) _controls(step),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _topBar() {
    return Row(
      children: [
        IconButton(
          tooltip: 'Quitter la séance guidée',
          onPressed: _confirmQuit,
          icon: const Icon(Icons.close_rounded, color: Colors.white),
          constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
        ),
        Expanded(
          child: Column(
            children: [
              Text(
                widget.session.title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 15),
              ),
              if (_steps.isNotEmpty)
                Text(
                  _finished ? 'Toutes les étapes faites' : 'Étape ${_index + 1} / ${_steps.length}',
                  style: const TextStyle(color: Colors.white70, fontSize: 12.5, fontWeight: FontWeight.w600),
                ),
            ],
          ),
        ),
        IconButton(
          tooltip: 'Terminer la séance',
          onPressed: _finish,
          icon: const Icon(Icons.flag_rounded, color: Colors.white),
          constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
        ),
      ],
    );
  }

  Widget _stepView(GuidedStep step) {
    final etape = step.etape;
    final now = _now();
    final remaining = _remainingMs(now);
    final next = _index + 1 < _steps.length ? _steps[_index + 1] : null;
    return LayoutBuilder(
      builder: (context, constraints) {
        final ring = (constraints.maxHeight * 0.42).clamp(150.0, 260.0);
        return SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
          child: ConstrainedBox(
            constraints: BoxConstraints(minHeight: constraints.maxHeight - 22),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Column(
                  children: [
                    Text(
                      (AppStrings.blockLabels[step.block] ?? step.block).toUpperCase(),
                      style: const TextStyle(color: Colors.white70, fontSize: 11.5, letterSpacing: 1.2, fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(height: 4),
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(movementIcon(step.movement), color: Colors.white, size: 20),
                        const SizedBox(width: 8),
                        Flexible(
                          child: Text(
                            step.exerciseName,
                            textAlign: TextAlign.center,
                            style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w700),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(
                      etape.label,
                      textAlign: TextAlign.center,
                      style: const TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.w900, height: 1.1),
                    ),
                  ],
                ),
                const SizedBox(height: 16),
                SizedBox(
                  width: ring,
                  height: ring,
                  child: remaining == null ? _untimedRing(etape) : _timedRing(etape, remaining),
                ),
                const SizedBox(height: 16),
                if (etape.cible != null && etape.cible!.isNotEmpty)
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.14),
                      borderRadius: BorderRadius.circular(18),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.track_changes_rounded, color: Colors.white, size: 22),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            etape.cible!,
                            style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w800, height: 1.3),
                          ),
                        ),
                      ],
                    ),
                  ),
                if (step.consigne != null && !etape.isRest) ...[
                  const SizedBox(height: 10),
                  Text(
                    step.consigne!,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white70, fontSize: 13.5, height: 1.4, fontStyle: FontStyle.italic),
                  ),
                ],
                const SizedBox(height: 14),
                _nextPreview(step, next),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _timedRing(Etape etape, int remainingMs) {
    final total = (etape.dureeSec ?? 1) * 1000;
    final left = remainingMs.clamp(0, total);
    final seconds = (left / 1000).ceil();
    return Stack(
      fit: StackFit.expand,
      children: [
        CircularProgressIndicator(
          value: 1 - left / total,
          strokeWidth: 12,
          strokeCap: StrokeCap.round,
          color: Colors.white,
          backgroundColor: Colors.white24,
        ),
        Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              FittedBox(
                child: Text(
                  fmtClock(seconds),
                  key: const ValueKey('guided-countdown'),
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 60,
                    fontWeight: FontWeight.w900,
                    fontFeatures: [FontFeature.tabularFigures()],
                  ),
                ),
              ),
              if (_paused)
                const Text('En pause', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700)),
            ],
          ),
        ),
      ],
    );
  }

  Widget _untimedRing(Etape etape) {
    return Container(
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white38, width: 12),
      ),
      padding: const EdgeInsets.all(18),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.fitness_center_rounded, color: Colors.white, size: 30),
            const SizedBox(height: 10),
            FilledButton(
              onPressed: _next,
              style: FilledButton.styleFrom(
                backgroundColor: Colors.white,
                foregroundColor: MaviohColors.primary,
                padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
              ),
              child: const Text('Série terminée'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _nextPreview(GuidedStep current, GuidedStep? next) {
    final String text;
    if (next == null) {
      text = 'Dernière étape : la séance est presque finie.';
    } else {
      final duree = next.etape.dureeSec == null ? '' : ' · ${fmtSecondes(next.etape.dureeSec!)}';
      final exo = next.exerciseIndex != current.exerciseIndex ? '${next.exerciseName} — ' : '';
      final cible = next.etape.cible == null || next.etape.cible!.isEmpty ? '' : '\n${next.etape.cible}';
      text = '$exo${next.etape.label}$duree$cible';
    }
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.black.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Padding(
            padding: EdgeInsets.only(top: 1),
            child: Icon(Icons.skip_next_rounded, color: Colors.white70, size: 20),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text.rich(
              TextSpan(
                children: [
                  const TextSpan(text: 'Ensuite : ', style: TextStyle(fontWeight: FontWeight.w800)),
                  TextSpan(text: text),
                ],
              ),
              style: const TextStyle(color: Colors.white, fontSize: 13.5, height: 1.4),
            ),
          ),
        ],
      ),
    );
  }

  Widget _controls(GuidedStep step) {
    final timed = step.etape.isTimed;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 6, 16, 0),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceEvenly,
        children: [
          _RoundButton(
            icon: Icons.skip_previous_rounded,
            label: 'Précédent',
            onPressed: _previous,
          ),
          _RoundButton(
            icon: _paused ? Icons.play_arrow_rounded : Icons.pause_rounded,
            label: _paused ? 'Reprendre' : 'Pause',
            onPressed: timed ? _togglePause : null,
            big: true,
          ),
          _RoundButton(
            icon: Icons.skip_next_rounded,
            label: 'Passer',
            onPressed: _next,
          ),
        ],
      ),
    );
  }

  Widget _finishedView() {
    final minutes = _durationMin();
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.emoji_events_outlined, color: Colors.white, size: 64),
            const SizedBox(height: 14),
            const Text(
              'Bravo, séance terminée !',
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 8),
            Text(
              _steps.isEmpty
                  ? 'Cette séance n’a pas d’étape minutée. Note ton ressenti pour l’enregistrer.'
                  : '${_steps.length} étapes · environ $minutes min. Note ton ressenti pour enregistrer la séance.',
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.white70, fontSize: 14.5, height: 1.4),
            ),
            const SizedBox(height: 24),
            SizedBox(
              height: 54,
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _finish,
                style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: MaviohColors.primary),
                icon: const Icon(Icons.flag_rounded),
                label: const Text('Terminer'),
              ),
            ),
            if (_steps.isNotEmpty) ...[
              const SizedBox(height: 8),
              TextButton(
                onPressed: _previous,
                style: TextButton.styleFrom(foregroundColor: Colors.white),
                child: const Text('Revenir à la dernière étape'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _RoundButton extends StatelessWidget {
  const _RoundButton({required this.icon, required this.label, required this.onPressed, this.big = false});

  final IconData icon;
  final String label;
  final VoidCallback? onPressed;
  final bool big;

  @override
  Widget build(BuildContext context) {
    final size = big ? 76.0 : 56.0;
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          width: size,
          height: size,
          child: IconButton(
            tooltip: label,
            onPressed: onPressed,
            style: IconButton.styleFrom(
              backgroundColor: big ? Colors.white : Colors.white.withValues(alpha: 0.18),
              foregroundColor: big ? MaviohColors.primary : Colors.white,
              disabledBackgroundColor: Colors.white.withValues(alpha: 0.1),
              disabledForegroundColor: Colors.white38,
            ),
            icon: Icon(icon, size: big ? 40 : 28),
          ),
        ),
        const SizedBox(height: 4),
        Text(label, style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700)),
      ],
    );
  }
}

/// Ressenti de fin de séance (RPE 1–10) ; ne se ferme qu'après un 2xx.
class _RpeSheet extends StatefulWidget {
  const _RpeSheet({required this.durationMin, required this.onSubmit});

  final int durationMin;
  final Future<SessionCompleteResult> Function(int rpe) onSubmit;

  @override
  State<_RpeSheet> createState() => _RpeSheetState();
}

class _RpeSheetState extends State<_RpeSheet> {
  int? _rpe;
  bool _saving = false;
  String? _error;

  static String _label(int rpe) {
    if (rpe <= 2) return 'très facile';
    if (rpe <= 4) return 'facile';
    if (rpe <= 6) return 'modéré';
    if (rpe <= 8) return 'difficile';
    if (rpe == 9) return 'très difficile';
    return 'maximal';
  }

  Future<void> _submit() async {
    final rpe = _rpe;
    if (rpe == null) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final result = await widget.onSubmit(rpe);
      if (!mounted) return;
      Navigator.of(context).pop(result);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final rpe = _rpe;
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 4, 20, 28),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          const Text(
            'Comment l’as-tu ressentie ?',
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
          ),
          const SizedBox(height: 4),
          Text(
            'Durée enregistrée : ${widget.durationMin} min. 1 = très facile, 10 = effort maximal.',
            style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            StatusBanner.error(_error!),
          ],
          const SizedBox(height: 16),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (var i = 1; i <= 10; i++)
                ChoiceChip(
                  label: Text('$i'),
                  selected: rpe == i,
                  onSelected: _saving ? null : (_) => setState(() => _rpe = i),
                ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            rpe == null ? 'Choisis une note.' : 'RPE $rpe · ${_label(rpe)}',
            style: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
          ),
          const SizedBox(height: 20),
          SizedBox(
            height: 52,
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: _saving || rpe == null ? null : _submit,
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
    );
  }
}
