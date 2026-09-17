import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/dashboard.dart';
import '../models/food.dart';
import '../models/recommendation.dart';
import '../services/dashboard_service.dart';
import '../services/food_service.dart';
import '../services/weight_service.dart';
import '../theme/app_theme.dart';
import '../widgets/add_to_meal_sheet.dart';
import '../widgets/app_card.dart';
import '../widgets/barcode_scanner_sheet.dart';
import '../widgets/budget_card.dart';
import '../widgets/error_state.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/notification_bell.dart';
import '../widgets/sparkline.dart';
import '../widgets/stat_tile.dart';
import '../widgets/status_banner.dart';

/// Accueil (§16.2): greeting, budget, next meal, coach, stock alerts, sport, weight.
class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key, this.onNavigate});

  final ValueChanged<String>? onNavigate;

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  static const String _cacheKey = 'dashboard';

  final _service = DashboardService();
  Dashboard? _data;
  bool _loading = true;
  String? _error;
  String? _refreshError;

  @override
  void initState() {
    super.initState();
    final cached = Session.instance.cached(_cacheKey);
    if (cached != null) {
      _data = Dashboard.fromJson(cached.data);
      _loading = false;
      if (cached.isStale()) _load(silent: true);
    } else {
      _load();
    }
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = _data == null;
        _error = null;
        _refreshError = null;
      });
    }
    try {
      final data = await _service.raw();
      if (!mounted) return;
      Session.instance.put(_cacheKey, data);
      final dashboard = Dashboard.fromJson(data);
      NotificationBell.unread.value = dashboard.notificationsUnread;
      final me = Session.instance.user;
      if (me != null && me.hasProfile != dashboard.hasProfile) {
        Session.instance.updateUser(me.copyWith(hasProfile: dashboard.hasProfile));
      }
      setState(() {
        _data = dashboard;
        _loading = false;
        _error = null;
        _refreshError = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (_data == null) {
          _error = e.message;
        } else {
          _refreshError = e.message;
          if (!silent) {
            ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(e.message)));
          }
        }
      });
    }
  }

  void _navigate(String slug) => widget.onNavigate?.call(slug);

  Future<void> _addToMeal({String? mealType}) async {
    final result = await AddToMealSheet.show(context, mealType: mealType ?? _data?.nextMealType);
    if (result != null && mounted) _load(silent: true);
  }

  Future<void> _scanAndAdd() async {
    final code = await BarcodeScannerSheet.show(context);
    if (code == null || !mounted) return;
    Food? food;
    try {
      food = await FoodService().tryByBarcode(code);
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(e.message)));
      return;
    }
    if (!mounted) return;
    final result = await AddToMealSheet.show(
      context,
      mealType: _data?.nextMealType,
      preset: food == null ? null : AddToMealPreset.food(food, quantity: food.servingSizeG != null ? 1 : 100, unit: food.servingSizeG != null ? 'portion' : 'g'),
      mode: food == null ? AddToMealMode.search : AddToMealMode.portionOnly,
    );
    if (result != null && mounted) _load(silent: true);
  }

  Future<void> _logWeight() async {
    final current = _data?.weight.current;
    final value = await showModalBottomSheet<double>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _WeightSheet(initial: current),
    );
    if (value == null || !mounted) return;
    try {
      await WeightService().log(value);
      if (!mounted) return;
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        SnackBar(content: Text('Poids enregistré : ${fmtDecimal(value)}${nbsp}kg')),
      );
      Session.instance.invalidate('profile');
      _load(silent: true);
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _data == null) return const LoadingState(skeleton: true, skeletonCount: 4);
    if (_error != null && _data == null) return ErrorState(message: _error!, onRetry: _load);
    final data = _data!;

    return RefreshIndicator(
      onRefresh: () => _load(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 96),
        children: [
          _Greeting(firstName: data.firstName.isEmpty ? (Session.instance.user?.firstName ?? '') : data.firstName, date: data.date),
          if (_refreshError != null)
            StatusBanner.warning(
              'Données peut-être obsolètes : $_refreshError',
              margin: const EdgeInsets.only(bottom: 12),
              onClose: () => setState(() => _refreshError = null),
            ),
          if (!data.hasProfile) ...[
            AppCard.hero(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(color: MaviohColors.tint(MaviohColors.rose), borderRadius: BorderRadius.circular(14)),
                    child: const Icon(Icons.person_outline_rounded, color: MaviohColors.rose),
                  ),
                  const SizedBox(height: 14),
                  const Text('Bienvenue sur Mavi’oh !', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: MaviohColors.text)),
                  const SizedBox(height: 6),
                  const Text(
                    'Renseigne ton profil (taille, poids, objectif, activité) pour obtenir ton budget calorique et des conseils adaptés. Deux minutes suffisent.',
                    style: TextStyle(color: MaviohColors.textTertiary, height: 1.45),
                  ),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    onPressed: () => _navigate('profil'),
                    icon: const Icon(Icons.arrow_forward_rounded),
                    label: const Text('Compléter mon profil'),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
          ],
          BudgetCard(
            consumed: data.consumed,
            targets: data.targets,
            remaining: data.remaining,
            caloriesBonus: data.caloriesBonus,
            isEstimate: data.isEstimate,
            onTap: () => _navigate('historique-repas-journee'),
          ),
          const SizedBox(height: 14),
          _NextMealCard(data: data, onAdd: () => _addToMeal(), onScan: _scanAndAdd),
          const SizedBox(height: 14),
          _CoachCard(
            recommendations: data.recommendations,
            onSeeAll: () => _navigate('recommandations-repas-journee'),
            onAction: _handleRecoAction,
          ),
          if (data.stock.hasAlerts) ...[
            const SizedBox(height: 14),
            _StockAlertCard(stock: data.stock, onOpen: () => _navigate('stock')),
          ],
          const SizedBox(height: 14),
          _SportCard(sport: data.sport, onOpen: () => _navigate('sport')),
          const SizedBox(height: 14),
          _WeightCard(weight: data.weight, onLog: _logWeight, onProfile: () => _navigate('profil')),
          const SizedBox(height: 8),
          const Text(
            AppStrings.disclaimer,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 11.5, color: MaviohColors.muted, height: 1.4),
          ),
        ],
      ),
    );
  }

  Future<void> _handleRecoAction(RecoAction action) async {
    switch (action.kind) {
      case 'ajouter_au_repas':
        final result = await AddToMealSheet.show(
          context,
          mealType: action.mealType ?? _data?.nextMealType,
          preset: AddToMealPreset.ids(foodId: action.foodId, recipeId: action.recipeId, quantity: action.quantity, unit: action.unit),
          mode: AddToMealMode.portionOnly,
        );
        if (result != null && mounted) _load(silent: true);
        break;
      case 'ouvrir_recette':
        _navigate('recettes');
        break;
      case 'ouvrir_stock':
      case 'supprimer_stock':
        _navigate('stock');
        break;
      case 'generer_seance':
        _navigate('sport');
        break;
      case 'ajouter_courses':
        _navigate('liste-course');
        break;
      case 'ouvrir_planificateur':
        _navigate('planificateur-semaine');
        break;
      default:
        _navigate('recommandations-repas-journee');
    }
  }
}

class _Greeting extends StatelessWidget {
  final String firstName;
  final DateTime date;

  const _Greeting({required this.firstName, required this.date});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(2, 4, 2, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            firstName.isEmpty ? 'Bonjour' : 'Bonjour $firstName',
            style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: MaviohColors.text),
          ),
          const SizedBox(height: 2),
          Text(capitalize(fmtDay(date)), style: const TextStyle(color: MaviohColors.muted, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}

class _NextMealCard extends StatelessWidget {
  final Dashboard data;
  final VoidCallback onAdd;
  final VoidCallback onScan;

  const _NextMealCard({required this.data, required this.onAdd, required this.onScan});

  @override
  Widget build(BuildContext context) {
    final type = data.nextMealType ?? 'dejeuner';
    final meal = data.nextMeal;
    final label = AppStrings.mealType(type);
    final detail = meal == null || meal.itemsCount == 0
        ? 'rien enregistré'
        : '${fmtKcal(meal.calories)} · ${meal.itemsCount} aliment${meal.itemsCount > 1 ? 's' : ''}';

    return AppCard(
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(color: MaviohColors.tint(MaviohColors.indigo), borderRadius: BorderRadius.circular(14)),
            child: const Icon(Icons.restaurant_rounded, color: MaviohColors.indigo),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Prochain repas', style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: MaviohColors.muted, letterSpacing: 0.4)),
                const SizedBox(height: 2),
                Text('$label · $detail', maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text)),
              ],
            ),
          ),
          const SizedBox(width: 8),
          IconButton(tooltip: 'Scanner un code-barres', onPressed: onScan, icon: const Icon(Icons.qr_code_scanner_rounded)),
          FilledButton(onPressed: onAdd, style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 14)), child: const Text('Ajouter')),
        ],
      ),
    );
  }
}

class _CoachCard extends StatelessWidget {
  final List<Recommendation> recommendations;
  final VoidCallback onSeeAll;
  final ValueChanged<RecoAction> onAction;

  const _CoachCard({required this.recommendations, required this.onSeeAll, required this.onAction});

  Color _tone(int priority) {
    switch (priority) {
      case 1:
        return MaviohColors.rose;
      case 2:
        return MaviohColors.amber;
      default:
        return MaviohColors.slate;
    }
  }

  @override
  Widget build(BuildContext context) {
    final top = recommendations.take(2).toList();
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.tips_and_updates_outlined, color: MaviohColors.teal, size: 20),
              const SizedBox(width: 8),
              const Expanded(child: Text('Coach du jour', style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text))),
              TextButton(onPressed: onSeeAll, child: const Text(AppStrings.seeAll)),
            ],
          ),
          if (top.isEmpty)
            const Padding(
              padding: EdgeInsets.only(top: 4),
              child: Text('Aucun conseil pour le moment : enregistre un repas pour que le coach puisse t’aider.', style: TextStyle(color: MaviohColors.muted, height: 1.4)),
            )
          else
            for (final reco in top) ...[
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
                decoration: BoxDecoration(
                  color: MaviohColors.surface,
                  borderRadius: BorderRadius.circular(14),
                  border: Border(left: BorderSide(color: _tone(reco.priority), width: 4)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(child: Text(reco.title, style: const TextStyle(fontWeight: FontWeight.w800, color: MaviohColors.text))),
                        if (reco.isEstimate) const EstimatePill(),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(reco.message, style: const TextStyle(color: MaviohColors.textTertiary, height: 1.4, fontSize: 13.5)),
                    if (reco.actions.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Wrap(
                        spacing: 8,
                        runSpacing: 6,
                        children: [
                          for (final action in reco.actions.take(2))
                            ActionChip(label: Text(action.buttonLabel), onPressed: () => onAction(action)),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
            ],
        ],
      ),
    );
  }
}

class _StockAlertCard extends StatelessWidget {
  final DashboardStock stock;
  final VoidCallback onOpen;

  const _StockAlertCard({required this.stock, required this.onOpen});

  @override
  Widget build(BuildContext context) {
    final parts = <String>[
      if (stock.expiredCount > 0) '${stock.expiredCount} périmé${stock.expiredCount > 1 ? 's' : ''}',
      if (stock.expiringCount > 0) '${stock.expiringCount} bientôt périmé${stock.expiringCount > 1 ? 's' : ''}',
      if (stock.lowCount > 0) '${stock.lowCount} en stock bas',
    ];
    final tone = stock.expiredCount > 0 ? MaviohColors.error : MaviohColors.warning;
    return AppCard(
      onTap: onOpen,
      borderColor: tone.withValues(alpha: 0.35),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.kitchen_outlined, color: tone, size: 20),
              const SizedBox(width: 8),
              Expanded(child: Text('Stock : ${parts.join(', ')}', style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text))),
              const Icon(Icons.chevron_right_rounded, color: MaviohColors.muted),
            ],
          ),
          if (stock.expiring.isNotEmpty) ...[
            const SizedBox(height: 8),
            for (final item in stock.expiring.take(3))
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Row(
                  children: [
                    Expanded(child: Text(item.label, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: MaviohColors.textSecondary, fontWeight: FontWeight.w600))),
                    Text(fmtDaysLeft(item.daysLeft), style: TextStyle(color: (item.daysLeft ?? 1) < 0 ? MaviohColors.error : MaviohColors.warning, fontWeight: FontWeight.w700, fontSize: 12.5)),
                  ],
                ),
              ),
          ],
        ],
      ),
    );
  }
}

class _SportCard extends StatelessWidget {
  final DashboardSport sport;
  final VoidCallback onOpen;

  const _SportCard({required this.sport, required this.onOpen});

  @override
  Widget build(BuildContext context) {
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.fitness_center_outlined, color: MaviohColors.amber, size: 20),
              const SizedBox(width: 8),
              const Expanded(child: Text('Sport', style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text))),
              TextButton(onPressed: onOpen, child: const Text('Ouvrir')),
            ],
          ),
          const SizedBox(height: 8),
          if (sport.sessionsToday.isEmpty)
            Row(
              children: [
                const Expanded(child: Text('Aucune séance aujourd’hui', style: TextStyle(color: MaviohColors.muted, fontWeight: FontWeight.w600))),
                OutlinedButton.icon(onPressed: onOpen, icon: const Icon(Icons.auto_awesome_outlined, size: 18), label: const Text('Générer')),
              ],
            )
          else
            for (final s in sport.sessionsToday)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(
                  children: [
                    Icon(s.status == 'terminee' ? Icons.check_circle_rounded : Icons.schedule_rounded, size: 18, color: s.status == 'terminee' ? MaviohColors.success : MaviohColors.muted),
                    const SizedBox(width: 8),
                    Expanded(child: Text(s.title, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, color: MaviohColors.text))),
                    Text(
                      [fmtMinutes(s.durationMin), if (s.caloriesBurned != null) fmtKcal(s.caloriesBurned)].join(' · '),
                      style: const TextStyle(color: MaviohColors.muted, fontSize: 12.5, fontWeight: FontWeight.w600),
                    ),
                  ],
                ),
              ),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(child: StatTile(label: 'Semaine', value: fmtMinutes(sport.weekMinutes), caption: '${sport.weekSessions} séance${sport.weekSessions > 1 ? 's' : ''}', icon: Icons.calendar_today_outlined, tone: MaviohColors.amber)),
              const SizedBox(width: 10),
              Expanded(child: StatTile(label: 'Série', value: '${sport.streakDays} j', caption: sport.streakDays > 0 ? 'Continue comme ça !' : 'Lance ta série', icon: Icons.local_fire_department_outlined, tone: MaviohColors.orange)),
              if (sport.caloriesBurned > 0) ...[
                const SizedBox(width: 10),
                Expanded(child: StatTile(label: 'Brûlées', value: fmtKcal(sport.caloriesBurned), caption: 'estimation', icon: Icons.bolt_outlined, tone: MaviohColors.rose)),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

class _WeightCard extends StatelessWidget {
  final DashboardWeight weight;
  final VoidCallback onLog;
  final VoidCallback onProfile;

  const _WeightCard({required this.weight, required this.onLog, required this.onProfile});

  @override
  Widget build(BuildContext context) {
    final values = weight.history.map((w) => w.weightKg).toList();
    final variation = weight.variationHebdoKg;
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.monitor_weight_outlined, color: MaviohColors.sky, size: 20),
              const SizedBox(width: 8),
              const Expanded(child: Text('Poids', style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text))),
              FilledButton.tonalIcon(onPressed: onLog, icon: const Icon(Icons.add_rounded, size: 18), label: const Text('Peser')),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerLeft,
                      child: Text(
                        weight.current == null ? '—' : '${fmtDecimal(weight.current)}${nbsp}kg',
                        style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: MaviohColors.text),
                      ),
                    ),
                    Text(
                      weight.target == null ? 'Aucun objectif de poids' : 'objectif ${fmtDecimal(weight.target)}${nbsp}kg',
                      style: const TextStyle(color: MaviohColors.muted, fontSize: 12.5, fontWeight: FontWeight.w600),
                    ),
                    if (variation != null)
                      Text(
                        'tendance ${variation > 0 ? '+' : ''}${fmtDecimal(variation, decimals: 2)}${nbsp}kg / semaine (estimation)',
                        style: const TextStyle(color: MaviohColors.muted, fontSize: 12),
                      ),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              SizedBox(
                width: 130,
                child: values.isEmpty
                    ? TextButton(onPressed: onProfile, child: const Text('Compléter'))
                    : Sparkline(values: values, color: MaviohColors.sky, referenceValue: weight.target),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _WeightSheet extends StatefulWidget {
  final double? initial;

  const _WeightSheet({this.initial});

  @override
  State<_WeightSheet> createState() => _WeightSheetState();
}

class _WeightSheetState extends State<_WeightSheet> {
  late final TextEditingController _controller =
      TextEditingController(text: widget.initial == null ? '' : fmtDecimal(widget.initial, decimals: 1));
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _submit() {
    final value = parseDecimal(_controller.text);
    if (value == null || value < 20 || value > 400) {
      setState(() => _error = 'Indique un poids entre 20 et 400 kg.');
      return;
    }
    Navigator.of(context).pop(value);
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
          const Text('Peser aujourd’hui', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text)),
          const SizedBox(height: 6),
          const Text('Le poids du jour met ton profil à jour ; tes cibles sont recalculées si nécessaire.', style: TextStyle(color: MaviohColors.muted, height: 1.4)),
          const SizedBox(height: 16),
          TextField(
            controller: _controller,
            autofocus: true,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: 'Poids', suffixText: 'kg', errorText: _error),
            onSubmitted: (_) => _submit(),
          ),
          const SizedBox(height: 16),
          FilledButton(onPressed: _submit, child: const Text(AppStrings.save)),
        ],
      ),
    );
  }
}
