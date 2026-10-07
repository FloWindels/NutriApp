import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mavioh/core/api_client.dart';
import 'package:mavioh/services/mise_a_jour.dart';

import '../support/fake_api_client.dart';

Map<String, dynamic> _version(int code) => {
      'data': {
        'version_code': code,
        'version_name': '1.$code.0',
        'notes': 'Ce qui change.',
        'taille': 78 * 1048576,
        'sha256': 'abc',
        'publiee_le': '2026-10-07T15:00:00+00:00',
      },
    };

Widget _ecran({bool manuel = false}) => MaterialApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => ElevatedButton(
            onPressed: () => MiseAJour.verifier(context, manuel: manuel),
            child: const Text('Vérifier'),
          ),
        ),
      ),
    );

void main() {
  setUp(() {
    MiseAJour.reinitialiser();
    MiseAJour.estAndroid = () => true;
    MiseAJour.versionInstallee = () async => 2;
    FakeApiClient.calls.clear();
  });

  testWidgets('une version plus récente est proposée', (tester) async {
    ApiClient.instance = FakeApiClient.build({'GET /app/android': (_) => FakeResponse(_version(3))});
    await tester.pumpWidget(_ecran());
    await tester.tap(find.text('Vérifier'));
    await tester.pumpAndSettle();

    expect(find.text('Mavi’oh 1.3.0 est disponible'), findsOneWidget);
    expect(find.text('Ce qui change.'), findsOneWidget);
    expect(find.text('Mettre à jour'), findsOneWidget);

    await tester.tap(find.text('Plus tard'));
    await tester.pumpAndSettle();
    expect(find.text('Mettre à jour'), findsNothing);
  });

  testWidgets('même version : rien au lancement, un message si on la cherche', (tester) async {
    ApiClient.instance = FakeApiClient.build({'GET /app/android': (_) => FakeResponse(_version(2))});
    await tester.pumpWidget(_ecran());
    await tester.tap(find.text('Vérifier'));
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.byType(SnackBar), findsNothing);

    await tester.pumpWidget(_ecran(manuel: true));
    await tester.tap(find.text('Vérifier'));
    await tester.pumpAndSettle();
    expect(find.text('Tu as déjà la dernière version de Mavi’oh.'), findsOneWidget);
  });

  testWidgets('une seule vérification automatique par lancement', (tester) async {
    ApiClient.instance = FakeApiClient.build({'GET /app/android': (_) => FakeResponse(_version(2))});
    await tester.pumpWidget(_ecran());
    await tester.tap(find.text('Vérifier'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Vérifier'));
    await tester.pumpAndSettle();
    expect(FakeApiClient.calls.where((c) => c == 'GET /app/android').length, 1);
  });

  testWidgets('aucune version publiée : message seulement en recherche manuelle', (tester) async {
    ApiClient.instance = FakeApiClient.build(const {});
    await tester.pumpWidget(_ecran(manuel: true));
    await tester.tap(find.text('Vérifier'));
    await tester.pumpAndSettle();
    expect(find.text('Aucune version n’est publiée pour le moment.'), findsOneWidget);
  });

  testWidgets('hors Android, rien n’est demandé au serveur', (tester) async {
    MiseAJour.estAndroid = () => false;
    ApiClient.instance = FakeApiClient.build({'GET /app/android': (_) => FakeResponse(_version(3))});
    await tester.pumpWidget(_ecran(manuel: true));
    await tester.tap(find.text('Vérifier'));
    await tester.pumpAndSettle();
    expect(FakeApiClient.calls, isEmpty);
  });
}
