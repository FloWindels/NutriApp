import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:mavioh/core/api_client.dart';
import 'package:mavioh/core/formatters.dart';
import 'package:mavioh/core/session.dart';
import 'package:mavioh/main.dart';
import 'package:mavioh/models/meal.dart';
import 'package:mavioh/screens/meals/plate_photo_sheet.dart';
import 'package:mavioh/widgets/add_to_meal_sheet.dart';

import '../support/fake_api_client.dart';

/// Rend une « photo » minuscule sans toucher à l'appareil.
class _FakePicker extends Fake implements ImagePicker {
  @override
  Future<XFile?> pickImage({
    required ImageSource source,
    double? maxWidth,
    double? maxHeight,
    int? imageQuality,
    CameraDevice preferredCameraDevice = CameraDevice.rear,
    bool requestFullMetadata = true,
  }) async =>
      XFile.fromData(Uint8List.fromList(const [1, 2, 3]), mimeType: 'image/jpeg');
}

Map<String, dynamic> _analyse({List<Map<String, dynamic>> aliments = const []}) => {
      'data': {
        'aliments': aliments,
        'description': '',
        'confiance_globale': 0.7,
        'avertissements': const <String>[],
        'source': 'ia',
        'llm_model': 'llava',
      },
    };

const _riz = {
  'nom': 'Riz blanc',
  'marque': null,
  'quantite': 150,
  'unite': 'g',
  'confiance': 0.8,
  'food': {'id': 3, 'name': 'Riz blanc cuit', 'brand': null, 'calories': 130},
  'valeurs_proposees': null,
};

Map<String, dynamic> _repas() => {
      'message': 'ok',
      'data': {'id': 9, 'date': '2026-10-07', 'type': 'dejeuner', 'items': const []},
    };

Map<String, dynamic> _corps(RequestOptions request) {
  final data = request.data;
  return data is String ? jsonDecode(data) as Map<String, dynamic> : Map<String, dynamic>.from(data as Map);
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

  /// Ouvre la feuille depuis un bouton, comme l'écran des repas.
  Future<void> ouvrir(WidgetTester tester, {required Future<PickedMealItem?> Function(BuildContext) choisirAjout}) async {
    await tester.binding.setSurfaceSize(const Size(800, 1400));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(MaviohApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => TextButton(
            onPressed: () => showModalBottomSheet<bool>(
              context: context,
              isScrollControlled: true,
              builder: (_) => PlatePhotoSheet(
                date: DateTime(2026, 10, 7),
                type: 'dejeuner',
                imagePicker: _FakePicker(),
                choisirAjout: choisirAjout,
              ),
            ),
            child: const Text('ouvrir'),
          ),
        ),
      ),
    ));
    await tester.tap(find.text('ouvrir'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Photographier'));
    await tester.pumpAndSettle();
  }

  const pates = PickedMealItem(
    input: MealItemInput(foodId: 42, quantity: 100, unit: 'g'),
    label: 'Pâtes complètes',
    calories: 350,
  );

  testWidgets('un aliment oublié rejoint les lignes reconnues, et tout part en un seul repas', (tester) async {
    Map<String, dynamic>? envoye;
    ApiClient.instance = FakeApiClient.build({
      'POST /meals/analyze-photo': (_) => FakeResponse(_analyse(aliments: [_riz])),
      'POST /meals': (request) {
        envoye = _corps(request);
        return FakeResponse(_repas(), status: 201);
      },
    });

    await ouvrir(tester, choisirAjout: (_) async => pates);

    expect(find.text('Riz blanc cuit'), findsOneWidget);
    // Rien n'est écrit tant que la personne n'a pas validé.
    expect(FakeApiClient.calls, isNot(contains('POST /meals')));

    await tester.tap(find.text('Ajouter un aliment oublié'));
    await tester.pumpAndSettle();

    expect(find.text('Pâtes complètes'), findsOneWidget);
    expect(find.text('Ajouté par toi · 350${nbsp}kcal'), findsOneWidget);
    expect(FakeApiClient.calls, isNot(contains('POST /meals')));

    // La quantité se corrige comme pour une ligne reconnue.
    await tester.enterText(find.widgetWithText(TextFormField, '100'), '200');
    await tester.pumpAndSettle();
    expect(find.text('Ajouté par toi · 700${nbsp}kcal'), findsOneWidget);

    await tester.tap(find.text('Vérifié, ajouter au repas'));
    await tester.pumpAndSettle();

    final items = (envoye!['items'] as List).cast<Map<String, dynamic>>();
    expect(items, hasLength(2));
    expect(items[0], containsPair('food_id', 3));
    expect(items[1], containsPair('food_id', 42));
    expect(items[1]['quantity'], 200);
    expect(items[1]['unit'], 'g');
    expect(find.text('Photo de mon assiette'), findsNothing);
  });

  testWidgets('sans rien reconnu, la saisie reste possible et une ligne ajoutée se retire', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'POST /meals/analyze-photo': (_) => FakeResponse(_analyse()),
    });

    await ouvrir(tester, choisirAjout: (_) async => pates);

    FilledButton valider() => tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Vérifié, ajouter au repas'));

    expect(find.text('Ajouter un aliment oublié'), findsOneWidget);
    expect(valider().onPressed, isNull);

    await tester.tap(find.text('Ajouter un aliment oublié'));
    await tester.pumpAndSettle();
    expect(find.text('Pâtes complètes'), findsOneWidget);
    expect(valider().onPressed, isNotNull);

    await tester.tap(find.byTooltip('Retirer Pâtes complètes'));
    await tester.pumpAndSettle();
    expect(find.text('Pâtes complètes'), findsNothing);
    expect(valider().onPressed, isNull);
  });

  testWidgets('en mode choix seul, un fréquent revient à l’appelant sans être enregistré', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /meals/frequent': (_) => const FakeResponse({
            'data': [
              {
                'kind': 'food',
                'id': 7,
                'label': 'Yaourt nature',
                'brand': null,
                'last_quantity': 125,
                'last_unit': 'g',
                'calories_per_100g': 60,
                'count': 4,
              },
            ],
          }),
    });

    PickedMealItem? choix;
    await tester.binding.setSurfaceSize(const Size(800, 1400));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaviohApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => TextButton(
            onPressed: () async => choix = await AddToMealSheet.pick(context, title: 'Ajouter un aliment oublié'),
            child: const Text('ouvrir'),
          ),
        ),
      ),
    ));
    await tester.tap(find.text('ouvrir'));
    await tester.pumpAndSettle();

    expect(find.text('Ajouter un aliment oublié'), findsOneWidget);
    // Le repas est fixé par l'appelant : pas de sélecteur de repas ici.
    expect(find.byType(SegmentedButton<String>), findsNothing);

    await tester.tap(find.byIcon(Icons.add_rounded).first);
    await tester.pumpAndSettle();

    expect(choix, isNotNull);
    expect(choix!.label, 'Yaourt nature');
    expect(choix!.input.foodId, 7);
    expect(choix!.input.quantity, 125);
    expect(choix!.calories, closeTo(75, 0.01));
    expect(FakeApiClient.calls, isNot(contains('POST /meals')));
  });
}
