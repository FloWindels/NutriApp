import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:mavioh/core/api_client.dart';
import 'package:mavioh/core/session.dart';
import 'package:mavioh/main.dart';
import 'package:mavioh/models/me.dart';
import 'package:mavioh/screens/family_screen.dart';
import 'package:mavioh/widgets/error_state.dart';
import 'package:mavioh/widgets/loading_state.dart';

import '../support/fake_api_client.dart';

Map<String, dynamic> _household() => {
      'data': {
        'id': 1,
        'name': 'Foyer Windels',
        'invite_code': 'K7M2PQ49',
        'role': 'proprietaire',
        'stock_items_count': 24,
        'members': [
          {
            'user_id': 1,
            'name': 'Demo Mavioh',
            'role': 'proprietaire',
            'share_profile': true,
            'calories_cibles': 2130,
            'regime': 'mediterraneen',
            'joined_at': '2026-09-01T10:00:00.000000Z',
          },
          {
            'user_id': 2,
            'name': 'Camille',
            'role': 'membre',
            'share_profile': false,
            'calories_cibles': null,
            'regime': null,
            'joined_at': '2026-09-02T10:00:00.000000Z',
          },
        ],
      },
    };

void main() {
  setUpAll(() async {
    await initializeDateFormatting('fr_FR');
  });

  setUp(() {
    ApiClient.resetInstance();
    Session.instance.clearCache();
    Session.instance.hydrate(Me.fromJson(FakePayloads.me()));
    FakeApiClient.calls.clear();
  });

  tearDown(ApiClient.resetInstance);

  Widget wrap() => const MaviohApp(home: Scaffold(body: FamilyScreen()));

  testWidgets('shows the skeleton while /household is in flight', (tester) async {
    ApiClient.instance = FakeApiClient.build(
      {'GET /household': (_) => FakeResponse(_household())},
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

    final before = FakeApiClient.calls.where((c) => c == 'GET /household').length;
    await tester.tap(find.text('Réessayer'));
    await tester.pumpAndSettle();

    expect(FakeApiClient.calls.where((c) => c == 'GET /household').length, greaterThan(before));
  });

  testWidgets('without a household shows the create and join cards', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /household': (_) => const FakeResponse({'data': null}),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    // SectionHeader uppercases its eyebrow.
    expect(find.text('FOYER'), findsOneWidget);
    expect(find.text('Créer un foyer'), findsWidgets);
    expect(find.text('Rejoindre un foyer'), findsWidgets);
    // Nom prérempli « Foyer de {prénom} ».
    expect(find.widgetWithText(TextField, 'Foyer de Demo'), findsOneWidget);
    // Le bouton « Rejoindre » reste désactivé tant que le code n’a pas 8 caractères.
    final joinButton = tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Rejoindre un foyer'));
    expect(joinButton.onPressed, isNull);
  });

  testWidgets('with a household renders the invite code and the members', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /household': (_) => FakeResponse(_household()),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    expect(find.text('Foyer Windels'), findsOneWidget);
    expect(find.text('K7M2PQ49'), findsOneWidget);
    expect(find.text('Copier'), findsOneWidget);
    expect(find.text('Nouveau code'), findsOneWidget);
    expect(find.text('MEMBRES'), findsOneWidget);
    expect(find.text('Demo Mavioh'), findsOneWidget);
    expect(find.text('Camille'), findsOneWidget);
    expect(find.text('Profil non partagé'), findsOneWidget);
    expect(find.text('Propriétaire'), findsOneWidget);
  });

  testWidgets('an 8-character code previews the household then joins it', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /household': (_) => const FakeResponse({'data': null}),
      'GET /household/preview': (_) => const FakeResponse({
            'data': {'name': 'Foyer Windels', 'members_count': 2},
          }),
      'POST /household/join': (_) => FakeResponse(
            {...(_household()), 'message': 'Bienvenue dans le foyer.', 'merged_stock_items': 3},
            status: 201,
          ),
      'GET /me': (_) => FakeResponse(FakePayloads.me()),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    await tester.enterText(find.widgetWithText(TextField, 'Code d’invitation'), 'k7m2pq49');
    await tester.pumpAndSettle();

    final joinFinder = find.widgetWithText(FilledButton, 'Rejoindre un foyer');
    expect(tester.widget<FilledButton>(joinFinder).onPressed, isNotNull);

    // Le bouton affiche un indicateur de progression : on ne peut pas « settle ».
    await tester.tap(joinFinder);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));

    expect(FakeApiClient.calls, contains('GET /household/preview'));
    expect(find.text('Rejoindre « Foyer Windels » ?'), findsOneWidget);
    expect(find.textContaining('fusionné'), findsOneWidget);

    await tester.tap(find.widgetWithText(FilledButton, 'Rejoindre'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pump(const Duration(milliseconds: 400));

    expect(FakeApiClient.calls, contains('POST /household/join'));
    expect(find.text('Foyer Windels'), findsWidgets);
  });
}
