import 'package:flutter/material.dart';

import '../core/formatters.dart';
import '../theme/app_theme.dart';

/// Dot markers for one calendar day.
class CalendarMarkers {
  final bool planned;
  final bool done;
  final bool cancelled;

  const CalendarMarkers({this.planned = false, this.done = false, this.cancelled = false});

  bool get isEmpty => !planned && !done && !cancelled;
}

/// French month grid (Monday first) with ‹ › navigation and dot markers.
///
/// Dots: planned = accent lime, done = primary green, cancelled = grey.
class MonthCalendar extends StatelessWidget {
  final DateTime month;
  final DateTime? selected;
  final ValueChanged<DateTime> onSelect;
  final ValueChanged<DateTime> onMonthChanged;
  final Map<DateTime, CalendarMarkers> markers;
  final DateTime? minMonth;
  final DateTime? maxMonth;

  const MonthCalendar({
    super.key,
    required this.month,
    this.selected,
    required this.onSelect,
    required this.onMonthChanged,
    this.markers = const {},
    this.minMonth,
    this.maxMonth,
  });

  static const List<String> _weekdays = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];

  DateTime _key(DateTime d) => DateTime(d.year, d.month, d.day);

  bool get _canPrev => minMonth == null || DateTime(month.year, month.month).isAfter(DateTime(minMonth!.year, minMonth!.month));

  bool get _canNext => maxMonth == null || DateTime(month.year, month.month).isBefore(DateTime(maxMonth!.year, maxMonth!.month));

  @override
  Widget build(BuildContext context) {
    final first = DateTime(month.year, month.month, 1);
    final daysInMonth = DateTime(month.year, month.month + 1, 0).day;
    final leading = first.weekday - 1; // Monday = 0
    final totalCells = ((leading + daysInMonth) / 7).ceil() * 7;
    final todayKey = today();
    final selectedKey = selected == null ? null : _key(selected!);

    return Column(
      children: [
        Row(
          children: [
            IconButton(
              tooltip: 'Mois précédent',
              onPressed: _canPrev ? () => onMonthChanged(DateTime(month.year, month.month - 1, 1)) : null,
              icon: const Icon(Icons.chevron_left_rounded),
            ),
            Expanded(
              child: Text(
                fmtMonthYear(month),
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: MaviohColors.text),
              ),
            ),
            IconButton(
              tooltip: 'Mois suivant',
              onPressed: _canNext ? () => onMonthChanged(DateTime(month.year, month.month + 1, 1)) : null,
              icon: const Icon(Icons.chevron_right_rounded),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Row(
          children: _weekdays
              .map(
                (d) => Expanded(
                  child: Center(
                    child: Text(
                      d,
                      style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: MaviohColors.muted),
                    ),
                  ),
                ),
              )
              .toList(),
        ),
        const SizedBox(height: 4),
        GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: 7, childAspectRatio: 1),
          itemCount: totalCells,
          itemBuilder: (context, index) {
            final dayNumber = index - leading + 1;
            if (dayNumber < 1 || dayNumber > daysInMonth) return const SizedBox.shrink();
            final date = DateTime(month.year, month.month, dayNumber);
            final isToday = date == todayKey;
            final isSelected = selectedKey == date;
            final marks = markers[date] ?? const CalendarMarkers();

            return Semantics(
              button: true,
              label: fmtDayLong(date),
              selected: isSelected,
              child: InkWell(
                borderRadius: BorderRadius.circular(12),
                onTap: () => onSelect(date),
                child: Container(
                  margin: const EdgeInsets.all(2),
                  decoration: BoxDecoration(
                    color: isSelected ? MaviohColors.primary : Colors.transparent,
                    borderRadius: BorderRadius.circular(12),
                    border: isToday && !isSelected ? Border.all(color: MaviohColors.primary, width: 1.5) : null,
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(
                        '$dayNumber',
                        style: TextStyle(
                          fontSize: 13.5,
                          fontWeight: isToday || isSelected ? FontWeight.w800 : FontWeight.w600,
                          color: isSelected ? Colors.white : MaviohColors.text,
                        ),
                      ),
                      const SizedBox(height: 3),
                      SizedBox(
                        height: 6,
                        child: marks.isEmpty
                            ? null
                            : Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  if (marks.done) _Dot(isSelected ? Colors.white : MaviohColors.primary),
                                  if (marks.planned) _Dot(isSelected ? Colors.white70 : MaviohColors.accent),
                                  if (marks.cancelled) _Dot(isSelected ? Colors.white38 : MaviohColors.border),
                                ],
                              ),
                      ),
                    ],
                  ),
                ),
              ),
            );
          },
        ),
      ],
    );
  }
}

class _Dot extends StatelessWidget {
  final Color color;

  const _Dot(this.color);

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 6,
      height: 6,
      margin: const EdgeInsets.symmetric(horizontal: 1),
      decoration: BoxDecoration(color: color, shape: BoxShape.circle),
    );
  }
}
