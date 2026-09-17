import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:mavioh/core/formatters.dart';

void main() {
  setUpAll(() async {
    await initializeDateFormatting('fr_FR');
  });

  group('numbers', () {
    test('fmtKcal groups thousands with a narrow no-break space', () {
      expect(fmtKcal(1240), '1 240 kcal');
      expect(fmtKcal(980), '980 kcal');
      expect(fmtKcal(1234567), '1 234 567 kcal');
      expect(fmtKcal(null), '—');
    });

    test('fmtGrams uses a decimal comma and trims trailing zeros', () {
      expect(fmtGrams(12.34), '12,3 g');
      expect(fmtGrams(12.0), '12 g');
      expect(fmtGrams(0.5), '0,5 g');
      expect(fmtGrams(1250.55, decimals: 2), '1 250,55 g');
    });

    test('fmtQty and unit labels', () {
      expect(fmtQty(1.5, 'portion'), '1,5 portion');
      expect(fmtQty(100, 'g'), '100 g');
      expect(fmtQty(2, 'cas'), '2 c. à s.');
      expect(fmtQty(1, 'piece'), '1 pièce');
      expect(fmtQty(null, 'g'), '—');
    });

    test('fmtInt rounds and handles negatives', () {
      expect(fmtInt(1999.6), '2 000');
      expect(fmtInt(-120), '−120');
    });

    test('fmtPct', () {
      expect(fmtPct(42), '42 %');
      expect(fmtPct(0.418, fraction: true), '42 %');
    });

    test('fmtMinutes', () {
      expect(fmtMinutes(45), '45 min');
      expect(fmtMinutes(60), '1 h');
      expect(fmtMinutes(65), '1 h 05');
    });

    test('fmtDuration', () {
      expect(fmtDuration(const Duration(minutes: 3, seconds: 7)), '03:07');
      expect(fmtDuration(const Duration(hours: 1, minutes: 2, seconds: 3)), '1:02:03');
    });
  });

  group('dates', () {
    final tuesday = DateTime(2026, 9, 15);
    final wednesday = DateTime(2026, 9, 16);

    test('fmtDay → mardi 15 sept.', () {
      expect(fmtDay(tuesday), 'mardi 15 sept.');
      expect(fmtDay(wednesday), 'mercredi 16 sept.');
    });

    test('fmtDateFr → 16/09/2026', () {
      expect(fmtDateFr(wednesday), '16/09/2026');
      expect(fmtDateFr(null), '—');
    });

    test('fmtRelativeDay', () {
      final now = DateTime(2026, 9, 16, 14, 30);
      expect(fmtRelativeDay(DateTime(2026, 9, 16), now: now), 'Aujourd’hui');
      expect(fmtRelativeDay(DateTime(2026, 9, 15), now: now), 'Hier');
      expect(fmtRelativeDay(DateTime(2026, 9, 17), now: now), 'Demain');
      expect(fmtRelativeDay(DateTime(2026, 9, 14), now: now), 'Lundi 14 sept.');
    });

    test('fmtTime / fmtTimeString', () {
      expect(fmtTime(DateTime(2026, 9, 16, 8, 5)), '08:05');
      expect(fmtTimeString('7:30:00'), '07:30');
      expect(fmtTimeString(null), '—');
    });

    test('fmtMonthYear is capitalised', () {
      expect(fmtMonthYear(wednesday), 'Septembre 2026');
    });

    test('isoDate / parseIsoDate / weekStart', () {
      expect(isoDate(wednesday), '2026-09-16');
      expect(parseIsoDate('2026-09-16'), wednesday);
      expect(parseIsoDate('n/a'), isNull);
      expect(weekStart(wednesday), DateTime(2026, 9, 14));
      expect(weekStart(DateTime(2026, 9, 14)), DateTime(2026, 9, 14));
    });

    test('fmtDaysLeft', () {
      expect(fmtDaysLeft(-1), 'Périmé');
      expect(fmtDaysLeft(0), 'Aujourd’hui');
      expect(fmtDaysLeft(1), 'Demain');
      expect(fmtDaysLeft(4), 'J-4');
      expect(fmtDaysLeft(null), 'Sans date');
    });
  });

  group('parseDecimal', () {
    test('accepts comma and dot', () {
      expect(parseDecimal('1,5'), 1.5);
      expect(parseDecimal('1.5'), 1.5);
      expect(parseDecimal(' 2 '), 2);
      expect(parseDecimal('1 250,5'), 1250.5);
    });

    test('rejects garbage', () {
      expect(parseDecimal(''), isNull);
      expect(parseDecimal('abc'), isNull);
      expect(parseDecimal(null), isNull);
    });
  });
}
