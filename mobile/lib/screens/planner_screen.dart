import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/planner.dart';
import '../models/recipe.dart';
import '../services/planner_service.dart';
import '../services/shopping_service.dart';
import '../theme/app_theme.dart';
import '../widgets/app_card.dart';
import '../widgets/empty_state.dart';
import '../widgets/error_state.dart';
import '../widgets/fm7_recipe_picker.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/status_banner.dart';

/// Planificateur de la semaine (§12 + §16.4).
///
/// Rendu dans l’onglet « Plus » : pas de `Scaffold` ni d’`AppBar` ici.
class PlannerScreen extends StatefulWidget {
  const PlannerScreen({super.key, this.onNavigate});

  /// Navigation rapide vers une autre section (slug).
  final ValueChanged<String>? onNavigate;

  @override
  State<PlannerScreen> createState() => _PlannerScreenState();
}

class _PlannerScreenState extends State<PlannerScreen> {
  final _service = PlannerService();
  final _shopping = ShoppingService();

  late DateTime _weekStart = weekStart(today());
  PlannerWeek? _week;
  bool _loading = true;
  bool _sendingToShopping = false;
  String? _error;
  String? _refreshError;

  String get _cacheKey => 'planner:${isoDate(_weekStart)}';

  @override
  void initState() {
    super.initState();
    _restoreOrLoad();
  }

  void _restoreOrLoad() {
    final cached = Session.instance.cached(_cacheKey);
    if (cached != null) {
      _week = PlannerWeek.fromJson(ApiClient.asMap(cached.data['data']));
      _loading = false;
      if (cached.isStale()) _load(silent: true);
    } else {
      _load();
    }
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = _week == null;
        _error = null;
        _refreshError = null;
      });
    }
    try {
      final json = await _service.weekRaw(weekStart: _weekStart);
      if (!mounted) return;
      Session.instance.put(_cacheKey, json);
      setState(() {
        _week = PlannerWeek.fromJson(ApiClient.asMap(json['data']));
        _loading = false;
        _error = null;
        _refreshError = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (_week == null) {
          _error = e.message;
        } else {
          _refreshError = e.message;
          if (!silent) _snack(e.message);
        }
      });
    }
  }

  void _snack(String message, {SnackBarAction? action}) {
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(message), action: action));
  }

  void _invalidatePlanner() => Session.instance.invalidatePrefix('planner');

  void _shiftWeek(int weeks) {
    setState(() {
      _weekStart = _weekStart.add(Duration(days: 7 * weeks));
      _week = null;
      _loading = true;
      _error = null;
      _refreshError = null;
    });
    _restoreOrLoad();
  }

  // ----- Actions ------------------------------------------------------------

  Future<void> _openPlanSheet({required DateTime date, required String mealType, MealPlan? plan}) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _PlanSheet(date: date, mealType: mealType, plan: plan),
    );
    if (saved != true || !mounted) return;
    _invalidatePlanner();
    _snack(plan == null ? 'Repas ajouté au planning.' : 'Planning mis à jour.');
    await _load(silent: true);
  }

  Future<void> _logPlan(MealPlan plan) async {
    final done = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _LogPlanSheet(plan: plan),
    );
    if (done != true || !mounted) return;
    _invalidatePlanner();
    Session.instance.invalidatePrefix('meals');
    Session.instance.invalidate('dashboard');
    Session.instance.invalidate('stock');
    _snack('Repas enregistré');
    await _load(silent: true);
  }

  Future<void> _deletePlan(MealPlan plan) async {
    final ok = await ConfirmDeleteDialog.show(context, title: plan.title);
    if (!ok || !mounted) return;
    try {
      await _service.delete(plan.id);
      if (!mounted) return;
      _invalidatePlanner();
      _snack('Repas retiré du planning.');
      await _load(silent: true);
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.message);
    }
  }

  Future<void> _generateWeek() async {
    final count = await showModalBottomSheet<int>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _GenerateWeekSheet(weekStart: _weekStart),
    );
    if (count == null || !mounted) return;
    _invalidatePlanner();
    _snack(count > 1 ? '$count repas planifiés' : '$count repas planifié');
    await _load(silent: true);
  }

  Future<void> _sendToShoppingList() async {
    setState(() => _sendingToShopping = true);
    try {
      final result = await _shopping.generate(weekStart: _weekStart);
      if (!mounted) return;
      Session.instance.invalidate('shopping');
      setState(() => _sendingToShopping = false);
      final n = result.addedCount;
      _snack(
        n > 1 ? '$n articles ajoutés' : '$n article ajouté',
        action: SnackBarAction(
          label: 'Voir',
          onPressed: () => widget.onNavigate?.call('liste-course'),
        ),
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _sendingToShopping = false);
      _snack(e.message);
    }
  }

  // ----- Build --------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    if (_loading && _week == null) return const LoadingState(skeleton: true, skeletonCount: 4);
    final error = _error;
    if (error != null && _week == null) return ErrorState(message: error, onRetry: _load);
    final week = _week!;

    return RefreshIndicator(
      onRefresh: () => _load(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
        children: [
          _WeekHeader(
            weekStart: _weekStart,
            onPrevious: () => _shiftWeek(-1),
            onNext: () => _shiftWeek(1),
            onToday: () {
              final monday = weekStart(today());
              if (isoDate(monday) == isoDate(_weekStart)) return;
              setState(() {
                _weekStart = monday;
                _week = null;
                _loading = true;
              });
              _restoreOrLoad();
            },
          ),
          if (_refreshError != null)
            StatusBanner.warning(
              'Données peut-être obsolètes : $_refreshError',
              margin: const EdgeInsets.only(bottom: 12),
              onClose: () => setState(() => _refreshError = null),
            ),
          Row(
            children: [
              Expanded(
                child: FilledButton.icon(
                  onPressed: _generateWeek,
                  icon: const Icon(Icons.auto_awesome_outlined, size: 18),
                  label: const Text('Générer la semaine'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: _sendingToShopping ? null : _sendToShoppingList,
                  icon: _sendingToShopping
                      ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2.2))
                      : const Icon(Icons.shopping_cart_outlined, size: 18),
                  label: const Text('Envoyer à la liste de courses'),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          if (week.plansCount == 0)
            AppCard(
              margin: const EdgeInsets.only(bottom: 14),
              child: EmptyState(
                icon: Icons.calendar_month_outlined,
                title: 'Aucun repas prévu cette semaine',
                message: 'Ajoute un repas dans un créneau ou laisse Mavi’oh composer ta semaine.',
                ctaLabel: 'Générer la semaine',
                onCta: _generateWeek,
                compact: true,
              ),
            ),
          for (final day in week.days) ...[
            _DayCard(
              day: day,
              onAdd: (mealType) => _openPlanSheet(date: day.date, mealType: mealType),
              onLog: _logPlan,
              onEdit: (plan) => _openPlanSheet(date: day.date, mealType: plan.mealType, plan: plan),
              onDelete: _deletePlan,
            ),
            const SizedBox(height: 12),
          ],
          const SizedBox(height: 4),
          const Text(
            AppStrings.disclaimer,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 11.5, color: MaviohColors.muted, height: 1.4),
          ),
        ],
      ),
    );
  }
}

/// Confirmation before removing a plan.
class ConfirmDeleteDialog {
  ConfirmDeleteDialog._();

  static Future<bool> show(BuildContext context, {required String title}) async {
    final result = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.delete_outline_rounded, color: MaviohColors.error, size: 30),
        title: const Text('Supprimer du planning ?'),
        content: Text('« $title » sera retiré de ta semaine. Les repas déjà enregistrés ne changent pas.'),
        actionsPadding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text(AppStrings.cancel)),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            style: FilledButton.styleFrom(backgroundColor: MaviohColors.error),
            child: const Text(AppStrings.delete),
          ),
        ],
      ),
    );
    return result ?? false;
  }
}

class _WeekHeader extends StatelessWidget {
  const _WeekHeader({
    required this.weekStart,
    required this.onPrevious,
    required this.onNext,
    required this.onToday,
  });

  final DateTime weekStart;
  final VoidCallback onPrevious;
  final VoidCallback onNext;
  final VoidCallback onToday;

  @override
  Widget build(BuildContext context) {
    final end = weekStart.add(const Duration(days: 6));
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        children: [
          IconButton(
            tooltip: 'Semaine précédente',
            onPressed: onPrevious,
            icon: const Icon(Icons.chevron_left_rounded),
          ),
          Expanded(
            child: GestureDetector(
              onTap: onToday,
              behavior: HitTestBehavior.opaque,
              child: Column(
                children: [
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(
                      'Semaine du ${fmtDayShort(weekStart)}',
                      style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: MaviohColors.text),
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    'du ${fmtDateFr(weekStart)} au ${fmtDateFr(end)}',
                    style: const TextStyle(fontSize: 12, color: MaviohColors.muted, fontWeight: FontWeight.w600),
                  ),
                ],
              ),
            ),
          ),
          IconButton(
            tooltip: 'Semaine suivante',
            onPressed: onNext,
            icon: const Icon(Icons.chevron_right_rounded),
          ),
        ],
      ),
    );
  }
}

class _DayCard extends StatelessWidget {
  const _DayCard({
    required this.day,
    required this.onAdd,
    required this.onLog,
    required this.onEdit,
    required this.onDelete,
  });

  final PlannerDay day;
  final ValueChanged<String> onAdd;
  final ValueChanged<MealPlan> onLog;
  final ValueChanged<MealPlan> onEdit;
  final ValueChanged<MealPlan> onDelete;

  @override
  Widget build(BuildContext context) {
    final isToday = isoDate(day.date) == isoDate(today());
    return AppCard(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
      borderColor: isToday ? MaviohColors.primary.withValues(alpha: 0.45) : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  capitalize(fmtDay(day.date)),
                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                ),
              ),
              if (isToday) ...[
                const TonePill(label: 'Aujourd’hui', tone: MaviohColors.primary),
                const SizedBox(width: 8),
              ],
              Text(
                day.totalCalories == null ? '—' : fmtKcal(day.totalCalories),
                style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: MaviohColors.muted),
              ),
            ],
          ),
          const SizedBox(height: 2),
          Text(
            'Total prévu de la journée (estimation)',
            style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted).copyWith(height: 1.3),
          ),
          for (final type in AppStrings.mealTypeOrder)
            _SlotRow(
              mealType: type,
              plans: day.plansFor(type),
              onAdd: () => onAdd(type),
              onLog: onLog,
              onEdit: onEdit,
              onDelete: onDelete,
            ),
        ],
      ),
    );
  }
}

class _SlotRow extends StatelessWidget {
  const _SlotRow({
    required this.mealType,
    required this.plans,
    required this.onAdd,
    required this.onLog,
    required this.onEdit,
    required this.onDelete,
  });

  final String mealType;
  final List<MealPlan> plans;
  final VoidCallback onAdd;
  final ValueChanged<MealPlan> onLog;
  final ValueChanged<MealPlan> onEdit;
  final ValueChanged<MealPlan> onDelete;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  AppStrings.mealType(mealType),
                  style: const TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 0.5,
                    color: MaviohColors.muted,
                  ),
                ),
              ),
              IconButton(
                tooltip: 'Ajouter ${AppStrings.mealTypeDative(mealType)}',
                onPressed: onAdd,
                icon: const Icon(Icons.add_circle_outline_rounded, size: 22),
                color: MaviohColors.primary,
              ),
            ],
          ),
          if (plans.isEmpty)
            const Padding(
              padding: EdgeInsets.only(left: 2, bottom: 2),
              child: Text('Rien de prévu', style: TextStyle(fontSize: 12.5, color: MaviohColors.muted)),
            )
          else
            for (final plan in plans)
              _PlanTile(
                plan: plan,
                onLog: () => onLog(plan),
                onEdit: () => onEdit(plan),
                onDelete: () => onDelete(plan),
              ),
        ],
      ),
    );
  }
}

class _PlanTile extends StatelessWidget {
  const _PlanTile({required this.plan, required this.onLog, required this.onEdit, required this.onDelete});

  final MealPlan plan;
  final VoidCallback onLog;
  final VoidCallback onEdit;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    final subtitle = <String>[
      '${fmtDecimal(plan.servings)} ${plan.servings > 1 ? 'portions' : 'portion'}',
      if (plan.calories != null) fmtKcal(plan.calories),
    ].join(' · ');

    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
      decoration: BoxDecoration(
        color: MaviohColors.surface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: MaviohColors.borderSoft),
      ),
      child: Row(
        children: [
          Icon(
            plan.recipeId != null ? Icons.menu_book_outlined : Icons.edit_note_rounded,
            size: 18,
            color: MaviohColors.muted,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  plan.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
                ),
                const SizedBox(height: 2),
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        subtitle,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 12, color: MaviohColors.muted, fontWeight: FontWeight.w600),
                      ),
                    ),
                    if (plan.calories != null) ...[
                      const SizedBox(width: 6),
                      const EstimatePill(),
                    ],
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 6),
          _StatusPill(status: plan.status),
          PopupMenuButton<String>(
            tooltip: 'Actions du repas prévu',
            icon: const Icon(Icons.more_vert_rounded, size: 20),
            onSelected: (value) {
              switch (value) {
                case 'log':
                  onLog();
                case 'edit':
                  onEdit();
                case 'delete':
                  onDelete();
              }
            },
            itemBuilder: (context) => [
              if (!plan.isDone)
                const PopupMenuItem<String>(
                  value: 'log',
                  child: ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(Icons.check_circle_outline_rounded),
                    title: Text('Réaliser'),
                  ),
                ),
              const PopupMenuItem<String>(
                value: 'edit',
                child: ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(Icons.edit_outlined),
                  title: Text(AppStrings.edit),
                ),
              ),
              const PopupMenuItem<String>(
                value: 'delete',
                child: ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(Icons.delete_outline_rounded, color: MaviohColors.error),
                  title: Text(AppStrings.delete, style: TextStyle(color: MaviohColors.error)),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.status});

  final String status;

  @override
  Widget build(BuildContext context) {
    switch (status) {
      case 'realise':
        return const TonePill(label: 'Réalisé', tone: MaviohColors.success, icon: Icons.check_rounded);
      case 'annule':
        return const TonePill(label: 'Annulé', tone: MaviohColors.slate, icon: Icons.block_rounded);
      default:
        return const TonePill(label: 'Prévu', tone: MaviohColors.sky, icon: Icons.schedule_rounded);
    }
  }
}

// ----- Sheets ---------------------------------------------------------------

/// Ajout / modification d’un repas prévu (onglets Recette / Titre libre).
class _PlanSheet extends StatefulWidget {
  const _PlanSheet({required this.date, required this.mealType, this.plan});

  final DateTime date;
  final String mealType;
  final MealPlan? plan;

  @override
  State<_PlanSheet> createState() => _PlanSheetState();
}

class _PlanSheetState extends State<_PlanSheet> {
  final _service = PlannerService();
  final _titleController = TextEditingController();
  final _notesController = TextEditingController();

  bool _recipeTab = true;
  Recipe? _recipe;
  late final String _mealType = widget.mealType;
  late double _servings = widget.plan?.servings ?? 1;
  bool _saving = false;
  String? _error;
  String? _titleError;

  bool get _isEdit => widget.plan != null;

  @override
  void initState() {
    super.initState();
    final plan = widget.plan;
    if (plan != null) {
      _titleController.text = plan.title;
      _notesController.text = plan.notes ?? '';
      _recipeTab = false;
    }
  }

  @override
  void dispose() {
    _titleController.dispose();
    _notesController.dispose();
    super.dispose();
  }

  bool get _canSubmit {
    if (_saving) return false;
    if (_isEdit) return true;
    if (_recipeTab) return _recipe != null;
    return _titleController.text.trim().isNotEmpty;
  }

  Future<void> _submit() async {
    setState(() {
      _saving = true;
      _error = null;
      _titleError = null;
    });
    try {
      final plan = widget.plan;
      if (plan != null) {
        await _service.update(plan.id, {
          'meal_type': _mealType,
          'servings': _servings,
          if (plan.recipeId == null) 'title': _titleController.text.trim(),
          'notes': _notesController.text.trim().isEmpty ? null : _notesController.text.trim(),
        });
      } else {
        await _service.create(
          date: widget.date,
          mealType: _mealType,
          recipeId: _recipeTab ? _recipe?.id : null,
          title: _recipeTab ? null : _titleController.text.trim(),
          servings: _servings,
          notes: _notesController.text.trim().isEmpty ? null : _notesController.text.trim(),
        );
      }
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
        _titleError = e.fieldError('title') ?? e.fieldError('recipe_id');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final inset = MediaQuery.viewInsetsOf(context).bottom;
    final height = MediaQuery.sizeOf(context).height;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 4, 20, 20 + inset),
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: height * 0.9),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                _isEdit ? 'Modifier le repas prévu' : 'Ajouter au planning',
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
              ),
              const SizedBox(height: 4),
              Text(
                '${capitalize(fmtDay(widget.date))} · ${AppStrings.mealType(_mealType)}',
                style: const TextStyle(color: MaviohColors.muted, fontWeight: FontWeight.w600),
              ),
              const SizedBox(height: 14),
              if (_error != null)
                StatusBanner.error(_error!, margin: const EdgeInsets.only(bottom: 12)),
              if (!_isEdit) ...[
                SegmentedButton<bool>(
                  segments: const [
                    ButtonSegment<bool>(value: true, label: Text('Recette'), icon: Icon(Icons.menu_book_outlined)),
                    ButtonSegment<bool>(value: false, label: Text('Titre libre'), icon: Icon(Icons.edit_note_rounded)),
                  ],
                  selected: {_recipeTab},
                  showSelectedIcon: false,
                  onSelectionChanged: (values) => setState(() => _recipeTab = values.first),
                ),
                const SizedBox(height: 14),
              ],
              if (!_isEdit && _recipeTab)
                Fm7RecipeSearch(
                  selectedId: _recipe?.id,
                  listHeight: 220,
                  onSelected: (recipe) => setState(() => _recipe = recipe),
                )
              else if (_isEdit && widget.plan?.recipeId != null)
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: MaviohColors.surface,
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: MaviohColors.borderSoft),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.menu_book_outlined, size: 18, color: MaviohColors.muted),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          widget.plan!.title,
                          style: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text),
                        ),
                      ),
                    ],
                  ),
                )
              else
                TextField(
                  controller: _titleController,
                  textCapitalization: TextCapitalization.sentences,
                  decoration: InputDecoration(
                    labelText: 'Titre du repas',
                    hintText: 'Ex. : Poulet riz brocolis',
                    errorText: _titleError,
                  ),
                  onChanged: (_) => setState(() {}),
                ),
              const SizedBox(height: 14),
              _ServingsStepper(
                value: _servings,
                onChanged: (value) => setState(() => _servings = value),
              ),
              const SizedBox(height: 14),
              TextField(
                controller: _notesController,
                maxLines: 2,
                textCapitalization: TextCapitalization.sentences,
                decoration: const InputDecoration(labelText: 'Notes (facultatif)'),
              ),
              const SizedBox(height: 18),
              FilledButton(
                onPressed: _canSubmit ? _submit : null,
                child: _saving
                    ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.4))
                    : Text(_isEdit ? AppStrings.save : 'Ajouter au planning'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ServingsStepper extends StatelessWidget {
  const _ServingsStepper({required this.value, required this.onChanged});

  final double value;
  final ValueChanged<double> onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        const Expanded(
          child: Text('Portions', style: TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.textSecondary)),
        ),
        IconButton.filledTonal(
          tooltip: 'Moins une demi-portion',
          onPressed: value <= 0.5 ? null : () => onChanged((value - 0.5).clamp(0.5, 20)),
          icon: const Icon(Icons.remove_rounded),
        ),
        SizedBox(
          width: 60,
          child: Text(
            fmtDecimal(value),
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: MaviohColors.text),
          ),
        ),
        IconButton.filledTonal(
          tooltip: 'Plus une demi-portion',
          onPressed: value >= 20 ? null : () => onChanged((value + 0.5).clamp(0.5, 20)),
          icon: const Icon(Icons.add_rounded),
        ),
      ],
    );
  }
}

/// « Réaliser » : enregistre le repas prévu (avec retrait du stock optionnel).
class _LogPlanSheet extends StatefulWidget {
  const _LogPlanSheet({required this.plan});

  final MealPlan plan;

  @override
  State<_LogPlanSheet> createState() => _LogPlanSheetState();
}

class _LogPlanSheetState extends State<_LogPlanSheet> {
  final _service = PlannerService();
  bool _decrementStock = true;
  bool _saving = false;
  String? _error;

  Future<void> _submit() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await _service.log(widget.plan.id, decrementStock: _decrementStock);
      if (!mounted) return;
      Navigator.of(context).pop(true);
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
    final inset = MediaQuery.viewInsetsOf(context).bottom;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 4, 20, 20 + inset),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'Réaliser ce repas',
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
          ),
          const SizedBox(height: 4),
          Text(
            '${widget.plan.title} · ${AppStrings.mealType(widget.plan.mealType)}',
            style: const TextStyle(color: MaviohColors.muted, fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 14),
          if (_error != null) StatusBanner.error(_error!, margin: const EdgeInsets.only(bottom: 12)),
          SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            value: _decrementStock,
            title: const Text('Retirer du stock'),
            subtitle: const Text('Les ingrédients disponibles seront décomptés de ton stock.'),
            onChanged: _saving ? null : (value) => setState(() => _decrementStock = value),
          ),
          const SizedBox(height: 10),
          FilledButton(
            onPressed: _saving ? null : _submit,
            child: _saving
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.4))
                : const Text('Enregistrer le repas'),
          ),
        ],
      ),
    );
  }
}

/// « Générer la semaine » : types de repas + remplacement.
class _GenerateWeekSheet extends StatefulWidget {
  const _GenerateWeekSheet({required this.weekStart});

  final DateTime weekStart;

  @override
  State<_GenerateWeekSheet> createState() => _GenerateWeekSheetState();
}

class _GenerateWeekSheetState extends State<_GenerateWeekSheet> {
  final _service = PlannerService();
  final Set<String> _mealTypes = {'dejeuner', 'diner'};
  bool _replace = false;
  bool _saving = false;
  String? _error;

  Future<void> _submit() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final result = await _service.generate(
        weekStart: widget.weekStart,
        mealTypes: AppStrings.mealTypeOrder.where(_mealTypes.contains).toList(),
        replace: _replace,
      );
      if (!mounted) return;
      Navigator.of(context).pop(result.generatedCount);
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
    final inset = MediaQuery.viewInsetsOf(context).bottom;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 4, 20, 20 + inset),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'Générer la semaine',
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
          ),
          const SizedBox(height: 4),
          Text(
            'Semaine du ${fmtDayShort(widget.weekStart)} · recettes compatibles avec ton régime et tes cibles (estimation).',
            style: const TextStyle(color: MaviohColors.muted, height: 1.4),
          ),
          const SizedBox(height: 14),
          if (_error != null) StatusBanner.error(_error!, margin: const EdgeInsets.only(bottom: 12)),
          const Text('Repas à remplir', style: TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.textSecondary)),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final type in AppStrings.mealTypeOrder)
                FilterChip(
                  label: Text(AppStrings.mealType(type)),
                  selected: _mealTypes.contains(type),
                  onSelected: _saving
                      ? null
                      : (selected) => setState(() {
                            if (selected) {
                              _mealTypes.add(type);
                            } else {
                              _mealTypes.remove(type);
                            }
                          }),
                ),
            ],
          ),
          const SizedBox(height: 6),
          SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            value: _replace,
            title: const Text('Remplacer l’existant'),
            subtitle: const Text('Les repas déjà prévus sur ces créneaux seront remplacés.'),
            onChanged: _saving ? null : (value) => setState(() => _replace = value),
          ),
          const SizedBox(height: 10),
          FilledButton(
            onPressed: _saving || _mealTypes.isEmpty ? null : _submit,
            child: _saving
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.4))
                : const Text('Générer la semaine'),
          ),
        ],
      ),
    );
  }
}
