import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:mavioh/core/api_client.dart';
import 'package:mavioh/core/session.dart';
import 'package:mavioh/main.dart';
import 'package:mavioh/models/sport.dart';
import 'package:mavioh/screens/sport/generate_sheet.dart';
import 'package:mavioh/screens/sport/guided_session_screen.dart';
import 'package:mavioh/screens/sport/lieux_sheet.dart';
import 'package:mavioh/screens/sport/proposal_screen.dart';
import 'package:mavioh/services/sport_service.dart';

import '../support/fake_api_client.dart';

Map<String, dynamic> _corps(RequestOptions request) {
  final data = request.data;
  return data is String ? jsonDecode(data) as Map<String, dynamic> : Map<String, dynamic>.from(data as Map);
}

/// Les formatteurs posent des espaces insécables (« 30 s ») : on compare en espaces simples.
String _norm(String text) => text.replaceAll(' ', ' ').replaceAll(' ', ' ');

Finder _texte(String attendu) => find.byWidgetPredicate(
      (w) => w is Text && _norm(w.data ?? w.textSpan?.toPlainText() ?? '') == attendu,
      description: 'Text « $attendu »',
    );

Map<String, dynamic> _lieu(int id, String nom, String type, List<String> materiel, {bool parDefaut = false}) => {
      'id': id,
      'nom': nom,
      'type': type,
      'type_libelle': {'maison': 'Maison', 'salle_publique': 'Salle publique'}[type] ?? type,
      'materiel': materiel,
      'par_defaut': parDefaut,
    };

void _grandEcran(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
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

  group('modèle', () {
    test('resume de repli lisible pour une séance d’avant la v2', () {
      final e = WorkoutExercise.fromJson({
        'id': 1,
        'name': 'Sprint vélo',
        'sets': 10,
        'duration_sec': 30,
        'rest_sec': 112,
      });
      expect(_norm(e.resume), '10 séries de 30 s, 1 min 52 de repos');
      expect(e.prescriptionV2, isNull);
      // Étapes reconstruites : 10 efforts de 30 s et 9 repos de 112 s.
      expect(e.etapesOuRepli.length, 19);
      expect(e.etapesOuRepli.first.dureeSec, 30);
      expect(e.etapesOuRepli[1].type, 'repos');

      final force = WorkoutExercise.fromJson({'id': 2, 'name': 'Squat', 'sets': 3, 'reps': 12, 'rest_sec': 90});
      expect(_norm(force.resume), '3 séries de 12 répétitions, 1 min 30 de repos');
      expect(force.etapesOuRepli.first.isTimed, isFalse);
    });

    test('pastilles de cibles', () {
      final p = Prescription.fromJson({
        'type': 'intervalles',
        'cible': {
          'cadence_rpm': [100, 110],
          'resistance': [7, 8],
          'zone_fc': 4,
          'fc_bpm': [150, 167],
          'allure_min_km': [5.5, 6.0],
          'allure_500m_sec': 125,
          'rpe': [8, 9],
        },
        'rir': 2,
        'tempo': '3-1-1',
      })!;
      expect(p.pastilles.map((c) => _norm(c.label)).toList(), [
        '100–110 tr/min',
        'résistance 7–8/10',
        '5:30–6:00 /km',
        '2:05 /500 m',
        'zone 4 · 150–167 bpm',
        'RPE 8–9',
        'RIR 2',
        'tempo 3-1-1',
      ]);
    });
  });

  testWidgets('« Mes lieux » : créer, définir par défaut, supprimer', (tester) async {
    _grandEcran(tester);
    final lieux = <Map<String, dynamic>>[
      _lieu(1, 'Maison', 'maison', ['aucun', 'tapis'], parDefaut: true),
    ];
    Map<String, dynamic>? created;
    Map<String, dynamic>? updated;
    ApiClient.instance = FakeApiClient.build({
      'GET /sport/lieux': (_) => FakeResponse({'data': lieux}),
      'POST /sport/lieux': (request) {
        created = _corps(request);
        final lieu = _lieu(2, created!['nom'] as String, created!['type'] as String,
            List<String>.from(created!['materiel'] as List));
        lieux.add(lieu);
        return FakeResponse({'message': 'Lieu ajouté.', 'data': lieu}, status: 201);
      },
      'PUT /sport/lieux/2': (request) {
        updated = _corps(request);
        lieux[0]['par_defaut'] = false;
        lieux[1]['par_defaut'] = true;
        return FakeResponse({'message': 'Lieu mis à jour.', 'data': lieux[1]});
      },
      'DELETE /sport/lieux/2': (_) {
        lieux.removeAt(1);
        lieux[0]['par_defaut'] = true;
        return const FakeResponse({'message': 'Lieu supprimé.'});
      },
    });

    await tester.pumpWidget(MaviohApp(
      home: Scaffold(body: LieuxSheet(config: const SportConfig(), service: SportService())),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Maison'), findsWidgets);
    expect(find.text('Par défaut'), findsOneWidget);

    // Création : le type « Salle publique » applique son préréglage de matériel.
    await tester.tap(find.text('Ajouter un lieu'));
    await tester.pumpAndSettle();
    expect(find.text('Nouveau lieu'), findsOneWidget);
    await tester.tap(find.widgetWithText(ChoiceChip, 'Salle publique'));
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextField, 'Nom du lieu'), 'Basic-Fit');
    expect(find.text('CARDIO'), findsOneWidget);
    await tester.tap(find.text('Enregistrer'));
    await tester.pumpAndSettle();

    expect(created, isNotNull);
    expect(created!['nom'], 'Basic-Fit');
    expect(created!['type'], 'salle_publique');
    final materiel = List<String>.from(created!['materiel'] as List);
    expect(materiel, containsAll(['rameur', 'halteres', 'machine']));
    expect(materiel, isNot(contains('piscine')));
    expect(materiel, isNot(contains('velo_route')));
    expect(find.text('Basic-Fit'), findsOneWidget);

    // Lieu par défaut.
    await tester.tap(find.byTooltip('Actions sur Basic-Fit'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Définir par défaut'));
    await tester.pumpAndSettle();
    expect(updated?['par_defaut'], isTrue);
    expect(FakeApiClient.calls, contains('PUT /sport/lieux/2'));

    // Suppression, après confirmation.
    await tester.tap(find.byTooltip('Actions sur Basic-Fit'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Supprimer').last);
    await tester.pumpAndSettle();
    expect(find.text('Supprimer « Basic-Fit » ?'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'Supprimer'));
    await tester.pumpAndSettle();
    expect(FakeApiClient.calls, contains('DELETE /sport/lieux/2'));
    expect(find.text('Basic-Fit'), findsNothing);
  });

  testWidgets('la génération envoie le lieu par défaut (lieu_id) et son matériel', (tester) async {
    _grandEcran(tester);
    Map<String, dynamic>? body;
    ApiClient.instance = FakeApiClient.build({
      'GET /sport/lieux': (_) => FakeResponse({
            'data': [
              _lieu(2, 'Salle du quartier', 'salle_publique', ['halteres', 'rameur', 'velo'], parDefaut: true),
              _lieu(1, 'Maison', 'maison', ['aucun', 'tapis']),
            ],
          }),
      'POST /sport/sessions/generate': (request) {
        body = _corps(request);
        return const FakeResponse({
          'data': {'title': 'Séance vélo', 'duration_min': 30, 'blocks': []},
        });
      },
    });

    GeneratedProposal? result;
    await tester.pumpWidget(MaviohApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => Center(
            child: TextButton(
              onPressed: () async {
                result = await GenerateSheet.show(context, config: const SportConfig(), service: SportService());
              },
              child: const Text('ouvrir'),
            ),
          ),
        ),
      ),
    ));
    await tester.tap(find.text('ouvrir'));
    await tester.pumpAndSettle();

    // Le lieu par défaut est présélectionné, son matériel résumé dessous ; pas de liste de matériel.
    final chip = tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Salle du quartier'));
    expect(chip.selected, isTrue);
    expect(find.textContaining('Haltères, Rameur'), findsOneWidget);
    expect(find.text('Ajouter un lieu'), findsOneWidget);
    expect(find.text('Matériel'), findsNothing);

    await tester.tap(find.text('Proposer une séance'));
    await tester.pumpAndSettle();

    expect(body, isNotNull);
    expect(body!['lieu_id'], 2);
    expect(body!['lieu'], 'salle_publique');
    expect(body!['equipment'], ['halteres', 'rameur', 'velo']);
    expect(result?.proposal.title, 'Séance vélo');
  });

  testWidgets('la proposition affiche resume, pastilles, consigne et le repli lisible', (tester) async {
    _grandEcran(tester);
    ApiClient.instance = FakeApiClient.build(const {});
    final proposal = SessionProposal.fromJson({
      'title': 'Fractionné vélo',
      'duration_min': 40,
      'lieu_nom': 'Salle du quartier',
      'blocks': [
        {
          'key': 'principal',
          'name': 'Corps de séance',
          'exercises': [
            {
              'name': 'Fractionné court',
              'sets': 10,
              'duration_sec': 30,
              'rest_sec': 90,
              'resume': '10 × 30 s à 100–110 tr/min · résistance 7–8/10 · zone 4 (150–167 bpm)',
              'prescription': {
                'type': 'intervalles',
                'repetitions': 10,
                'effort_sec': 30,
                'recup_sec': 90,
                'cible': {
                  'cadence_rpm': [100, 110],
                  'resistance': [7, 8],
                  'zone_fc': 4,
                  'fc_bpm': [150, 167],
                },
                'recup_cible': {
                  'cadence_rpm': [80, 85],
                  'resistance': [3, 3],
                },
                'consigne': 'Jambes rapides, buste calme.',
              },
            },
            // Ancienne réponse : ni resume, ni prescription.
            {'name': 'Sprint d’autrefois', 'sets': 10, 'duration_sec': 30, 'rest_sec': 112},
          ],
        },
      ],
    });

    await tester.pumpWidget(MaviohApp(home: ProposalScreen(proposal: proposal, service: SportService())));
    await tester.pumpAndSettle();

    expect(find.text('Salle du quartier'), findsOneWidget);
    expect(find.text('10 × 30 s à 100–110 tr/min · résistance 7–8/10 · zone 4 (150–167 bpm)'), findsOneWidget);
    expect(_texte('100–110 tr/min'), findsOneWidget);
    expect(_texte('résistance 7–8/10'), findsOneWidget);
    expect(_texte('zone 4 · 150–167 bpm'), findsOneWidget);
    expect(_texte('Récup : 80–85 tr/min · résistance 3/10'), findsOneWidget);
    expect(find.text('Jambes rapides, buste calme.'), findsOneWidget);
    expect(_texte('10 séries de 30 s, 1 min 52 de repos'), findsOneWidget);
    expect(find.textContaining('1min52'), findsNothing);
  });

  testWidgets('la séance guidée enchaîne les étapes au fil du temps, puis enregistre le RPE', (tester) async {
    _grandEcran(tester);
    var now = DateTime(2026, 10, 7, 18);
    Map<String, dynamic>? complete;
    ApiClient.instance = FakeApiClient.build({
      'POST /sport/sessions/5/complete': (request) {
        complete = _corps(request);
        return const FakeResponse({
          'data': {'id': 5, 'title': 'Vélo + force', 'status': 'terminee', 'duration_min': 4},
          'nutrition': {'calories_burned': 50, 'calories_bonus': 50},
        });
      },
    });

    final session = WorkoutSession.fromJson({
      'id': 5,
      'title': 'Vélo + force',
      'status': 'en_cours',
      'duration_min': 30,
      'started_at': now.toIso8601String(),
      'exercises': [
        {
          'id': 11,
          'name': 'Fractionné vélo',
          'block': 'principal',
          'position': 1,
          'etapes': [
            {'label': 'Effort 1/2', 'type': 'effort', 'duree_sec': 30, 'cible': '100–110 tr/min · 150–167 bpm'},
            {'label': 'Récup 1/2', 'type': 'recup', 'duree_sec': 90, 'cible': '80 tr/min'},
            {'label': 'Effort 2/2', 'type': 'effort', 'duree_sec': 30, 'cible': '100–110 tr/min · 150–167 bpm'},
          ],
        },
        // Ancienne ligne : étapes reconstruites (séries sans durée → « Série terminée »).
        {'id': 12, 'name': 'Pompes', 'block': 'principal', 'position': 2, 'sets': 2, 'reps': 10, 'rest_sec': 60},
      ],
    });

    Object? popped = 'rien';
    await tester.pumpWidget(MaviohApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => Center(
            child: TextButton(
              onPressed: () async {
                popped = await Navigator.of(context).push<SessionCompleteResult>(
                  MaterialPageRoute(
                    builder: (_) => GuidedSessionScreen(session: session, service: SportService(), clock: () => now),
                  ),
                );
              },
              child: const Text('ouvrir'),
            ),
          ),
        ),
      ),
    ));
    await tester.tap(find.text('ouvrir'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));

    expect(find.text('Effort 1/2'), findsOneWidget);
    expect(find.text('0:30'), findsOneWidget);
    expect(find.text('100–110 tr/min · 150–167 bpm'), findsOneWidget);
    expect(find.text('Étape 1 / 6'), findsOneWidget);

    now = now.add(const Duration(seconds: 10));
    await tester.pump(const Duration(milliseconds: 250));
    expect(find.text('0:20'), findsOneWidget);

    // Pause : le temps passe, le compte à rebours ne bouge pas.
    await tester.tap(find.byTooltip('Pause'));
    await tester.pump();
    now = now.add(const Duration(seconds: 60));
    await tester.pump(const Duration(milliseconds: 250));
    expect(find.text('0:20'), findsOneWidget);
    expect(find.text('En pause'), findsOneWidget);
    await tester.tap(find.byTooltip('Reprendre'));
    await tester.pump();

    now = now.add(const Duration(seconds: 21));
    await tester.pump(const Duration(milliseconds: 250));
    expect(find.text('Récup 1/2'), findsOneWidget);

    // Application en arrière-plan 2 min : la récup et le second effort sont rattrapés.
    now = now.add(const Duration(seconds: 125));
    await tester.pump(const Duration(milliseconds: 250));
    expect(find.text('Série 1/2'), findsOneWidget);
    expect(find.text('Série terminée'), findsOneWidget);
    expect(find.text('10 répétitions'), findsOneWidget);

    await tester.tap(find.text('Série terminée'));
    await tester.pump(const Duration(milliseconds: 250));
    expect(find.text('Repos'), findsOneWidget);

    await tester.tap(find.byTooltip('Passer'));
    await tester.pump(const Duration(milliseconds: 250));
    expect(find.text('Série 2/2'), findsOneWidget);

    await tester.tap(find.text('Série terminée'));
    await tester.pump(const Duration(milliseconds: 250));
    expect(find.text('Bravo, séance terminée !'), findsOneWidget);

    await tester.tap(find.text('Terminer'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ChoiceChip, '7'));
    await tester.pump();
    await tester.tap(find.text('Enregistrer la séance'));
    await tester.pumpAndSettle();

    expect(complete, isNotNull);
    expect(complete!['rpe'], 7);
    expect(complete!['duration_min'], 4);
    final exercises = List<Map<String, dynamic>>.from(complete!['exercises'] as List);
    expect(exercises.every((e) => e['completed'] == true), isTrue);
    expect(popped, isA<SessionCompleteResult>());
  });

  testWidgets('quitter la séance guidée demande confirmation', (tester) async {
    _grandEcran(tester);
    ApiClient.instance = FakeApiClient.build(const {});
    final session = WorkoutSession.fromJson({
      'id': 6,
      'title': 'Gainage',
      'status': 'en_cours',
      'exercises': [
        {'id': 1, 'name': 'Planche', 'sets': 1, 'duration_sec': 45},
      ],
    });
    await tester.pumpWidget(MaviohApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => TextButton(
            onPressed: () => Navigator.of(context).push<void>(
              MaterialPageRoute(builder: (_) => GuidedSessionScreen(session: session, service: SportService())),
            ),
            child: const Text('ouvrir'),
          ),
        ),
      ),
    ));
    await tester.tap(find.text('ouvrir'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));

    // Retour Android : confirmation, puis on reste.
    await tester.binding.handlePopRoute();
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('Quitter la séance guidée ?'), findsOneWidget);
    await tester.tap(find.text('Continuer'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('Planche'), findsOneWidget);

    await tester.tap(find.byTooltip('Quitter la séance guidée'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));
    await tester.tap(find.text('Quitter'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    expect(find.text('ouvrir'), findsOneWidget);
  });
}
