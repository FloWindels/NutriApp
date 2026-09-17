import 'package:dio/dio.dart' show CancelToken;
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../models/sport.dart';
import '../../services/sport_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/app_card.dart';
import '../../widgets/confirm_dialog.dart';
import '../../widgets/error_state.dart';
import '../../widgets/loading_state.dart';
import '../../widgets/macro_pill.dart';
import '../../widgets/status_banner.dart';
import '../workout_session_screen.dart';
import 'sport_icons.dart';

/// Replays the generation with another seed (« Régénérer »).
typedef ProposalRegenerator = Future<SessionProposal> Function(int seed, CancelToken cancelToken);

/// Proposed session (addendum §C.4/§D) — pushed screen, owns its Scaffold.
///
/// Pops `true` when a session was created (the caller refreshes), or the result
/// of [WorkoutSessionScreen] when the user starts the session right away.
class ProposalScreen extends StatefulWidget {
  const ProposalScreen({
    super.key,
    required this.proposal,
    this.onRegenerate,
    this.config,
    this.service,
    this.date,
    this.plannedAt,
    this.sportPlanId,
    this.sportId,
  });

  final SessionProposal proposal;
  final ProposalRegenerator? onRegenerate;
  final SportConfig? config;
  final SportService? service;
  final DateTime? date;
  final String? plannedAt;
  final int? sportPlanId;
  final int? sportId;

  @override
  State<ProposalScreen> createState() => _ProposalScreenState();
}

class _ProposalScreenState extends State<ProposalScreen> {
  SportService get _service => widget.service ?? SportService();

  late SessionProposal _proposal = widget.proposal;
  int _seed = 0;
  bool _busy = false;
  bool _regenerating = false;
  String? _error;
  CancelToken? _cancelToken;

  @override
  void dispose() {
    _cancelToken?.cancel('dispose');
    super.dispose();
  }

  void _invalidateCaches() {
    Session.instance.invalidatePrefix('sport');
    Session.instance.invalidate('dashboard');
    Session.instance.invalidatePrefix('meals');
  }

  Future<void> _regenerate() async {
    final regenerate = widget.onRegenerate;
    if (regenerate == null) return;
    final token = CancelToken();
    setState(() {
      _regenerating = true;
      _error = null;
      _cancelToken = token;
    });
    try {
      final proposal = await regenerate(++_seed, token);
      if (!mounted) return;
      setState(() {
        _proposal = proposal;
        _regenerating = false;
        _cancelToken = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _regenerating = false;
        _cancelToken = null;
        _error = e.message;
      });
    }
  }

  void _cancelRegeneration() {
    _cancelToken?.cancel('utilisateur');
    setState(() {
      _regenerating = false;
      _cancelToken = null;
      _error = null;
    });
  }

  Future<void> _replaceExercise(int blockIndex, int exerciseIndex) async {
    final current = _proposal.blocks[blockIndex].exercises[exerciseIndex];
    final replacement = await showModalBottomSheet<Exercise>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _ExercisePickerSheet(service: _service, muscleGroup: current.muscleGroup),
    );
    if (replacement == null || !mounted) return;
    final blocks = List<ProposalBlock>.from(_proposal.blocks);
    final exercises = List<ProposalExercise>.from(blocks[blockIndex].exercises);
    exercises[exerciseIndex] = current.copyWithExercise(replacement);
    blocks[blockIndex] = blocks[blockIndex].copyWith(exercises: exercises);
    setState(() => _proposal = _proposal.copyWith(blocks: blocks));
  }

  Future<void> _plan() async {
    final now = today();
    final date = await showDatePicker(
      context: context,
      initialDate: widget.date ?? now,
      firstDate: now.subtract(const Duration(days: 365)),
      lastDate: now.add(const Duration(days: 365)),
      helpText: 'Date de la séance',
    );
    if (date == null || !mounted) return;
    final time = await showTimePicker(
      context: context,
      initialTime: const TimeOfDay(hour: 18, minute: 0),
      helpText: 'Heure de la séance',
    );
    if (!mounted) return;
    final plannedAt = time == null
        ? widget.plannedAt
        : '${time.hour.toString().padLeft(2, '0')}:${time.minute.toString().padLeft(2, '0')}';
    await _create(date: date, plannedAt: plannedAt, start: false);
  }

  Future<void> _start() => _create(date: widget.date ?? today(), plannedAt: widget.plannedAt, start: true);

  Future<void> _create({required DateTime date, String? plannedAt, required bool start}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final body = _proposal.toSessionJson(
        date: date,
        plannedAt: plannedAt,
        sportPlanId: widget.sportPlanId,
        sportId: widget.sportId,
      );
      var session = await _service.createSession(body);
      if (start) {
        session = await _service.start(session.id);
        await ApiClient.instance.writeSecure(activeWorkoutSessionKey, session.id.toString());
      }
      _invalidateCaches();
      if (!mounted) return;
      if (!start) {
        Navigator.of(context).pop(true);
        return;
      }
      final result = await Navigator.of(context).push<Object?>(
        MaterialPageRoute<Object?>(builder: (_) => WorkoutSessionScreen(sessionId: session.id)),
      );
      if (!mounted) return;
      Navigator.of(context).pop(result);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final proposal = _proposal;
    return Scaffold(
      appBar: AppBar(title: const Text('Séance proposée')),
      body: _regenerating
          ? const LoadingState(message: 'Le coach prépare ta séance…')
          : ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
              children: [
                Row(
                  children: [
                    TonePill(
                      label: proposal.isAi
                          ? 'Proposée par l’IA${proposal.llmModel == null ? '' : ' (${proposal.llmModel})'}'
                          : 'Règles Mavi’oh',
                      tone: proposal.isAi ? MaviohColors.violet : MaviohColors.slate,
                      icon: proposal.isAi ? Icons.auto_awesome_rounded : Icons.rule_rounded,
                    ),
                  ],
                ),
                if (proposal.warnings.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  StatusBanner.warning(proposal.warnings.join('\n'), title: 'À savoir avant de commencer'),
                ],
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  StatusBanner.error(_error!),
                ],
                const SizedBox(height: 14),
                AppCard.hero(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        proposal.title,
                        style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: MaviohColors.text),
                      ),
                      const SizedBox(height: 8),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          TonePill(
                            label: fmtMinutes(proposal.durationMin),
                            tone: MaviohColors.primary,
                            icon: Icons.schedule_rounded,
                          ),
                          if (proposal.caloriesEstimate != null)
                            TonePill(
                              label: '~${fmtKcal(proposal.caloriesEstimate)} (estimation)',
                              tone: MaviohColors.orange,
                              icon: Icons.local_fire_department_outlined,
                            ),
                          if (proposal.lieu != null)
                            TonePill(
                              label: AppStrings.vocabLabel(widget.config?.lieux ?? AppStrings.sportLieux, proposal.lieu),
                              tone: MaviohColors.sky,
                              icon: Icons.place_outlined,
                            ),
                          if (proposal.isEstimate) const EstimatePill(),
                        ],
                      ),
                      if (proposal.explication.isNotEmpty) ...[
                        const SizedBox(height: 14),
                        ...proposal.explication.map(
                          (line) => Padding(
                            padding: const EdgeInsets.only(bottom: 8),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Padding(
                                  padding: EdgeInsets.only(top: 6, right: 8),
                                  child: Icon(Icons.circle, size: 6, color: MaviohColors.accent),
                                ),
                                Expanded(
                                  child: Text(
                                    line,
                                    style: const TextStyle(color: MaviohColors.textTertiary, height: 1.45),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                for (var b = 0; b < proposal.blocks.length; b++) ...[
                  _BlockCard(
                    block: proposal.blocks[b],
                    onInfo: (exercise) => ConfirmDialog.info(
                      context,
                      title: exercise.name,
                      message: exercise.instructions.isEmpty
                          ? 'Aucune consigne détaillée pour cet exercice.'
                          : exercise.instructions,
                      icon: Icons.menu_book_outlined,
                    ),
                    onReplace: (index) => _replaceExercise(b, index),
                  ),
                  const SizedBox(height: 14),
                ],
                const Text(
                  'Arrête l’exercice en cas de douleur ou de malaise. Programme indicatif, il ne remplace ni un coach ni un avis médical.',
                  style: TextStyle(fontSize: 11.5, color: MaviohColors.muted, height: 1.4),
                ),
              ],
            ),
      bottomNavigationBar: SafeArea(
        minimum: const EdgeInsets.fromLTRB(20, 8, 20, 12),
        child: _regenerating
            ? Row(
                children: [
                  const Expanded(
                    child: Text(
                      'Le coach prépare ta séance… (20 à 60 s)',
                      style: TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
                    ),
                  ),
                  TextButton(onPressed: _cancelRegeneration, child: const Text(AppStrings.cancel)),
                ],
              )
            : Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Row(
                    children: [
                      if (widget.onRegenerate != null) ...[
                        Expanded(
                          child: OutlinedButton.icon(
                            onPressed: _busy ? null : _regenerate,
                            icon: const Icon(Icons.refresh_rounded),
                            label: const Text('Régénérer'),
                          ),
                        ),
                        const SizedBox(width: 10),
                      ],
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: _busy ? null : _plan,
                          icon: const Icon(Icons.event_outlined),
                          label: const Text('Planifier…'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  SizedBox(
                    height: 52,
                    width: double.infinity,
                    child: FilledButton.icon(
                      onPressed: _busy ? null : _start,
                      icon: _busy
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                            )
                          : const Icon(Icons.play_arrow_rounded),
                      label: const Text('Commencer'),
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}

class _BlockCard extends StatelessWidget {
  const _BlockCard({required this.block, required this.onInfo, required this.onReplace});

  final ProposalBlock block;
  final ValueChanged<ProposalExercise> onInfo;
  final ValueChanged<int> onReplace;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      padding: const EdgeInsets.fromLTRB(16, 14, 8, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(blockIcon(block.key), size: 18, color: MaviohColors.primary),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  block.name,
                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          for (var i = 0; i < block.exercises.length; i++)
            _ExerciseTile(
              exercise: block.exercises[i],
              onInfo: () => onInfo(block.exercises[i]),
              onReplace: () => onReplace(i),
            ),
        ],
      ),
    );
  }
}

class _ExerciseTile extends StatelessWidget {
  const _ExerciseTile({required this.exercise, required this.onInfo, required this.onReplace});

  final ProposalExercise exercise;
  final VoidCallback onInfo;
  final VoidCallback onReplace;

  @override
  Widget build(BuildContext context) {
    final details = <String>[
      if (exercise.prescription.isNotEmpty) exercise.prescription,
      if (exercise.restSec != null) 'repos ${exercise.restSec} s',
      if (exercise.muscleGroup != null) AppStrings.muscleGroupLabels[exercise.muscleGroup] ?? exercise.muscleGroup!,
    ];
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              color: MaviohColors.tint(MaviohColors.primary),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(equipmentIcon(exercise.equipment), size: 18, color: MaviohColors.primary),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  exercise.name,
                  style: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
                ),
                if (details.isNotEmpty)
                  Text(
                    details.join(' · '),
                    style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                  ),
              ],
            ),
          ),
          IconButton(
            tooltip: 'Voir les consignes',
            onPressed: onInfo,
            icon: const Icon(Icons.info_outline_rounded, size: 20),
            constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
          ),
          IconButton(
            tooltip: 'Remplacer l’exercice',
            onPressed: onReplace,
            icon: const Icon(Icons.swap_horiz_rounded, size: 20),
            constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
          ),
        ],
      ),
    );
  }
}

/// Catalog picker used by « Remplacer » (`GET /sport/exercises`).
class _ExercisePickerSheet extends StatefulWidget {
  const _ExercisePickerSheet({required this.service, this.muscleGroup});

  final SportService service;
  final String? muscleGroup;

  @override
  State<_ExercisePickerSheet> createState() => _ExercisePickerSheetState();
}

class _ExercisePickerSheetState extends State<_ExercisePickerSheet> {
  final TextEditingController _search = TextEditingController();

  List<Exercise> _items = const [];
  bool _loading = true;
  String? _error;
  int _requestId = 0;

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
    final id = ++_requestId;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final paged = await widget.service.exercises(
        q: _search.text,
        muscle: _search.text.trim().isEmpty ? widget.muscleGroup : null,
      );
      if (!mounted || id != _requestId) return;
      setState(() {
        _items = paged.items;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted || id != _requestId) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final height = (MediaQuery.sizeOf(context).height * 0.85).clamp(420.0, 760.0);
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
                'Remplacer l’exercice',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 12),
              child: TextField(
                controller: _search,
                textInputAction: TextInputAction.search,
                onSubmitted: (_) => _load(),
                decoration: InputDecoration(
                  hintText: 'Rechercher un exercice',
                  prefixIcon: const Icon(Icons.search_rounded),
                  suffixIcon: IconButton(
                    tooltip: AppStrings.search,
                    onPressed: _load,
                    icon: const Icon(Icons.arrow_forward_rounded),
                  ),
                ),
              ),
            ),
            Expanded(
              child: Builder(
                builder: (context) {
                  if (_loading) return const LoadingState();
                  if (_error != null && _items.isEmpty) {
                    return ErrorState(message: _error!, onRetry: _load, compact: true);
                  }
                  if (_items.isEmpty) {
                    return const Padding(
                      padding: EdgeInsets.all(24),
                      child: Text('Aucun exercice trouvé.', textAlign: TextAlign.center),
                    );
                  }
                  return ListView.builder(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(12, 0, 12, 24),
                    itemCount: _items.length,
                    itemBuilder: (context, index) {
                      final exercise = _items[index];
                      return ListTile(
                        minVerticalPadding: 10,
                        leading: Icon(equipmentIcon(exercise.equipment), color: MaviohColors.primary),
                        title: Text(
                          exercise.name,
                          style: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
                        ),
                        subtitle: Text(
                          '${AppStrings.exerciseCategoryLabels[exercise.category] ?? exercise.category} · '
                          '${AppStrings.muscleGroupLabels[exercise.muscleGroup] ?? exercise.muscleGroup}',
                        ),
                        onTap: () => Navigator.of(context).pop(exercise),
                      );
                    },
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
  }
}
