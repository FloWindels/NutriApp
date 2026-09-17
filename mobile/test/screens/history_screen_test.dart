import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:mavioh/core/api_client.dart';
import 'package:mavioh/core/formatters.dart';
import 'package:mavioh/core/session.dart';
import 'package:mavioh/main.dart';
import 'package:mavioh/screens/history_screen.dart';
import 'package:mavioh/widgets/empty_state.dart';
import 'package:mavioh/widgets/error_state.dart';
import 'package:mavioh/widgets/loading_state.dart';
import 'package:mavioh/widgets/sparkline.dart';

import '../support/fake_api_client.dart';

Map<String, dynamic> _day(DateTime date, {double calories = 2100, int sportMinutes = 0}) => {
      'date': isoDate(date),
      'calories': calories,
      'proteins': 120,
      'carbs': 250,
      'fat': 60,
      'target_calories': 2000,
      'target_proteins': 130,
      'target_carbs': 240,
      'target_fat': 65,
      'meals_count': 3,
      'sport_minutes': sportMinutes,
      'calories_burned': sportMinutes * 7,
    };

Map<String, dynamic> _history({bool empty = false}) {
  final now = today();
  return {
    'data': {
      'days': empty
          ? const []
          : [
              _day(now.subtract(const Duration(days: 1)), calories: 2450, sportMinutes: 45),
              _day(now, calories: 1950),
            ],
      'weights': empty
          ? const []
          : [
              {'date': isoDate(now.subtract(const Duration(days: 1))), 'weight_kg': 81.6},
              {'date': isoDate(now), 'weight_kg': 81.2},
            ],
      'summary': {
        'avg_calories': empty ? null : 2200,
        'days_logged': empty ? 0 : 2,
        'adherence_pct': empty ? null : 50,
      },
    },
  };
}

void main() {
  setUpAll(() async {
    await initializeDateFormatting('fr_FR');
  });

  setUp(() {
    ApiClient.resetInstance();
    Session.instance.clearCache();
    FakeApiClient.calls.clear();
  });

  tearDown(ApiClient.resetInstance);

  Widget wrap() => const MaviohApp(home: HistoryScreen());

  testWidgets('shows the skeleton while /history is in flight', (tester) async {
    ApiClient.instance = FakeApiClient.build(
      {'GET /history': (_) => FakeResponse(_history())},
      delay: const Duration(milliseconds: 80),
    );

    await tester.pumpWidget(wrap());
    await tester.pump();

    expect(find.widgetWithText(AppBar, 'Historique'), findsOneWidget);
    expect(find.byType(LoadingState), findsOneWidget);

    await tester.pumpAndSettle();
    expect(find.byType(LoadingState), findsNothing);
  });

  testWidgets('shows an error state with « Réessayer » and retries', (tester) async {
    ApiClient.instance = FakeApiClient.offline();

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    expect(find.byType(ErrorState), findsOneWidget);

    final before = FakeApiClient.calls.where((c) => c == 'GET /history').length;
    await tester.tap(find.text('Réessayer'));
    await tester.pumpAndSettle();

    expect(FakeApiClient.calls.where((c) => c == 'GET /history').length, greaterThan(before));
  });

  testWidgets('shows the empty state with its CTA when no day was logged', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /history': (_) => FakeResponse(_history(empty: true)),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    expect(find.byType(EmptyState), findsOneWidget);
    expect(find.text('Pas encore d’historique'), findsOneWidget);
    expect(find.text('Enregistrer un repas'), findsOneWidget);
  });

  testWidgets('renders the chart, the summary tiles, the weight sparkline and the days', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /history': (_) => FakeResponse(_history()),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    // SectionHeader uppercases its eyebrow.
    expect(find.text('CALORIES'), findsOneWidget);
    expect(find.byType(CaloriesBarChart), findsOneWidget);
    expect(find.text('Moyenne'), findsOneWidget);
    expect(find.text('Jours suivis'), findsOneWidget);
    expect(find.text('Adhérence'), findsOneWidget);
    expect(find.text('sur 7 jours'), findsOneWidget);

    final scrollable = find.byType(Scrollable).first;
    await tester.scrollUntilVisible(find.byType(Sparkline), 250, scrollable: scrollable);
    expect(find.text('POIDS'), findsOneWidget);
    expect(find.byType(Sparkline), findsOneWidget);

    await tester.scrollUntilVisible(find.text('Hier'), 250, scrollable: scrollable);
    expect(find.text('Aujourd’hui'), findsOneWidget);
    expect(find.text('Hier'), findsOneWidget);
  });

  testWidgets('the « 30 j » chip refetches the history over 30 days', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /history': (_) => FakeResponse(_history()),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    expect(find.text('sur 7 jours'), findsOneWidget);

    final before = FakeApiClient.calls.where((c) => c == 'GET /history').length;
    await tester.tap(find.text('30 j'));
    await tester.pumpAndSettle();

    expect(FakeApiClient.calls.where((c) => c == 'GET /history').length, greaterThan(before));
    expect(find.text('sur 30 jours'), findsOneWidget);
  });
}
