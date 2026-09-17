import 'package:flutter/material.dart';

import '../core/formatters.dart';
import '../theme/app_theme.dart';

/// ‹ Aujourd’hui › strip; tap on the label opens a French date picker.
class DateStrip extends StatelessWidget {
  final DateTime date;
  final ValueChanged<DateTime> onChanged;
  final DateTime? firstDate;
  final DateTime? lastDate;
  final bool allowFuture;

  const DateStrip({
    super.key,
    required this.date,
    required this.onChanged,
    this.firstDate,
    this.lastDate,
    this.allowFuture = true,
  });

  DateTime get _dateOnly => DateTime(date.year, date.month, date.day);

  bool get _canGoNext {
    if (allowFuture) return lastDate == null || _dateOnly.isBefore(lastDate!);
    return _dateOnly.isBefore(today());
  }

  Future<void> _pick(BuildContext context) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _dateOnly,
      firstDate: firstDate ?? DateTime(2020, 1, 1),
      lastDate: lastDate ?? (allowFuture ? DateTime.now().add(const Duration(days: 365)) : today()),
      locale: const Locale('fr', 'FR'),
      helpText: 'Choisis une date',
      cancelText: 'Annuler',
      confirmText: 'OK',
    );
    if (picked != null) onChanged(DateTime(picked.year, picked.month, picked.day));
  }

  @override
  Widget build(BuildContext context) {
    final isToday = _dateOnly == today();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: MaviohColors.border),
      ),
      child: Row(
        children: [
          IconButton(
            tooltip: 'Jour précédent',
            onPressed: () => onChanged(_dateOnly.subtract(const Duration(days: 1))),
            icon: const Icon(Icons.chevron_left_rounded),
          ),
          Expanded(
            child: InkWell(
              borderRadius: BorderRadius.circular(12),
              onTap: () => _pick(context),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 10),
                child: Column(
                  children: [
                    Text(
                      fmtRelativeDay(_dateOnly),
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                    ),
                    if (!isToday)
                      Text(
                        fmtDateFr(_dateOnly),
                        style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted, fontWeight: FontWeight.w600),
                      ),
                  ],
                ),
              ),
            ),
          ),
          if (!isToday)
            IconButton(
              tooltip: 'Revenir à aujourd’hui',
              onPressed: () => onChanged(today()),
              icon: const Icon(Icons.today_rounded, size: 20),
            ),
          IconButton(
            tooltip: 'Jour suivant',
            onPressed: _canGoNext ? () => onChanged(_dateOnly.add(const Duration(days: 1))) : null,
            icon: const Icon(Icons.chevron_right_rounded),
          ),
        ],
      ),
    );
  }
}
