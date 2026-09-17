import 'package:dio/dio.dart' show CancelToken;
import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/meal.dart';
import '../models/sport.dart';
import '../services/sport_service.dart';
import '../theme/app_theme.dart';
import '../widgets/app_card.dart';
import '../widgets/confirm_dialog.dart';
import '../widgets/empty_state.dart';
import '../widgets/error_state.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/month_calendar.dart';
import '../widgets/section_header.dart';
import '../widgets/stat_tile.dart';
import '../widgets/status_banner.dart';
import 'sport/activity_sheet.dart';
import 'sport/generate_sheet.dart';
import 'sport/log_sheet.dart';
import 'sport/plan_sheet.dart';
import 'sport/proposal_screen.dart';
import 'sport/session_detail_sheet.dart';
import 'sport/sport_icons.dart';
import 'sport/week_plan_sheet.dart';
import 'workout_session_screen.dart';

/// The three panes of the Sport section (addendum §D).
enum SportTab { aujourdhui, calendrier, seances }

/// Sport (§16.4 + addendum §D): Aujourd’hui · Calendrier · Séances.
///
/// Rendered inside the Sport tab: no `AppBar` (the shell owns it). The
/// `Scaffold` is transparent and only carries the « Planifier » FAB.
class SportScreen extends StatefulWidget {
  const SportScreen({super.key, this.onNavigate, this.openGenerate = false});

  /// Navigation rapide vers une autre section (slug).
  final ValueChanged<String>? onNavigate;

  /// Ouvre directement la feuille « Générer une séance ».
  final bool openGenerate;

  @override
  State<SportScreen> createState() => _SportScreenState();
}

class _SportScreenState extends State<SportScreen> {
  final SportService _service = SportService();

  SportTab _tab = SportTab.aujourdhui;
  SportConfig? _config;

  // ----- Aujourd’hui --------------------------------------------------------
  SportSummary? _summary;
  bool _summaryLoading = true;
  String? _summaryError;
  String? _summaryRefreshError;

  // ----- Calendrier ---------------------------------------------------------
  late DateTime _month = DateTime(today().year, today().month);
  late DateTime _selectedDay = today();
  SportCalendar? _calendar;
  bool _calendarLoading = false;
  String? _calendarError;

  // ----- Séances ------------------------------------------------------------
  String _sessionFilter = 'toutes';
  List<WorkoutSession>? _sessions;
  bool _sessionsLoading = false;
  String? _sessionsError;

  // ----- Génération en cours ------------------------------------------------
  bool _proposing = false;
  CancelToken? _proposeToken;

  @override
  void initState() {
    super.initState();
    _loadConfig();
    _loadSummary();
    if (widget.openGenerate) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _generate();
      });
    }
  }

  @override
  void dispose() {
    _proposeToken?.cancel('dispose');
    super.dispose();
  }

  // ----- Chargements --------------------------------------------------------

  Future<void> _loadConfig() async {
    try {
      final config = await _service.config();
      if (!mounted) return;
      setState(() => _config = config);
    } on ApiException {
      // La config n’est qu’un confort : on garde les libellés locaux.
    }
  }

  Future<void> _loadSummary({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _summaryLoading = _summary == null;
        _summaryError = null;
        _summaryRefreshError = null;
      });
    }
    try {
      final summary = await _service.summary();
      if (!mounted) return;
      setState(() {
        _summary = summary;
        _summaryLoading = false;
        _summaryError = null;
        _summaryRefreshError = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _summaryLoading = false;
        if (_summary == null) {
          _summaryError = e.message;
        } else {
          _summaryRefreshError = e.message;
        }
      });
    }
  }

  Future<void> _loadCalendar({bool silent = false}) async {
    final from = DateTime(_month.year, _month.month, 1);
    final to = DateTime(_month.year, _month.month + 1, 0);
    if (!silent) {
      setState(() {
        _calendarLoading = _calendar == null;
        _calendarError = null;
      });
    }
    try {
      final calendar = await _service.calendar(from: from, to: to);
      if (!mounted) return;
      setState(() {
        _calendar = calendar;
        _calendarLoading = false;
        _calendarError = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _calendarLoading = false;
        if (_calendar == null) {
          _calendarError = e.message;
        } else {
          _snack(e.message);
        }
      });
    }
  }

  Future<void> _loadSessions({bool silent = false}) async {
    final now = today();
    if (!silent) {
      setState(() {
        _sessionsLoading = _sessions == null;
        _sessionsError = null;
      });
    }
    try {
      final sessions = await _service.sessions(
        from: now.subtract(const Duration(days: 30)),
        to: now.add(const Duration(days: 30)),
      );
      if (!mounted) return;
      sessions.sort((a, b) => b.date.compareTo(a.date));
      setState(() {
        _sessions = sessions;
        _sessionsLoading = false;
        _sessionsError = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _sessionsLoading = false;
        if (_sessions == null) {
          _sessionsError = e.message;
        } else {
          _snack(e.message);
        }
      });
    }
  }

  /// Refetches the panes that already hold data (after a mutation).
  void _reloadAll() {
    _loadSummary(silent: true);
    if (_calendar != null || _tab == SportTab.calendrier) _loadCalendar(silent: true);
    if (_sessions != null || _tab == SportTab.seances) _loadSessions(silent: true);
  }

  void _selectTab(SportTab tab) {
    setState(() => _tab = tab);
    if (tab == SportTab.calendrier && _calendar == null && !_calendarLoading) _loadCalendar();
    if (tab == SportTab.seances && _sessions == null && !_sessionsLoading) _loadSessions();
  }

  void _snack(String message) {
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(message)));
  }

  void _invalidateCaches() {
    Session.instance.invalidatePrefix('sport');
    Session.instance.invalidate('dashboard');
    Session.instance.invalidatePrefix('meals');
  }

  // ----- Actions ------------------------------------------------------------

  Future<void> _generate({DateTime? date, SportPlan? plan}) async {
    final generated = await GenerateSheet.show(
      context,
      config: _config,
      service: _service,
      initialDurationMin: plan?.plannedDurationMin,
      initialLieu: plan?.lieu,
    );
    if (generated == null || !mounted) return;
    await _openProposal(
      generated.proposal,
      date: date ?? plan?.date ?? today(),
      plannedAt: plan?.plannedAt,
      sportPlanId: plan?.id,
      sportId: plan?.sportId,
      regenerate: (seed, token) => _service.generate(
        <String, dynamic>{...generated.request, 'seed': seed},
        cancelToken: token,
      ),
    );
  }

  Future<void> _proposeForPlan(SportPlan plan) async {
    final token = CancelToken();
    setState(() {
      _proposing = true;
      _proposeToken = token;
    });
    try {
      final proposal = await _service.proposeForPlan(plan.id, cancelToken: token);
      if (!mounted) return;
      setState(() {
        _proposing = false;
        _proposeToken = null;
      });
      await _openProposal(
        proposal,
        date: plan.date,
        plannedAt: plan.plannedAt,
        sportPlanId: plan.id,
        sportId: plan.sportId,
        regenerate: (seed, cancelToken) => _service.proposeForPlan(
          plan.id,
          overrides: <String, dynamic>{'seed': seed},
          cancelToken: cancelToken,
        ),
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      final cancelled = token.isCancelled;
      setState(() {
        _proposing = false;
        _proposeToken = null;
      });
      if (!cancelled) _snack(e.message);
    }
  }

  void _cancelPropose() {
    _proposeToken?.cancel('utilisateur');
    setState(() {
      _proposing = false;
      _proposeToken = null;
    });
  }

  Future<void> _openProposal(
    SessionProposal proposal, {
    required DateTime date,
    String? plannedAt,
    int? sportPlanId,
    int? sportId,
    ProposalRegenerator? regenerate,
  }) async {
    final result = await Navigator.of(context).push<Object?>(
      MaterialPageRoute<Object?>(
        builder: (_) => ProposalScreen(
          proposal: proposal,
          config: _config,
          service: _service,
          date: date,
          plannedAt: plannedAt,
          sportPlanId: sportPlanId,
          sportId: sportId,
          onRegenerate: regenerate,
        ),
      ),
    );
    if (!mounted) return;
    if (result == 'budget') {
      widget.onNavigate?.call('historique-repas-journee');
      return;
    }
    if (result != null) _reloadAll();
  }

  Future<void> _quickActivity({DateTime? date}) async {
    final result = await ActivitySheet.show(context, config: _config, service: _service, date: date);
    if (result == null || !mounted) return;
    _invalidateCaches();
    _reloadAll();
    _snack(result.message ?? 'Activité enregistrée.');
  }

  Future<void> _logPlan(SportPlan plan) async {
    // Sans identifiant de plan (résumé partiel), on enregistre une activité libre.
    if (plan.id == 0) return _quickActivity(date: plan.date);
    final result = await LogSheet.show(context, plan: plan, config: _config, service: _service);
    if (result == null || !mounted) return;
    _reloadAll();
    _snack(result.message ?? 'Séance enregistrée.');
  }

  Future<void> _openPlanSheet({DateTime? date, SportPlan? plan}) async {
    final ok = await PlanSheet.show(
      context,
      date: date ?? plan?.date ?? _selectedDay,
      plan: plan,
      config: _config,
      service: _service,
    );
    if (ok != true || !mounted) return;
    _reloadAll();
  }

  Future<void> _deletePlan(SportPlan plan) async {
    bool? serie;
    if (plan.isRecurring) {
      serie = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          icon: const Icon(Icons.repeat_rounded, color: MaviohColors.error, size: 30),
          title: const Text('Supprimer aussi les répétitions ?'),
          content: const Text(
            'Cette séance fait partie d’une série hebdomadaire. Tu peux ne supprimer que celle-ci '
            'ou toute la série à partir de cette date.',
          ),
          actionsPadding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
          actions: [
            TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text(AppStrings.cancel)),
            TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Celle-ci seulement')),
            FilledButton(
              onPressed: () => Navigator.of(ctx).pop(true),
              style: FilledButton.styleFrom(backgroundColor: MaviohColors.error),
              child: const Text('Toute la série'),
            ),
          ],
        ),
      );
      if (serie == null || !mounted) return;
    } else {
      final ok = await ConfirmDialog.show(
        context,
        title: 'Supprimer cette séance prévue ?',
        message: 'Elle disparaîtra de ton calendrier.',
        confirmLabel: AppStrings.delete,
        destructive: true,
        icon: Icons.delete_outline_rounded,
      );
      if (!ok || !mounted) return;
      serie = false;
    }
    try {
      await _service.deletePlan(plan.id, serie: serie);
      if (!mounted) return;
      _invalidateCaches();
      _reloadAll();
      _snack(serie ? 'Série supprimée.' : 'Séance prévue supprimée.');
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.message);
    }
  }

  Future<void> _planWeek() async {
    final start = weekStart(_tab == SportTab.calendrier ? _selectedDay : today());
    final existing = <int>{};
    final calendar = _calendar;
    if (calendar != null) {
      for (final day in calendar.days) {
        if (!day.date.isBefore(start) && day.date.isBefore(start.add(const Duration(days: 7)))) {
          if (day.plans.isNotEmpty) existing.add(day.date.weekday);
        }
      }
    }
    final result = await WeekPlanSheet.show(
      context,
      weekStart: start,
      config: _config,
      service: _service,
      initialDays: existing.toList()..sort(),
    );
    if (result == null || !mounted) return;
    _reloadAll();
    _snack('Ta semaine est planifiée.');
  }

  Future<void> _openSession(WorkoutSession session) async {
    final result = await SessionDetailSheet.show(context, session: session, service: _service);
    if (result == null || !mounted) return;
    if (result.action == SessionDetailAction.started) {
      await _openWorkout(result.sessionId);
      return;
    }
    _reloadAll();
  }

  Future<void> _openWorkout(int sessionId) async {
    final result = await Navigator.of(context).push<Object?>(
      MaterialPageRoute<Object?>(builder: (_) => WorkoutSessionScreen(sessionId: sessionId)),
    );
    if (!mounted) return;
    _reloadAll();
    if (result == 'budget') widget.onNavigate?.call('historique-repas-journee');
  }

  void _explainBonus(SportBonus nutrition) {
    final message = nutrition.explication ??
        '${fmtKcal(nutrition.caloriesBurned)} brûlées aujourd’hui (estimation MET, hors métabolisme de base) : '
            '${nutrition.coefPct} % sont ajoutées à ton budget, soit +${fmtInt(nutrition.caloriesBonus)} kcal à consommer.';
    ConfirmDialog.info(context, title: 'Bonus sport', message: message, icon: Icons.local_fire_department_outlined);
  }

  // ----- Build --------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.transparent,
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
            child: SizedBox(
              width: double.infinity,
              child: SegmentedButton<SportTab>(
                segments: const [
                  ButtonSegment<SportTab>(value: SportTab.aujourdhui, label: Text('Aujourd’hui')),
                  ButtonSegment<SportTab>(value: SportTab.calendrier, label: Text('Calendrier')),
                  ButtonSegment<SportTab>(value: SportTab.seances, label: Text('Séances')),
                ],
                selected: {_tab},
                showSelectedIcon: false,
                onSelectionChanged: (value) => _selectTab(value.first),
              ),
            ),
          ),
          Expanded(child: _body()),
        ],
      ),
      floatingActionButton: _tab == SportTab.calendrier
          ? FloatingActionButton.extended(
              onPressed: () => _openPlanSheet(date: _selectedDay),
              tooltip: 'Planifier une séance',
              icon: const Icon(Icons.event_available_rounded),
              label: const Text('Planifier'),
            )
          : null,
    );
  }

  Widget _body() {
    switch (_tab) {
      case SportTab.aujourdhui:
        return _todayPane();
      case SportTab.calendrier:
        return _calendarPane();
      case SportTab.seances:
        return _sessionsPane();
    }
  }

  // ----- Pane « Aujourd’hui » ----------------------------------------------

  Widget _todayPane() {
    if (_summaryLoading && _summary == null) return const LoadingState(skeleton: true, skeletonCount: 3);
    if (_summaryError != null && _summary == null) {
      return ErrorState(message: _summaryError!, onRetry: _loadSummary);
    }
    final summary = _summary!;
    final nutrition = summary.nutrition;
    final activeId = summary.activeSessionId;
    final nextPlan = summary.nextPlan;

    return RefreshIndicator(
      onRefresh: () => _loadSummary(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 6, 16, 96),
        children: [
          if (_summaryRefreshError != null)
            StatusBanner.warning(
              'Données peut-être obsolètes : $_summaryRefreshError',
              margin: const EdgeInsets.only(bottom: 12),
              onClose: () => setState(() => _summaryRefreshError = null),
            ),
          if (activeId != null) ...[
            AppCard(
              borderColor: MaviohColors.amber.withValues(alpha: 0.4),
              child: Row(
                children: [
                  const Icon(Icons.play_circle_outline_rounded, color: MaviohColors.amber),
                  const SizedBox(width: 10),
                  const Expanded(
                    child: Text(
                      'Une séance est en cours.',
                      style: TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
                    ),
                  ),
                  FilledButton(
                    onPressed: () => _openWorkout(activeId),
                    child: const Text('Reprendre la séance en cours'),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 14),
          ],
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: 'Aujourd’hui',
                  value: fmtMinutes(summary.todayMinutes),
                  caption: fmtKcal(summary.todayCaloriesBurned),
                  icon: Icons.today_outlined,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: StatTile(
                  label: 'Semaine',
                  value: fmtMinutes(summary.weekMinutes),
                  caption: '${summary.weekSessions} séance${summary.weekSessions > 1 ? 's' : ''}',
                  icon: Icons.calendar_view_week_outlined,
                  tone: MaviohColors.sky,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: StatTile(
                  label: 'Série',
                  value: '${summary.streakDays} j',
                  caption: summary.streakDays > 0 ? 'Continue comme ça' : 'À relancer',
                  icon: Icons.local_fire_department_outlined,
                  tone: MaviohColors.amber,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          if (nutrition.caloriesBonus > 0)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: () => _explainBonus(nutrition),
                icon: const Icon(Icons.info_outline_rounded, size: 18),
                label: Text(
                  '+${fmtInt(nutrition.caloriesBonus)} kcal ajoutés à ton budget (${nutrition.coefPct} %)',
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
            ),
          const SizedBox(height: 6),
          if (nextPlan != null) ...[
            _NextPlanCard(
              plan: nextPlan,
              onPropose: _proposing ? null : () => _proposeForPlan(_planFromNext(nextPlan)),
              onLog: () => _logPlan(_planFromNext(nextPlan)),
            ),
            const SizedBox(height: 14),
          ],
          if (_proposing) ...[
            _ProposingRow(onCancel: _cancelPropose),
            const SizedBox(height: 14),
          ],
          Row(
            children: [
              Expanded(
                child: SizedBox(
                  height: 50,
                  child: FilledButton.icon(
                    onPressed: _generate,
                    icon: const Icon(Icons.auto_awesome_outlined, size: 19),
                    label: const Text('Générer une séance'),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: SizedBox(
                  height: 50,
                  child: OutlinedButton.icon(
                    onPressed: () => _quickActivity(),
                    icon: const Icon(Icons.bolt_outlined, size: 19),
                    label: const Text('Activité rapide'),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 18),
          const SectionHeader(eyebrow: 'Aujourd’hui', title: 'Tes séances du jour'),
          if (summary.todaySessions.isEmpty)
            EmptyState(
              icon: Icons.fitness_center_outlined,
              title: 'Aucune séance aujourd’hui',
              message: 'Génère une séance adaptée à ton contexte ou enregistre une activité déjà faite.',
              ctaLabel: 'Générer une séance',
              onCta: _generate,
              compact: true,
            )
          else
            for (final session in summary.todaySessions) ...[
              _SessionTile(session: session, onTap: () => _openSession(session)),
              const SizedBox(height: 10),
            ],
          if (summary.recoPost != null) ...[
            const SizedBox(height: 8),
            StatusBanner.info(summary.recoPost!),
          ],
        ],
      ),
    );
  }

  SportPlan _planFromNext(NextPlan next) {
    final calendar = _calendar;
    if (calendar != null) {
      final day = calendar.day(next.date);
      if (day != null) {
        for (final plan in day.plans) {
          if (plan.status == 'prevu' && plan.sportName == next.sportName) return plan;
        }
      }
    }
    for (final plan in _summary?.weekPlans ?? const <SportPlan>[]) {
      if (plan.status == 'prevu' && plan.date == next.date && plan.sportName == next.sportName) return plan;
    }
    return SportPlan(
      id: next.id ?? 0,
      date: next.date,
      sportName: next.sportName,
      plannedDurationMin: next.plannedDurationMin,
      plannedAt: next.plannedAt,
    );
  }

  // ----- Pane « Calendrier » -----------------------------------------------

  Widget _calendarPane() {
    if (_calendarLoading && _calendar == null) return const LoadingState(skeleton: true, skeletonCount: 2);
    if (_calendarError != null && _calendar == null) {
      return ErrorState(message: _calendarError!, onRetry: _loadCalendar);
    }
    final calendar = _calendar;
    final markers = <DateTime, CalendarMarkers>{};
    for (final day in calendar?.days ?? const <SportCalendarDay>[]) {
      final key = DateTime(day.date.year, day.date.month, day.date.day);
      markers[key] = CalendarMarkers(
        planned: day.hasPlanned,
        done: day.hasDone,
        cancelled: day.hasCancelled,
      );
    }
    final selected = calendar?.day(_selectedDay);

    return RefreshIndicator(
      onRefresh: () => _loadCalendar(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 6, 16, 110),
        children: [
          SectionHeader(
            eyebrow: 'Calendrier',
            title: 'Tes séances prévues',
            subtitle: calendar == null
                ? null
                : '${calendar.plannedCount} prévue${calendar.plannedCount > 1 ? 's' : ''} · '
                    '${calendar.doneCount} réalisée${calendar.doneCount > 1 ? 's' : ''} · ${fmtMinutes(calendar.minutesDone)}',
            action: TextButton.icon(
              onPressed: _planWeek,
              icon: const Icon(Icons.auto_awesome_outlined, size: 18),
              label: const Text('Planifier ma semaine'),
            ),
          ),
          AppCard(
            padding: const EdgeInsets.fromLTRB(8, 6, 8, 10),
            child: MonthCalendar(
              month: _month,
              selected: _selectedDay,
              markers: markers,
              onSelect: (date) => setState(() => _selectedDay = date),
              onMonthChanged: (month) {
                setState(() {
                  _month = month;
                  _calendar = null;
                });
                _loadCalendar();
              },
            ),
          ),
          const SizedBox(height: 10),
          const Row(
            children: [
              _LegendDot(color: MaviohColors.accent, label: 'Prévue'),
              SizedBox(width: 14),
              _LegendDot(color: MaviohColors.primary, label: 'Réalisée'),
              SizedBox(width: 14),
              _LegendDot(color: MaviohColors.border, label: 'Annulée'),
            ],
          ),
          const SizedBox(height: 16),
          Text(
            capitalize(fmtRelativeDay(_selectedDay)),
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: MaviohColors.text),
          ),
          const SizedBox(height: 10),
          if (_proposing) ...[
            _ProposingRow(onCancel: _cancelPropose),
            const SizedBox(height: 12),
          ],
          if (selected == null || selected.isEmpty)
            EmptyState(
              icon: Icons.event_note_outlined,
              title: 'Rien de prévu ce jour-là',
              message: 'Planifie une séance et Mavi’oh te proposera les exercices adaptés.',
              ctaLabel: 'Planifier',
              onCta: () => _openPlanSheet(date: _selectedDay),
              compact: true,
            )
          else ...[
            for (final plan in selected.plans) ...[
              _PlanCard(
                plan: plan,
                onLog: plan.status == 'prevu' ? () => _logPlan(plan) : null,
                onPropose: _proposing || plan.id == 0 ? null : () => _proposeForPlan(plan),
                onEdit: () => _openPlanSheet(plan: plan),
                onDelete: () => _deletePlan(plan),
              ),
              const SizedBox(height: 10),
            ],
            for (final session in selected.sessions) ...[
              _SessionTile(session: session, onTap: () => _openSession(session)),
              const SizedBox(height: 10),
            ],
          ],
        ],
      ),
    );
  }

  // ----- Pane « Séances » ---------------------------------------------------

  Widget _sessionsPane() {
    if (_sessionsLoading && _sessions == null) return const LoadingState(skeleton: true, skeletonCount: 3);
    if (_sessionsError != null && _sessions == null) {
      return ErrorState(message: _sessionsError!, onRetry: _loadSessions);
    }
    final all = _sessions ?? const <WorkoutSession>[];
    final visible = switch (_sessionFilter) {
      'terminee' => all.where((s) => s.isDone).toList(),
      'prevue' => all.where((s) => s.isPlanned || s.isInProgress).toList(),
      _ => all,
    };

    return RefreshIndicator(
      onRefresh: () => _loadSessions(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 6, 16, 96),
        children: [
          const SectionHeader(
            eyebrow: 'Séances',
            title: 'Ton historique',
            subtitle: 'Des 30 derniers jours aux 30 prochains.',
          ),
          Wrap(
            spacing: 8,
            children: [
              for (final filter in const [
                ('toutes', 'Toutes'),
                ('terminee', 'Terminées'),
                ('prevue', 'Prévues'),
              ])
                ChoiceChip(
                  label: Text(filter.$2),
                  selected: _sessionFilter == filter.$1,
                  onSelected: (_) => setState(() => _sessionFilter = filter.$1),
                ),
            ],
          ),
          const SizedBox(height: 14),
          if (visible.isEmpty)
            EmptyState(
              icon: Icons.history_toggle_off_outlined,
              title: 'Aucune séance',
              message: 'Tes séances générées, planifiées et tes activités rapides apparaîtront ici.',
              ctaLabel: 'Générer une séance',
              onCta: _generate,
              compact: true,
            )
          else
            for (final session in visible) ...[
              _SessionTile(session: session, showDate: true, onTap: () => _openSession(session)),
              const SizedBox(height: 10),
            ],
        ],
      ),
    );
  }
}

// ----- Sous-widgets ---------------------------------------------------------

class _ProposingRow extends StatelessWidget {
  const _ProposingRow({required this.onCancel});

  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      padding: const EdgeInsets.fromLTRB(16, 10, 8, 10),
      child: Row(
        children: [
          const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.4)),
          const SizedBox(width: 12),
          const Expanded(
            child: Text(
              'Le coach prépare ta séance…',
              style: TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
            ),
          ),
          TextButton(onPressed: onCancel, child: const Text(AppStrings.cancel)),
        ],
      ),
    );
  }
}

class _LegendDot extends StatelessWidget {
  const _LegendDot({required this.color, required this.label});

  final Color color;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
        const SizedBox(width: 5),
        Text(label, style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted, fontWeight: FontWeight.w600)),
      ],
    );
  }
}

class _NextPlanCard extends StatelessWidget {
  const _NextPlanCard({required this.plan, required this.onPropose, required this.onLog});

  final NextPlan plan;
  final VoidCallback? onPropose;
  final VoidCallback onLog;

  @override
  Widget build(BuildContext context) {
    final time = plan.plannedAt == null ? '' : ' · ${fmtTimeString(plan.plannedAt)}';
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: MaviohColors.tint(MaviohColors.accent, 0.2),
                  borderRadius: BorderRadius.circular(13),
                ),
                child: const Icon(Icons.event_outlined, color: MaviohColors.primary, size: 20),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Prochaine séance',
                      style: TextStyle(fontSize: 11, letterSpacing: 1, fontWeight: FontWeight.w800, color: MaviohColors.muted),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      '${capitalize(fmtRelativeDay(plan.date))} · ${plan.sportName} · ${fmtMinutes(plan.plannedDurationMin)}$time',
                      style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w800, color: MaviohColors.text),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: SizedBox(
                  height: 48,
                  child: OutlinedButton.icon(
                    onPressed: onPropose,
                    icon: const Icon(Icons.auto_awesome_outlined, size: 18),
                    label: const Text('Proposer une séance'),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: SizedBox(
                  height: 48,
                  child: FilledButton.icon(
                    onPressed: onLog,
                    icon: const Icon(Icons.check_rounded, size: 18),
                    label: const Text('J’ai fait cette séance'),
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _PlanCard extends StatelessWidget {
  const _PlanCard({
    required this.plan,
    required this.onLog,
    required this.onPropose,
    required this.onEdit,
    required this.onDelete,
  });

  final SportPlan plan;
  final VoidCallback? onLog;
  final VoidCallback? onPropose;
  final VoidCallback onEdit;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    final details = <String>[
      fmtMinutes(plan.plannedDurationMin),
      if (plan.plannedAt != null) fmtTimeString(plan.plannedAt),
      if (plan.lieu != null) AppStrings.vocabLabel(AppStrings.sportLieux, plan.lieu),
    ];
    return AppCard(
      padding: const EdgeInsets.fromLTRB(16, 12, 8, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: MaviohColors.tint(MaviohColors.primary),
                  borderRadius: BorderRadius.circular(12),
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
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      details.join(' · '),
                      style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                    ),
                  ],
                ),
              ),
              TonePill(
                label: AppStrings.planStatusLabels[plan.status] ?? plan.status,
                tone: plan.isDone
                    ? MaviohColors.primary
                    : plan.isCancelled
                        ? MaviohColors.slate
                        : MaviohColors.accent,
              ),
              PopupMenuButton<String>(
                tooltip: 'Plus d’actions',
                onSelected: (value) {
                  if (value == 'edit') onEdit();
                  if (value == 'delete') onDelete();
                },
                itemBuilder: (_) => const [
                  PopupMenuItem<String>(value: 'edit', child: Text(AppStrings.edit)),
                  PopupMenuItem<String>(value: 'delete', child: Text(AppStrings.delete)),
                ],
              ),
            ],
          ),
          if (plan.notes != null && plan.notes!.isNotEmpty) ...[
            const SizedBox(height: 8),
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: Text(
                plan.notes!,
                style: const TextStyle(fontSize: 12.5, color: MaviohColors.textSecondary, height: 1.35),
              ),
            ),
          ],
          if (onLog != null || onPropose != null) ...[
            const SizedBox(height: 12),
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: Row(
                children: [
                  if (onPropose != null) ...[
                    Expanded(
                      child: SizedBox(
                        height: 48,
                        child: OutlinedButton.icon(
                          onPressed: onPropose,
                          icon: const Icon(Icons.auto_awesome_outlined, size: 18),
                          label: const Text('Proposer une séance'),
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                  ],
                  if (onLog != null)
                    Expanded(
                      child: SizedBox(
                        height: 48,
                        child: FilledButton.icon(
                          onPressed: onLog,
                          icon: const Icon(Icons.check_rounded, size: 18),
                          label: const Text('Réaliser'),
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _SessionTile extends StatelessWidget {
  const _SessionTile({required this.session, required this.onTap, this.showDate = false});

  final WorkoutSession session;
  final VoidCallback onTap;
  final bool showDate;

  @override
  Widget build(BuildContext context) {
    final details = <String>[
      if (showDate) capitalize(fmtRelativeDay(session.date)),
      fmtMinutes(session.durationMin),
      if (session.caloriesBurned != null) fmtKcal(session.caloriesBurned),
    ];
    return AppCard(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      onTap: onTap,
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: MaviohColors.tint(MaviohColors.primary),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(
              session.isActivity ? Icons.directions_run : Icons.fitness_center,
              color: MaviohColors.primary,
              size: 20,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  session.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                ),
                const SizedBox(height: 2),
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        details.join(' · '),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                      ),
                    ),
                    if (session.caloriesBurned != null && !session.isManualCalories) ...[
                      const SizedBox(width: 6),
                      const EstimatePill(),
                    ],
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          TonePill(label: session.statusLabel, tone: _statusTone(session.status)),
        ],
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
