import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/strings.dart';
import '../models/history.dart';
import '../models/meal.dart';
import '../services/history_service.dart';
import '../theme/app_theme.dart';
import '../widgets/app_card.dart';
import '../widgets/empty_state.dart';
import '../widgets/error_state.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/section_header.dart';
import '../widgets/sparkline.dart';
import '../widgets/stat_tile.dart';

/// Historique (§16.3) — écran poussé depuis Repas : il porte son `Scaffold`.
///
/// Taper un jour le renvoie via `Navigator.pop(date)` pour que `MealsScreen`
/// bascule sur cette date.
class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key, this.initialDate});

  /// Jour à mettre en avant.
  final DateTime? initialDate;

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  static const List<int> _ranges = [7, 30, 90];

  final HistoryService _service = HistoryService();

  int _range = 7;
  HistoryData? _data;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = _data == null;
        _error = null;
      });
    }
    try {
      final data = await _service.lastDays(_range);
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
        _error = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (_data == null) {
          _error = e.message;
        } else {
          ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(e.message)));
        }
      });
    }
  }

  void _selectRange(int range) {
    if (range == _range) return;
    setState(() {
      _range = range;
      _data = null;
    });
    _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text(AppStrings.sectionHistory)),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 10, 20, 4),
            child: Row(
              children: [
                for (final range in _ranges) ...[
                  ChoiceChip(
                    label: Text('$range j'),
                    selected: _range == range,
                    onSelected: (_) => _selectRange(range),
                  ),
                  const SizedBox(width: 8),
                ],
              ],
            ),
          ),
          Expanded(child: _body()),
        ],
      ),
    );
  }

  Widget _body() {
    if (_loading && _data == null) return const LoadingState(skeleton: true, skeletonCount: 3);
    if (_error != null && _data == null) return ErrorState(message: _error!, onRetry: _load);
    final data = _data!;
    if (data.days.isEmpty) {
      return RefreshIndicator(
        onRefresh: () => _load(silent: true),
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            SizedBox(
              height: MediaQuery.sizeOf(context).height * 0.6,
              child: EmptyState(
                icon: Icons.insights_outlined,
                title: 'Pas encore d’historique',
                message: 'Enregistre tes repas : tes journées apparaîtront ici avec tes calories et tes macros.',
                ctaLabel: 'Enregistrer un repas',
                onCta: () => Navigator.of(context).pop(today()),
              ),
            ),
          ],
        ),
      );
    }

    final summary = data.summary;
    final weights = data.weights.map((w) => w.weightKg).toList();

    return RefreshIndicator(
      onRefresh: () => _load(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
        children: [
          const SectionHeader(
            eyebrow: 'Calories',
            title: 'Tes journées',
            subtitle: 'Barres roses : plus de 110 % de ta cible.',
          ),
          AppCard(
            padding: const EdgeInsets.fromLTRB(14, 16, 14, 12),
            child: CaloriesBarChart(days: data.days),
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: 'Moyenne',
                  value: fmtKcal(summary.avgCalories),
                  caption: 'par jour enregistré',
                  icon: Icons.local_fire_department_outlined,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: StatTile(
                  label: 'Jours suivis',
                  value: '${summary.daysLogged}',
                  caption: 'sur $_range jours',
                  icon: Icons.event_available_outlined,
                  tone: MaviohColors.sky,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: StatTile(
                  label: 'Adhérence',
                  value: fmtPct(summary.adherencePct),
                  caption: 'dans ±10 % de ta cible',
                  icon: Icons.track_changes_outlined,
                  tone: MaviohColors.amber,
                ),
              ),
            ],
          ),
          if (weights.isNotEmpty) ...[
            const SizedBox(height: 20),
            const SectionHeader(eyebrow: 'Poids', title: 'Ton évolution'),
            AppCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${fmtDecimal(weights.last)}${nbsp}kg',
                    style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: MaviohColors.text),
                  ),
                  const SizedBox(height: 10),
                  Sparkline(values: weights),
                  const SizedBox(height: 6),
                  Text(
                    '${data.weights.length} pesée${data.weights.length > 1 ? 's' : ''} sur la période',
                    style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted),
                  ),
                ],
              ),
            ),
          ],
          const SizedBox(height: 20),
          const SectionHeader(
            eyebrow: 'Journal',
            title: 'Jour par jour',
            subtitle: 'Touche une journée pour l’ouvrir dans Repas.',
          ),
          for (final day in data.days.reversed) ...[
            _DayTile(
              day: day,
              highlighted: widget.initialDate != null && isoDate(day.date) == isoDate(widget.initialDate!),
              onTap: () => Navigator.of(context).pop(day.date),
            ),
            const SizedBox(height: 10),
          ],
        ],
      ),
    );
  }
}

/// Calories par jour face à la ligne de cible (§16.3).
class CaloriesBarChart extends StatelessWidget {
  const CaloriesBarChart({super.key, required this.days, this.height = 170});

  final List<MealHistoryRow> days;
  final double height;

  @override
  Widget build(BuildContext context) {
    final targets = days.map((d) => d.targetCalories).whereType<double>().toList();
    final target = targets.isEmpty ? null : targets.reduce((a, b) => a + b) / targets.length;
    return Semantics(
      label: 'Graphique des calories des ${days.length} derniers jours',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            height: height,
            width: double.infinity,
            child: CustomPaint(painter: _CaloriesBarPainter(days: days, target: target)),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              if (target != null) ...[
                _Legend(color: MaviohColors.muted, label: 'Cible ${fmtKcal(target)}', dashed: true),
                const SizedBox(width: 14),
              ],
              const _Legend(color: MaviohColors.primary, label: 'Dans la cible'),
              const SizedBox(width: 14),
              const _Legend(color: MaviohColors.rose, label: '> 110 %'),
            ],
          ),
        ],
      ),
    );
  }
}

class _Legend extends StatelessWidget {
  const _Legend({required this.color, required this.label, this.dashed = false});

  final Color color;
  final String label;
  final bool dashed;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: dashed ? 14 : 8,
          height: dashed ? 2 : 8,
          decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(dashed ? 1 : 4)),
        ),
        const SizedBox(width: 5),
        Flexible(
          child: Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 11, color: MaviohColors.muted, fontWeight: FontWeight.w600),
          ),
        ),
      ],
    );
  }
}

class _CaloriesBarPainter extends CustomPainter {
  _CaloriesBarPainter({required this.days, required this.target});

  final List<MealHistoryRow> days;
  final double? target;

  @override
  void paint(Canvas canvas, Size size) {
    if (days.isEmpty) return;

    var max = 0.0;
    for (final day in days) {
      if (day.calories > max) max = day.calories;
    }
    if (target != null && target! > max) max = target!;
    if (max <= 0) max = 1;
    max *= 1.12;

    const bottom = 4.0;
    final chartHeight = size.height - bottom;
    final slot = size.width / days.length;
    final barWidth = (slot * 0.62).clamp(2.0, 22.0);
    final radius = barWidth >= 6 ? Radius.circular(barWidth / 3) : Radius.zero;

    // Baseline.
    canvas.drawLine(
      Offset(0, chartHeight),
      Offset(size.width, chartHeight),
      Paint()
        ..color = MaviohColors.border
        ..strokeWidth = 1,
    );

    for (var i = 0; i < days.length; i++) {
      final day = days[i];
      if (day.calories <= 0) continue;
      final ratio = (day.calories / max).clamp(0.0, 1.0);
      final barHeight = chartHeight * ratio;
      final dayTarget = day.targetCalories ?? target;
      final over = dayTarget != null && dayTarget > 0 && day.calories > dayTarget * 1.1;
      final left = slot * i + (slot - barWidth) / 2;
      final rect = RRect.fromRectAndCorners(
        Rect.fromLTWH(left, chartHeight - barHeight, barWidth, barHeight),
        topLeft: radius,
        topRight: radius,
      );
      canvas.drawRRect(rect, Paint()..color = over ? MaviohColors.rose : MaviohColors.primary);
    }

    // Ligne de cible (pointillés).
    if (target != null && target! > 0) {
      final y = chartHeight - chartHeight * (target! / max).clamp(0.0, 1.0);
      final paint = Paint()
        ..color = MaviohColors.muted
        ..strokeWidth = 1.4;
      var x = 0.0;
      while (x < size.width) {
        canvas.drawLine(Offset(x, y), Offset((x + 6).clamp(0.0, size.width), y), paint);
        x += 11;
      }
    }
  }

  @override
  bool shouldRepaint(covariant _CaloriesBarPainter old) => old.days != days || old.target != target;
}

class _DayTile extends StatelessWidget {
  const _DayTile({required this.day, required this.highlighted, required this.onTap});

  final MealHistoryRow day;
  final bool highlighted;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final target = day.targetCalories;
    final over = target != null && target > 0 && day.calories > target * 1.1;
    return AppCard(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      borderColor: highlighted ? MaviohColors.primary : null,
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  capitalize(fmtRelativeDay(day.date)),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                ),
              ),
              Text(
                fmtKcal(day.calories),
                style: TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w800,
                  color: over ? MaviohColors.error : MaviohColors.text,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              MacroPill.proteins(value: day.proteins, target: day.targetProteins, compact: true),
              MacroPill.carbs(value: day.carbs, target: day.targetCarbs, compact: true),
              MacroPill.fat(value: day.fat, target: day.targetFat, compact: true),
              if (day.sportMinutes > 0)
                TonePill(
                  label: fmtMinutes(day.sportMinutes),
                  tone: MaviohColors.amber,
                  icon: Icons.fitness_center_rounded,
                ),
            ],
          ),
        ],
      ),
    );
  }
}
