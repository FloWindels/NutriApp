import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:mavioh/core/api_client.dart';
import 'package:mavioh/core/formatters.dart';
import 'package:mavioh/core/session.dart';
import 'package:mavioh/main.dart';
import 'package:mavioh/screens/sport_screen.dart';
import 'package:mavioh/widgets/empty_state.dart';
import 'package:mavioh/widgets/error_state.dart';
import 'package:mavioh/widgets/loading_state.dart';
import 'package:mavioh/widgets/month_calendar.dart';

import '../support/fake_api_client.dart';

Map<String, dynamic> _config() => {
      'data': {
        'ia_disponible': true,
        'llm_model': 'claude-opus-5',
        'coef_calories': 100,
        'vocab': <String, dynamic>{},
      },
    };

Map<String, dynamic> _session({
  int id = 3,
  String status = 'terminee',
  String title = 'Séance haut du corps',
}) =>
    {
      'id': id,
      'date': isoDate(today()),
      'title': title,
      'kind': 'seance',
      'status': status,
      'duration_min': 45,
      'calories_burned': 320,
      'calories_source': 'auto',
      'sport_name': 'Musculation',
      'exercises': const [],
    };

Map<String, dynamic> _summary({bool empty = false}) => {
      'data': {
        'today': {
          'sessions': empty ? const [] : [_session()],
          'calories_burned': empty ? 0 : 320,
          'minutes': empty ? 0 : 45,
        },
        'week': {'sessions': empty ? 0 : 3, 'minutes': empty ? 0 : 150, 'calories': empty ? 0 : 900},
        'streak_days': empty ? 0 : 4,
        'nutrition': {
          'calories_burned': empty ? 0 : 320,
          'calories_bonus': empty ? 0 : 320,
          'coefficient': 1,
          'explication': '320 kcal brûlées aujourd’hui : 100 % sont ajoutées à ton budget.',
          'is_estimate': true,
        },
        'recos': const {'pre': null, 'post': null},
        'active_session_id': null,
        'coef_calories': 100,
        'next_plan': empty
            ? null
            : {
                'id': 9,
                'date': isoDate(today()),
                'sport_name': 'Course à pied',
                'planned_duration_min': 30,
                'planned_at': '18:00',
              },
        'week_plans': const [],
      },
    };

Map<String, dynamic> _calendar() => {
      'data': {
        'days': [
          {
            'date': isoDate(today()),
            'plans': [
              {
                'id': 9,
                'date': isoDate(today()),
                'sport_id': 4,
                'sport_name': 'Course à pied',
                'planned_duration_min': 30,
                'planned_at': '18:00',
                'lieu': 'exterieur',
                'notes': null,
                'status': 'prevu',
              },
            ],
            'sessions': const [],
          },
        ],
        'summary': {'planned_count': 1, 'done_count': 0, 'minutes_done': 0, 'calories_done': 0},
      },
    };

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

  Widget wrap() => const MaviohApp(home: Scaffold(body: SportScreen()));

  testWidgets('shows the skeleton while /sport/summary is in flight', (tester) async {
    ApiClient.instance = FakeApiClient.build(
      {
        'GET /sport/config': (_) => FakeResponse(_config()),
        'GET /sport/summary': (_) => FakeResponse(_summary()),
      },
      delay: const Duration(milliseconds: 80),
    );

    await tester.pumpWidget(wrap());
    await tester.pump();

    expect(find.byType(LoadingState), findsOneWidget);

    await tester.pumpAndSettle();
    expect(find.byType(LoadingState), findsNothing);
  });

  testWidgets('shows an error state with « Réessayer » and retries', (tester) async {
    ApiClient.instance = FakeApiClient.offline();

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    expect(find.byType(ErrorState), findsOneWidget);
    expect(find.text('Pas de connexion. Vérifie ton réseau puis réessaie.'), findsOneWidget);

    final before = FakeApiClient.calls.where((c) => c == 'GET /sport/summary').length;
    await tester.tap(find.text('Réessayer'));
    await tester.pumpAndSettle();

    expect(FakeApiClient.calls.where((c) => c == 'GET /sport/summary').length, greaterThan(before));
  });

  testWidgets('shows the empty state with its CTA when nothing was done today', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /sport/config': (_) => FakeResponse(_config()),
      'GET /sport/summary': (_) => FakeResponse(_summary(empty: true)),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    expect(find.byType(EmptyState), findsOneWidget);
    expect(find.text('Aucune séance aujourd’hui'), findsOneWidget);
    expect(find.text('Générer une séance'), findsWidgets);
  });

  testWidgets('renders the stat tiles, the bonus caption and the next plan', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /sport/config': (_) => FakeResponse(_config()),
      'GET /sport/summary': (_) => FakeResponse(_summary()),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    // SectionHeader uppercases its eyebrow.
    expect(find.text('AUJOURD’HUI'), findsOneWidget);
    expect(find.text('Tes séances du jour'), findsOneWidget);
    expect(find.text('Semaine'), findsOneWidget);
    expect(find.text('Série'), findsOneWidget);
    expect(find.textContaining('ajoutés à ton budget'), findsOneWidget);
    expect(find.text('Prochaine séance'), findsOneWidget);
    expect(find.text('J’ai fait cette séance'), findsOneWidget);
    expect(find.text('Séance haut du corps'), findsOneWidget);
    expect(find.text('Activité rapide'), findsOneWidget);
  });

  testWidgets('switching to « Calendrier » loads the month and lists the day plans', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /sport/config': (_) => FakeResponse(_config()),
      'GET /sport/summary': (_) => FakeResponse(_summary()),
      'GET /sport/calendar': (_) => FakeResponse(_calendar()),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    await tester.tap(find.text('Calendrier'));
    await tester.pumpAndSettle();

    expect(FakeApiClient.calls, contains('GET /sport/calendar'));
    expect(find.byType(MonthCalendar), findsOneWidget);
    expect(find.text('CALENDRIER'), findsOneWidget);
    expect(find.text('Planifier ma semaine'), findsOneWidget);
    expect(find.widgetWithText(FloatingActionButton, 'Planifier'), findsOneWidget);

    await tester.scrollUntilVisible(
      find.text('Course à pied'),
      250,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Course à pied'), findsOneWidget);
    expect(find.text('Réaliser'), findsOneWidget);
  });

  testWidgets('« Séances » filters the list over the ±30 days window', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /sport/config': (_) => FakeResponse(_config()),
      'GET /sport/summary': (_) => FakeResponse(_summary()),
      'GET /sport/sessions': (_) => FakeResponse({
            'data': [
              _session(),
              _session(id: 4, status: 'prevue', title: 'Sortie vélo'),
            ],
          }),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    await tester.tap(find.text('Séances').first);
    await tester.pumpAndSettle();

    expect(FakeApiClient.calls, contains('GET /sport/sessions'));
    expect(find.text('Séance haut du corps'), findsOneWidget);
    expect(find.text('Sortie vélo'), findsOneWidget);

    await tester.tap(find.text('Terminées'));
    await tester.pumpAndSettle();

    expect(find.text('Séance haut du corps'), findsOneWidget);
    expect(find.text('Sortie vélo'), findsNothing);
  });
}
