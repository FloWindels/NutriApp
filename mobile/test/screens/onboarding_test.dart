import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:mavioh/core/api_client.dart';
import 'package:mavioh/core/formatters.dart';
import 'package:mavioh/core/session.dart';
import 'package:mavioh/main.dart';
import 'package:mavioh/models/me.dart';
import 'package:mavioh/screens/home_screen.dart';
import 'package:mavioh/screens/onboarding/accueil.dart';
import 'package:mavioh/screens/onboarding/visite_guidee.dart';
import 'package:mavioh/widgets/budget_card.dart';
import 'package:mavioh/widgets/notification_bell.dart';

import '../support/fake_api_client.dart';

Map<String, dynamic> _settings({String? onboarding, String? visite}) => {
      'data': {
        'notif_peremption': true,
        'notif_rappel_repas': false,
        'notif_rappel_sport': false,
        'heure_rappel': null,
        'jours_alerte_peremption': 3,
        'unites': 'metrique',
        'theme': 'systeme',
        'langue': 'fr',
        'timezone': 'Europe/Paris',
        'ia_seances': true,
        'magasin_prefere_id': null,
        'onboarding_termine_le': onboarding,
        'visite_vue_le': visite,
        'partage_profil_foyer': null,
        'updated_at': null,
      },
    };

Map<String, dynamic> _besoins() => {
      'bmr': 1700,
      'tdee': 2400,
      'calories_recommandees': 2400,
      'proteines_g': 120,
      'glucides_g': 300,
      'lipides_g': 80,
      'variation_hebdo_kg': 0,
      'plancher_kcal': 1700,
      'avertissements': <String>[],
      'etapes': <String>[],
      'mention': 'Estimation, pas un avis médical.',
      'is_estimate': true,
    };

/// Écran de lancement : ouvre le questionnaire comme le fait l'app, et garde son résultat.
class _Lanceur extends StatefulWidget {
  const _Lanceur();

  @override
  State<_Lanceur> createState() => _LanceurState();
}

class _LanceurState extends State<_Lanceur> {
  bool? resultat;

  @override
  Widget build(BuildContext context) => Scaffold(
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              ElevatedButton(
                onPressed: () async {
                  final r = await Accueil.ouvrirQuestionnaire(context);
                  setState(() => resultat = r);
                },
                child: const Text('Ouvrir'),
              ),
              if (resultat != null) Text('Résultat : $resultat'),
            ],
          ),
        ),
      );
}

void main() {
  setUpAll(() async {
    await initializeDateFormatting('fr_FR');
  });

  setUp(() {
    ApiClient.resetInstance();
    Session.instance.clearCache();
    Session.instance.hydrate(Me.fromJson(FakePayloads.me(hasProfile: false)));
    FakeApiClient.calls.clear();
    Accueil.reinitialiser();
  });

  tearDown(ApiClient.resetInstance);

  Future<void> taper(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.pumpAndSettle();
    await tester.tap(finder);
    await tester.pumpAndSettle();
  }

  testWidgets('le questionnaire guide étape par étape puis enregistre le profil et l’accueil', (tester) async {
    Object? corpsParametres;
    Object? corpsProfil;
    ApiClient.instance = FakeApiClient.build({
      'GET /profile': (_) => const FakeResponse.notFound(),
      'POST /profile/preview': (_) => FakeResponse({
            'data': {
              'besoins': _besoins(),
              'cibles_effectives': {'calories': 2400, 'proteines': 120, 'glucides': 300, 'lipides': 80, 'source': 'calcul'},
              'imc': 22.9,
              'imc_cible': null,
            },
          }),
      'PUT /profile': (request) {
        corpsProfil = request.data;
        return const FakeResponse({'message': 'Profil mis à jour.', 'has_profile': true});
      },
      'PUT /settings': (request) {
        corpsParametres = request.data;
        return FakeResponse(_settings(onboarding: '2026-10-07T09:30:00.000000Z'));
      },
    });

    await tester.pumpWidget(const MaviohApp(home: _Lanceur()));
    await tester.tap(find.text('Ouvrir'));
    await tester.pumpAndSettle();

    expect(find.text('Faisons connaissance'), findsOneWidget);
    expect(find.text('Étape 1 sur 7'), findsOneWidget);

    // Rien n'est choisi : l'étape refuse d'avancer et dit pourquoi.
    await taper(tester, find.text('Suivant'));
    expect(find.text('Choisis une réponse.'), findsOneWidget);

    await taper(tester, find.text('Homme'));
    await tester.enterText(find.widgetWithText(TextField, 'Âge'), '30');
    await taper(tester, find.text('Suivant'));

    expect(find.text('Ta taille et ton poids'), findsOneWidget);
    await tester.enterText(find.widgetWithText(TextField, 'Taille (cm)'), '178');
    await tester.enterText(find.widgetWithText(TextField, 'Poids actuel (kg)'), '72');
    await taper(tester, find.text('Suivant'));

    await taper(tester, find.text('Modéré'));
    await taper(tester, find.text('Suivant'));

    await taper(tester, find.text('Maintenir mon poids'));
    await taper(tester, find.text('Suivant'));

    await taper(tester, find.text('Omnivore'));
    await taper(tester, find.text('Suivant'));

    // L'accord santé est déjà donné sur ce compte.
    expect(find.text('Ton accord'), findsOneWidget);
    await taper(tester, find.text('Suivant'));

    expect(find.text('Tes objectifs'), findsOneWidget);
    expect(find.text(fmtKcal(2400)), findsOneWidget);
    expect(FakeApiClient.calls, contains('POST /profile/preview'));

    await taper(tester, find.text('Enregistrer'));

    expect(find.text('Résultat : true'), findsOneWidget);
    expect((corpsProfil as Map)['objectif_calcul_auto'], isTrue);
    expect((corpsProfil as Map)['sexe'], 'homme');
    expect(corpsParametres, {'onboarding_termine': true});
    expect(Session.instance.user!.hasProfile, isTrue);
  });

  testWidgets('« Plus tard » referme le questionnaire sans rien enregistrer', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /profile': (_) => const FakeResponse.notFound(),
    });

    await tester.pumpWidget(const MaviohApp(home: _Lanceur()));
    await tester.tap(find.text('Ouvrir'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Plus tard'));
    await tester.pumpAndSettle();

    expect(find.text('Résultat : false'), findsOneWidget);
    expect(FakeApiClient.calls.where((c) => c.startsWith('PUT')), isEmpty);
  });

  testWidgets('la visite guidée avance, recule et se passe', (tester) async {
    ApiClient.instance = FakeApiClient.build(const {});
    await tester.pumpWidget(MaviohApp(
      home: Builder(
        builder: (context) => Scaffold(
          body: Center(
            child: ElevatedButton(onPressed: () => VisiteGuidee.show(context), child: const Text('Visite')),
          ),
        ),
      ),
    ));
    await tester.tap(find.text('Visite'));
    await tester.pumpAndSettle();

    expect(find.text('Bienvenue sur Mavi’oh'), findsOneWidget);
    expect(find.text('VISITE GUIDÉE · 1/${etapesVisite.length}'), findsOneWidget);

    await tester.tap(find.text('C’est parti'));
    await tester.pumpAndSettle();
    expect(find.text('Ton journal des repas'), findsOneWidget);
    expect(find.text('Onglet « Repas »'), findsOneWidget);

    await tester.tap(find.byTooltip('Précédent'));
    await tester.pumpAndSettle();
    expect(find.text('Bienvenue sur Mavi’oh'), findsOneWidget);

    await tester.tap(find.text('Passer'));
    await tester.pumpAndSettle();
    expect(find.text('Bienvenue sur Mavi’oh'), findsNothing);
  });

  group('visite guidée dans l’app', () {
    Object? corpsParametres;

    setUp(() {
      NotificationBell.unread.value = 0;
      Session.instance.hydrate(Me.fromJson(FakePayloads.me()));
      corpsParametres = null;
      ApiClient.instance = FakeApiClient.build({
        'GET /settings': (_) => FakeResponse(_settings()),
        'PUT /settings': (request) {
          corpsParametres = request.data;
          return FakeResponse(_settings(visite: '2026-10-07T09:30:00.000000Z'));
        },
        'GET /dashboard': (_) => FakeResponse(FakePayloads.dashboard()),
        'GET /notifications': (_) => FakeResponse(FakePayloads.notifications()),
        'GET /portions': (_) => FakeResponse(FakePayloads.portions()),
      });
    });

    // Un téléphone, pas la surface 800 × 600 par défaut des tests.
    Future<void> telephone(WidgetTester tester) async {
      tester.view.physicalSize = const Size(1080, 2340);
      tester.view.devicePixelRatio = 2.75;
      addTearDown(tester.view.reset);
    }

    int ongletActif(WidgetTester tester) => tester.widget<NavigationBar>(find.byType(NavigationBar)).selectedIndex;

    Rect? zoneEclairee(WidgetTester tester) =>
        (tester.widget<CustomPaint>(find.byKey(const ValueKey('visite-voile'))).painter as ProjecteurVisite).cible;

    Future<void> suivant(WidgetTester tester) async {
      await tester.tap(find.descendant(of: find.byKey(const ValueKey('visite-carte')), matching: find.byType(FilledButton)));
      await tester.pumpAndSettle();
    }

    testWidgets('chaque étape ouvre son écran et l’éclaire, « Passer » ramène à l’accueil', (tester) async {
      await telephone(tester);
      await tester.pumpWidget(const MaviohApp(home: HomeScreen()));
      await tester.pumpAndSettle();

      // Accueil : le budget du jour est éclairé, la carte ne le recouvre pas.
      expect(find.text('Bienvenue sur Mavi’oh'), findsOneWidget);
      expect(ongletActif(tester), 0);
      final budget = tester.getRect(find.byType(BudgetCard));
      expect(zoneEclairee(tester)!.contains(budget.center), isTrue);
      expect(tester.getRect(find.byKey(const ValueKey('visite-carte'))).overlaps(budget), isFalse);

      // Repas : l'onglet s'ouvre ; son écran n'a pas de données ici (erreur), la visite
      // se rabat sur le titre de l'écran au lieu de rester bloquée.
      await suivant(tester);
      expect(find.text('Ton journal des repas'), findsOneWidget);
      expect(ongletActif(tester), 1);
      final titre = tester.getRect(find.descendant(of: find.byType(AppBar), matching: find.text('Repas du jour')));
      expect(zoneEclairee(tester)!.contains(titre.center), isTrue);

      // Recherche d'aliments : une section de « Plus », ouverte sans empiler de page.
      await suivant(tester);
      expect(find.text('Trouver un aliment'), findsOneWidget);
      expect(ongletActif(tester), 4);
      final recherche = tester.getRect(find.widgetWithText(TextField, 'Nom, marque ou code-barres'));
      expect(zoneEclairee(tester)!.contains(recherche.center), isTrue);
      expect(find.byType(HomeScreen), findsOneWidget);

      await tester.tap(find.text('Passer'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('visite-carte')), findsNothing);
      expect(ongletActif(tester), 0);
      expect(find.text('Bonjour Demo'), findsOneWidget);
      expect(corpsParametres, {'visite_vue': true});
    });

    testWidgets('le bouton retour revient d’une étape, puis ferme la visite à la première', (tester) async {
      await telephone(tester);
      await tester.pumpWidget(const MaviohApp(home: HomeScreen()));
      await tester.pumpAndSettle();
      await suivant(tester);
      await suivant(tester);
      expect(find.text('Trouver un aliment'), findsOneWidget);

      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('Ton journal des repas'), findsOneWidget);
      expect(ongletActif(tester), 1);

      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('Bienvenue sur Mavi’oh'), findsOneWidget);

      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('visite-carte')), findsNothing);
      expect(ongletActif(tester), 0);
    });

    testWidgets('la dernière étape éclaire le bouton + de l’accueil, et « Terminer » referme', (tester) async {
      await telephone(tester);
      await tester.pumpWidget(const MaviohApp(home: HomeScreen()));
      await tester.pumpAndSettle();
      for (var i = 0; i < etapesVisite.length - 1; i++) {
        await suivant(tester);
      }
      expect(find.text('À toi de jouer'), findsOneWidget);
      expect(ongletActif(tester), 0);
      final plus = tester.getRect(find.byType(FloatingActionButton));
      expect(zoneEclairee(tester)!.contains(plus.center), isTrue);
      // Bouton en bas d'écran : la carte passe en haut.
      expect(tester.getRect(find.byKey(const ValueKey('visite-carte'))).bottom, lessThan(plus.top));

      await tester.tap(find.text('Terminer'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('visite-carte')), findsNothing);
      expect(corpsParametres, {'visite_vue': true});
    });
  });

  testWidgets('une fonction hors offre est annoncée comme incluse dans une offre payante', (tester) async {
    Session.instance.hydrate(Me.fromJson({
      ...FakePayloads.me(),
      'offre': {
        'nom': 'gratuit',
        'capacites': <String>[],
      },
    }));
    ApiClient.instance = FakeApiClient.build(const {});
    await tester.pumpWidget(MaviohApp(
      home: Builder(
        builder: (context) => Scaffold(
          body: ElevatedButton(onPressed: () => VisiteGuidee.show(context), child: const Text('Visite')),
        ),
      ),
    ));
    await tester.tap(find.text('Visite'));
    await tester.pumpAndSettle();
    // Bienvenue › Repas › Aliments › Recettes › Coach
    for (var i = 0; i < 4; i++) {
      await tester.tap(find.byType(FilledButton));
      await tester.pumpAndSettle();
    }
    expect(find.text('Le coach du jour'), findsOneWidget);
    expect(find.text('Inclus dans l’offre Complet.'), findsOneWidget);
  });

  testWidgets('profil complet et visite jamais vue : seule la visite est proposée, puis marquée vue', (tester) async {
    Session.instance.hydrate(Me.fromJson(FakePayloads.me()));
    Object? corps;
    ApiClient.instance = FakeApiClient.build({
      'GET /settings': (_) => FakeResponse(_settings()),
      'PUT /settings': (request) {
        corps = request.data;
        return FakeResponse(_settings(visite: '2026-10-07T09:30:00.000000Z'));
      },
    });

    await tester.pumpWidget(MaviohApp(
      home: Builder(
        builder: (context) => Scaffold(
          body: ElevatedButton(onPressed: () => Accueil.lancer(context), child: const Text('Lancer')),
        ),
      ),
    ));
    await tester.tap(find.text('Lancer'));
    await tester.pumpAndSettle();

    expect(find.text('Faisons connaissance'), findsNothing);
    expect(find.text('Bienvenue sur Mavi’oh'), findsOneWidget);

    await tester.tap(find.text('Passer'));
    await tester.pumpAndSettle();
    expect(corps, {'visite_vue': true});

    // Une seule proposition par session.
    FakeApiClient.calls.clear();
    await tester.tap(find.text('Lancer'));
    await tester.pumpAndSettle();
    expect(FakeApiClient.calls, isEmpty);
  });
}
