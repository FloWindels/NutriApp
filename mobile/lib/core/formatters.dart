import 'package:intl/intl.dart';

/// Narrow no-break space used as French thousands separator.
const String nnbsp = ' ';

/// Regular no-break space (before units).
const String nbsp = ' ';

String _groupThousands(String digits) {
  final buffer = StringBuffer();
  final len = digits.length;
  for (var i = 0; i < len; i++) {
    buffer.write(digits[i]);
    final remaining = len - i - 1;
    if (remaining > 0 && remaining % 3 == 0) buffer.write(nnbsp);
  }
  return buffer.toString();
}

/// Formats an integer with French grouping: `1240 → '1 240'`.
String fmtInt(num? value) {
  if (value == null) return '—';
  final rounded = value.round();
  final sign = rounded < 0 ? '−' : '';
  return '$sign${_groupThousands(rounded.abs().toString())}';
}

/// Formats a decimal with a French comma, trimming trailing zeros.
String fmtDecimal(num? value, {int decimals = 1}) {
  if (value == null) return '—';
  if (value.isNaN || value.isInfinite) return '—';
  var text = value.toStringAsFixed(decimals);
  if (text.contains('.')) {
    text = text.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
  }
  final negative = text.startsWith('-');
  if (negative) text = text.substring(1);
  final parts = text.split('.');
  final intPart = _groupThousands(parts[0]);
  final result = parts.length > 1 ? '$intPart,${parts[1]}' : intPart;
  return negative ? '−$result' : result;
}

/// `1240 → '1 240 kcal'`.
String fmtKcal(num? value) {
  if (value == null) return '—';
  return '${fmtInt(value)}${nbsp}kcal';
}

/// `12.34 → '12,3 g'`.
String fmtGrams(num? value, {int decimals = 1}) {
  if (value == null) return '—';
  return '${fmtDecimal(value, decimals: decimals)}${nbsp}g';
}

/// Formats a quantity with its unit: `fmtQty(1.5, 'portion') → '1,5 portion'`.
String fmtQty(num? quantity, String? unit) {
  if (quantity == null) return '—';
  final qty = fmtDecimal(quantity, decimals: 2);
  if (unit == null || unit.isEmpty) return qty;
  return '$qty$nbsp${unitLabelShort(unit)}';
}

/// Short French label for a unit key.
String unitLabelShort(String unit) {
  switch (unit) {
    case 'g':
      return 'g';
    case 'ml':
      return 'ml';
    case 'piece':
    case 'unite':
      return 'pièce';
    case 'portion':
      return 'portion';
    case 'cas':
      return 'c. à s.';
    case 'cac':
      return 'c. à c.';
    case 'verre':
      return 'verre';
    case 'bol':
      return 'bol';
    case 'assiette':
      return 'assiette';
    case 'poignee':
      return 'poignée';
    case 'tranche':
      return 'tranche';
    default:
      return unit;
  }
}

/// Percent with no decimals: `0.42 → '42 %'` when [fraction] else `42 → '42 %'`.
String fmtPct(num? value, {bool fraction = false}) {
  if (value == null) return '—';
  final pct = fraction ? value * 100 : value;
  return '${fmtInt(pct)}$nbsp%';
}

/// `'2026-09-16' → 'mardi 16 sept.'`.
String fmtDay(DateTime date) {
  return DateFormat('EEEE d MMM', 'fr_FR').format(date).replaceAll(RegExp(r'\.$'), '.');
}

/// `'mardi 16 septembre'` (long form).
String fmtDayLong(DateTime date) => DateFormat('EEEE d MMMM', 'fr_FR').format(date);

/// `'16 sept.'` (day + short month).
String fmtDayShort(DateTime date) => DateFormat('d MMM', 'fr_FR').format(date);

/// Short weekday `'mar.'`.
String fmtWeekdayShort(DateTime date) => DateFormat('EEE', 'fr_FR').format(date);

/// `'16/09/2026'`.
String fmtDateFr(DateTime? date) {
  if (date == null) return '—';
  return DateFormat('dd/MM/yyyy', 'fr_FR').format(date);
}

/// `'septembre 2026'` (capitalised).
String fmtMonthYear(DateTime date) {
  final text = DateFormat('MMMM yyyy', 'fr_FR').format(date);
  return capitalize(text);
}

/// `'08:30'`.
String fmtTime(DateTime? date) {
  if (date == null) return '—';
  return DateFormat('HH:mm', 'fr_FR').format(date);
}

/// Formats a `HH:mm[:ss]` string as `'08:30'`.
String fmtTimeString(String? time) {
  if (time == null || time.isEmpty) return '—';
  final parts = time.split(':');
  if (parts.length < 2) return time;
  return '${parts[0].padLeft(2, '0')}:${parts[1].padLeft(2, '0')}';
}

/// Aujourd’hui / Hier / Demain / otherwise [fmtDay].
String fmtRelativeDay(DateTime date, {DateTime? now}) {
  final today = _dateOnly(now ?? DateTime.now());
  final target = _dateOnly(date);
  final diff = target.difference(today).inDays;
  if (diff == 0) return 'Aujourd’hui';
  if (diff == -1) return 'Hier';
  if (diff == 1) return 'Demain';
  return capitalize(fmtDay(date));
}

/// Human expiry wording from [daysLeft]: `Périmé`, `Aujourd’hui`, `Demain`, `J-3`.
String fmtDaysLeft(int? daysLeft) {
  if (daysLeft == null) return 'Sans date';
  if (daysLeft < 0) return 'Périmé';
  if (daysLeft == 0) return 'Aujourd’hui';
  if (daysLeft == 1) return 'Demain';
  return 'J-$daysLeft';
}

/// Minutes to `'1 h 05'` / `'45 min'`.
String fmtMinutes(num? minutes) {
  if (minutes == null) return '—';
  final total = minutes.round();
  if (total < 60) return '$total${nbsp}min';
  final h = total ~/ 60;
  final m = total % 60;
  if (m == 0) return '$h${nbsp}h';
  return '$h${nbsp}h$nbsp${m.toString().padLeft(2, '0')}';
}

/// Seconds to `'mm:ss'` or `'h:mm:ss'`.
String fmtDuration(Duration d) {
  final h = d.inHours;
  final m = d.inMinutes.remainder(60);
  final s = d.inSeconds.remainder(60);
  final mm = m.toString().padLeft(2, '0');
  final ss = s.toString().padLeft(2, '0');
  return h > 0 ? '$h:$mm:$ss' : '$mm:$ss';
}

/// Parses `'1,5'` / `'1.5'` / `' 2 '` to a double (null when invalid).
double? parseDecimal(String? text) {
  if (text == null) return null;
  final cleaned = text.trim().replaceAll(nnbsp, '').replaceAll(nbsp, '').replaceAll(' ', '').replaceAll(',', '.');
  if (cleaned.isEmpty) return null;
  return double.tryParse(cleaned);
}

/// ISO date `Y-m-d` (local).
String isoDate(DateTime date) {
  final y = date.year.toString().padLeft(4, '0');
  final m = date.month.toString().padLeft(2, '0');
  final d = date.day.toString().padLeft(2, '0');
  return '$y-$m-$d';
}

/// Parses `Y-m-d` to a local date-only [DateTime].
DateTime? parseIsoDate(String? text) {
  if (text == null || text.isEmpty) return null;
  final parsed = DateTime.tryParse(text);
  if (parsed == null) return null;
  return DateTime(parsed.year, parsed.month, parsed.day);
}

/// Capitalises the first letter.
String capitalize(String text) {
  if (text.isEmpty) return text;
  return text[0].toUpperCase() + text.substring(1);
}

DateTime _dateOnly(DateTime d) => DateTime(d.year, d.month, d.day);

/// Today as a date-only value.
DateTime today() => _dateOnly(DateTime.now());

/// Monday of the week containing [date].
DateTime weekStart(DateTime date) {
  final d = _dateOnly(date);
  return d.subtract(Duration(days: d.weekday - 1));
}
