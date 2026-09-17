import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:mavioh/core/api_client.dart';
import 'package:mavioh/core/session.dart';
import 'package:mavioh/main.dart';
import 'package:mavioh/screens/recipes_screen.dart';
import 'package:mavioh/widgets/empty_state.dart';
import 'package:mavioh/widgets/error_state.dart';
import 'package:mavioh/widgets/loading_state.dart';

import '../support/fake_api_client.dart';

Map<String, dynamic> _publicRecipe() => {
      'id': 1,
      'title': 'Gratin de courgettes',
      'description': 'Un gratin léger, prêt en 25 minutes.',
      'prep_time_minutes': 25,
      'calories': 1200,
      'image_url': null,
      'ingredients': [
        {'name': 'Courgette', 'ean': '3560070976478', 'amount': 300, 'unit': 'g'},
      ],
      'ingredients_count': 1,
      'is_public': true,
      'is_owner': false,
      'created_by_user_id': 2,
      'servings': 4,
      'proteins': 40,
      'carbs': 100,
      'fat': 50,
      'tags': ['vegetarien'],
      'meal_types': ['diner'],
      'per_serving': {'calories': 300, 'proteins': 10, 'carbs': 25, 'fat': 12.5},
      'has_macros': true,
      'is_estimate': false,
      'created_at': '2026-09-01T10:00:00.000000Z',
      'updated_at': '2026-09-01T10:00:00.000000Z',
    };

/// Owned recipe without per-serving calories → the card must print « — ».
Map<String, dynamic> _myRecipe() => {
      'id': 2,
      'title': 'Bowl protéiné maison',
      'description': null,
      'prep_time_minutes': null,
      'calories': null,
      'image_url': null,
      'ingredients': const [],
      'ingredients_count': 0,
      'is_public': false,
      'is_owner': true,
      'created_by_user_id': 1,
      'servings': 2,
      'proteins': null,
      'carbs': null,
      'fat': null,
      'tags': const [],
      'meal_types': const [],
      'per_serving': {'calories': null, 'proteins': null, 'carbs': null, 'fat': null},
      'has_macros': false,
      'is_estimate': true,
      'created_at': '2026-09-10T10:00:00.000000Z',
      'updated_at': '2026-09-10T10:00:00.000000Z',
    };

Map<String, dynamic> _payload(List<Map<String, dynamic>> recipes) => {
      'data': recipes,
      'meta': {'current_page': 1, 'last_page': 1, 'per_page': 100, 'total': recipes.length},
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

  Widget wrap() => const MaviohApp(home: Scaffold(body: RecipesScreen()));

  testWidgets('shows the skeleton while /recipes is in flight', (tester) async {
    ApiClient.instance = FakeApiClient.build(
      {'GET /recipes': (_) => FakeResponse(_payload([_publicRecipe()]))},
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

    final before = FakeApiClient.calls.where((c) => c == 'GET /recipes').length;
    await tester.tap(find.text('Réessayer'));
    await tester.pumpAndSettle();

    expect(FakeApiClient.calls.where((c) => c == 'GET /recipes').length, greaterThan(before));
  });

  testWidgets('shows the empty state when no recipe matches', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /recipes': (_) => FakeResponse(_payload(const [])),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    expect(find.byType(EmptyState), findsOneWidget);
    expect(find.text('Aucune recette'), findsOneWidget);
  });

  testWidgets('renders the featured card and the list from the payload', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /recipes': (_) => FakeResponse(_payload([_publicRecipe(), _myRecipe()])),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    expect(find.text('Toutes les recettes'), findsOneWidget);
    expect(find.text('2 RECETTES'), findsOneWidget);
    expect(find.text('Gratin de courgettes'), findsOneWidget);
    expect(find.text('Bowl protéiné maison'), findsOneWidget);
    expect(find.text('Ajouter à un repas'), findsOneWidget);
  });

  testWidgets('« Mes recettes » filters the list and shows « — » without per_serving', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /recipes': (_) => FakeResponse(_payload([_publicRecipe(), _myRecipe()])),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    await tester.tap(find.text('Mes recettes').first);
    await tester.pumpAndSettle();

    expect(find.text('Gratin de courgettes'), findsNothing);
    expect(find.text('Bowl protéiné maison'), findsOneWidget);
    expect(find.text('1 RECETTE'), findsOneWidget);
    expect(find.textContaining('— / portion'), findsOneWidget);
  });
}
