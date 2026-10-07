import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:mavioh/core/api_client.dart';
import 'package:mavioh/core/session.dart';
import 'package:mavioh/main.dart';
import 'package:mavioh/screens/food_search/food_form_sheet.dart';
import 'package:mavioh/widgets/add_to_meal_sheet.dart';
import 'package:mavioh/widgets/recherche_en_ligne.dart';

import '../support/fake_api_client.dart';

const _biscuit = {
  'id': 77,
  'name': 'Biscuit sablé breton',
  'brand': 'La Mère Poulard',
  'calories': 510,
  'proteins': 6,
  'carbs': 62,
  'fat': 26,
  'per_unit': '100g',
  'source_type': 'open_food_facts',
};

Map<String, dynamic> _reponse({
  required String source,
  Map<String, dynamic>? food,
  Map<String, dynamic>? proposition,
  List<Map<String, dynamic>> sources = const [],
  List<String> avertissements = const [],
  String? message,
}) =>
    {
      'data': {
        'source': source,
        'food': food,
        'proposition': proposition,
        'sources': sources,
        'avertissements': avertissements,
        'message': message,
      },
    };

Map<String, dynamic> _corps(RequestOptions request) {
  final data = request.data;
  return data is String ? jsonDecode(data) as Map<String, dynamic> : Map<String, dynamic>.from(data as Map);
}

Map<String, FakeHandler> _routesDeBase() => {
      'GET /portions': (_) => FakeResponse(FakePayloads.portions()),
      'GET /meals/frequent': (_) => const FakeResponse({'data': []}),
      'GET /foods/search': (_) => const FakeResponse({
            'data': [],
            'meta': {'current_page': 1, 'last_page': 1, 'per_page': 20, 'total': 0, 'off_queried': true},
          }),
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

  Future<void> monter(WidgetTester tester, Future<void> Function(BuildContext) ouvrir) async {
    // Écran haut : le formulaire pré-rempli tient entier sous la carte des sources.
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(800, 2000);
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaviohApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => TextButton(onPressed: () => ouvrir(context), child: const Text('ouvrir')),
        ),
      ),
    ));
    await tester.tap(find.text('ouvrir'));
    await tester.pumpAndSettle();
  }

  /// Tape un nom introuvable dans la feuille « Ajouter à un repas » puis lance l'agent.
  Future<void> chercherSurInternet(WidgetTester tester, String nom) async {
    await tester.enterText(find.byType(TextField).first, nom);
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(find.text('Aucun aliment trouvé'), findsOneWidget);

    await tester.tap(find.text(RechercheEnLigne.libelle));
    await tester.pumpAndSettle();
  }

  testWidgets('produit trouvé sur Open Food Facts : il est choisi directement', (tester) async {
    Map<String, dynamic>? envoye;
    ApiClient.instance = FakeApiClient.build({
      ..._routesDeBase(),
      'POST /foods/recherche-en-ligne': (request) {
        envoye = _corps(request);
        return FakeResponse(_reponse(source: 'open_food_facts', food: _biscuit));
      },
    });

    await monter(tester, (context) => AddToMealSheet.show(context));
    await chercherSurInternet(tester, 'sablé breton');

    expect(envoye, containsPair('nom', 'sablé breton'));
    expect(find.text('Quantité'), findsOneWidget);
    expect(find.text('Biscuit sablé breton'), findsOneWidget);
    expect(find.text('Trouvé dans Open Food Facts : Biscuit sablé breton'), findsOneWidget);
    expect(FakeApiClient.calls, isNot(contains('POST /foods')));
  });

  testWidgets('valeurs trouvées sur Internet : le formulaire est pré-rempli, avec ses sources', (tester) async {
    Map<String, dynamic>? cree;
    ApiClient.instance = FakeApiClient.build({
      ..._routesDeBase(),
      'POST /foods/recherche-en-ligne': (_) => FakeResponse(_reponse(
            source: 'internet',
            proposition: {
              'nom': 'Kouign-amann',
              'marque': null,
              'per_unit': '100g',
              'calories': 450.5,
              'proteins': 5,
              'carbs': 48,
              'fat': 27,
              'fiber': 1.2,
              'sugar': 22,
              'salt': null,
              'serving_size_g': 80,
              'nature': 'plat',
              'confiance': 0.62,
              'estimation': true,
            },
            sources: [
              {'titre': 'Recette du kouign-amann', 'url': 'https://exemple.fr/kouign-amann'},
            ],
            avertissements: ['Les quantités de beurre varient selon les recettes.'],
          )),
      'POST /foods': (request) {
        cree = _corps(request);
        return FakeResponse({
          'message': 'Aliment créé.',
          'data': {'id': 90, 'name': 'Kouign-amann', 'calories': 450.5, 'proteins': 5, 'carbs': 48, 'fat': 27},
        }, status: 201);
      },
    });

    await monter(tester, (context) => AddToMealSheet.show(context));
    await chercherSurInternet(tester, 'kouign amann');

    expect(find.text('Créer l’aliment'), findsOneWidget);
    expect(find.text(RechercheEnLigne.aVerifier), findsOneWidget);
    expect(find.textContaining('Confiance : 62'), findsOneWidget);
    expect(find.textContaining('recette type'), findsOneWidget);
    expect(find.text('Les quantités de beurre varient selon les recettes.'), findsOneWidget);
    expect(find.text('Recette du kouign-amann'), findsOneWidget);
    expect(find.text('exemple.fr'), findsOneWidget);

    String champ(String libelle) => tester.widget<TextField>(find.widgetWithText(TextField, libelle)).controller!.text;
    expect(champ('Nom *'), 'Kouign-amann');
    expect(champ('Calories pour 100 g *'), '450,5');
    expect(champ('Protéines'), '5');
    expect(champ('Lipides'), '27');
    expect(champ('Portion (facultatif)'), '80');
    // Rien n'est enregistré tant que la personne n'a pas validé.
    expect(FakeApiClient.calls, isNot(contains('POST /foods')));

    await tester.tap(find.text('Créer et ajouter'));
    await tester.pumpAndSettle();

    expect(cree, isNotNull);
    expect(cree!['name'], 'Kouign-amann');
    expect(cree!['calories'], 450.5);
    expect(cree!['fiber'], 1.2);
    expect(cree!['serving_size_g'], 80);
    expect(cree!.containsKey('salt'), isFalse);
    expect(find.text('Quantité'), findsOneWidget);
  });

  testWidgets('offre sans IA (402) : l’indication d’offre remplace le bouton', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'POST /foods/recherche-en-ligne': (_) => const FakeResponse(
            {'message': 'Cette fonctionnalité fait partie de l’offre Complet.'},
            status: 402,
          ),
    });

    await monter(tester, (context) => FoodFormSheet.show(context, initialName: 'Gâteau de Savoie', rechercherEnLigne: true));

    expect(FakeApiClient.calls, contains('POST /foods/recherche-en-ligne'));
    expect(find.text(RechercheEnLigne.offre), findsOneWidget);
    expect(find.text(RechercheEnLigne.libelle), findsNothing);
    // La saisie manuelle reste possible.
    expect(find.text('Créer un aliment'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Nom *'), findsOneWidget);
  });

  testWidgets('rien de fiable trouvé : message, et la saisie manuelle reste possible', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'POST /foods/recherche-en-ligne': (_) => FakeResponse(_reponse(
            source: 'indisponible',
            message: 'Aucune source fiable pour ce produit.',
          )),
    });

    await monter(tester, (context) => FoodFormSheet.show(context, initialName: 'Truc inconnu', rechercherEnLigne: true));

    expect(find.text('Aucune source fiable pour ce produit.'), findsOneWidget);
    expect(find.text(RechercheEnLigne.libelle), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Calories (kcal)'), findsOneWidget);
  });
}
