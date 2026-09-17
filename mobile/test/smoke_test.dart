import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:mavioh/core/api_client.dart';
import 'package:mavioh/core/session.dart';
import 'package:mavioh/main.dart';
import 'package:mavioh/models/me.dart';
import 'package:mavioh/screens/dashboard_screen.dart';
import 'package:mavioh/screens/home_screen.dart';
import 'package:mavioh/screens/login_screen.dart';
import 'package:mavioh/screens/menu_screen.dart';
import 'package:mavioh/widgets/error_state.dart';
import 'package:mavioh/widgets/notification_bell.dart';

import 'support/fake_api_client.dart';

void main() {
  setUpAll(() async {
    await initializeDateFormatting('fr_FR');
  });

  setUp(() {
    ApiClient.resetInstance();
    Session.instance.clearCache();
    NotificationBell.unread.value = 0;
    FakeApiClient.calls.clear();
  });

  tearDown(() {
    ApiClient.resetInstance();
  });

  Widget wrap(Widget child) => MaviohApp(home: child);

  group('LoginScreen', () {
    testWidgets('renders brand, tagline, fields and forgot-password link', (tester) async {
      ApiClient.instance = FakeApiClient.build(const {});
      await tester.pumpWidget(wrap(const LoginScreen()));
      await tester.pumpAndSettle();

      expect(find.text('Mavi’oh'), findsOneWidget);
      expect(find.text('Ton coach nutrition et sport, au quotidien.'), findsOneWidget);
      expect(find.text('Email'), findsOneWidget);
      expect(find.text('Mot de passe'), findsOneWidget);
      expect(find.text('Mot de passe oublié ?'), findsOneWidget);
      expect(find.text('Se connecter'), findsOneWidget);
    });

    testWidgets('shows the session-expired banner and validates email format', (tester) async {
      ApiClient.instance = FakeApiClient.build(const {});
      await tester.pumpWidget(wrap(const LoginScreen(initialMessage: 'Ta session a expiré, reconnecte-toi.')));
      await tester.pumpAndSettle();

      expect(find.text('Ta session a expiré, reconnecte-toi.'), findsOneWidget);

      await tester.enterText(find.widgetWithText(TextFormField, 'Email'), 'pas-un-email');
      await tester.enterText(find.widgetWithText(TextFormField, 'Mot de passe'), 'secret123');
      await tester.tap(find.text('Se connecter'));
      await tester.pumpAndSettle();

      expect(find.text('Adresse email invalide'), findsOneWidget);
      expect(FakeApiClient.calls, isEmpty);
    });

    testWidgets('maps a 422 on login to the email field error', (tester) async {
      ApiClient.instance = FakeApiClient.build({
        'POST /login': (_) => const FakeResponse(
              {'message': 'Identifiants invalides.', 'errors': {'email': ['Identifiants invalides.']}},
              status: 422,
            ),
      });
      await tester.pumpWidget(wrap(const LoginScreen()));
      await tester.pumpAndSettle();

      await tester.enterText(find.widgetWithText(TextFormField, 'Email'), 'demo@mavioh.app');
      await tester.enterText(find.widgetWithText(TextFormField, 'Mot de passe'), 'wrong-pass');
      await tester.tap(find.text('Se connecter'));
      await tester.pumpAndSettle();

      expect(find.text('Identifiants invalides.'), findsOneWidget);
      expect(FakeApiClient.calls, contains('POST /login'));
    });
  });

  group('HomeScreen', () {
    testWidgets('renders the 5 destinations and the dashboard with canned data', (tester) async {
      Session.instance.hydrate(Me.fromJson(FakePayloads.me()));
      ApiClient.instance = FakeApiClient.build({
        'GET /dashboard': (_) => FakeResponse(FakePayloads.dashboard()),
        'GET /notifications': (_) => FakeResponse(FakePayloads.notifications()),
        'GET /portions': (_) => FakeResponse(FakePayloads.portions()),
      });

      await tester.pumpWidget(wrap(const HomeScreen()));
      await tester.pumpAndSettle();

      expect(find.byType(NavigationBar), findsOneWidget);
      for (final label in ['Accueil', 'Repas', 'Stock', 'Sport', 'Plus']) {
        expect(find.text(label), findsWidgets, reason: 'destination $label');
      }
      expect(find.byType(DashboardScreen), findsOneWidget);
      expect(find.text('Bonjour Demo'), findsOneWidget);
      expect(find.textContaining('restantes'), findsOneWidget);
      expect(find.textContaining('1 345 kcal'), findsOneWidget);
      expect(find.text('Un peu plus de protéines'), findsOneWidget);
      expect(find.byType(FloatingActionButton), findsOneWidget);
      expect(find.byType(Drawer), findsNothing);
      expect(NotificationBell.unread.value, 3);
      expect(FakeApiClient.calls, contains('GET /dashboard'));
    });

    testWidgets('shows ErrorState with Réessayer when the dashboard fails to load', (tester) async {
      Session.instance.hydrate(Me.fromJson(FakePayloads.me()));
      ApiClient.instance = FakeApiClient.offline();

      await tester.pumpWidget(wrap(const HomeScreen()));
      await tester.pumpAndSettle();

      expect(find.byType(ErrorState), findsOneWidget);
      expect(find.text('Pas de connexion. Vérifie ton réseau puis réessaie.'), findsOneWidget);
      expect(find.text('Réessayer'), findsOneWidget);
    });

    testWidgets('has_profile == false shows the banner and opens Plus › Profil', (tester) async {
      Session.instance.hydrate(Me.fromJson(FakePayloads.me(hasProfile: false)));
      ApiClient.instance = FakeApiClient.build({
        'GET /dashboard': (_) => FakeResponse(FakePayloads.dashboard(hasProfile: false)),
        'GET /notifications': (_) => FakeResponse(FakePayloads.notifications()),
        'GET /portions': (_) => FakeResponse(FakePayloads.portions()),
        'GET /profile': (_) => const FakeResponse({'nom': 'Demo', 'has_profile': false}),
      });

      await tester.pumpWidget(wrap(const HomeScreen(initialTab: 4, initialPlusSlug: 'profil')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.byType(MaterialBanner), findsOneWidget);
      expect(find.text('Complète ton profil pour obtenir tes objectifs personnalisés.'), findsOneWidget);
      expect(find.widgetWithText(AppBar, 'Profil'), findsOneWidget);
      expect(find.byIcon(Icons.arrow_back_rounded), findsOneWidget);
      expect(find.byType(FloatingActionButton), findsNothing);
    });

    testWidgets('Plus tab shows the MenuScreen grid with the 13 sections', (tester) async {
      Session.instance.hydrate(Me.fromJson(FakePayloads.me()));
      ApiClient.instance = FakeApiClient.build({
        'GET /dashboard': (_) => FakeResponse(FakePayloads.dashboard()),
        'GET /notifications': (_) => FakeResponse(FakePayloads.notifications()),
        'GET /portions': (_) => FakeResponse(FakePayloads.portions()),
      });

      await tester.pumpWidget(wrap(const HomeScreen(initialTab: 4)));
      await tester.pumpAndSettle();

      expect(find.byType(MenuScreen), findsOneWidget);
      expect(find.text('Demo Mavioh'), findsOneWidget);
      final menuList = find.descendant(of: find.byType(MenuScreen), matching: find.byType(Scrollable)).first;
      for (final title in ['Recherche d’aliments', 'Recettes', 'Coach du jour', 'Régime reconnu', 'Famille', 'Profil', 'Paramètres']) {
        await tester.scrollUntilVisible(find.text(title), 200, scrollable: menuList);
        expect(find.text(title), findsWidgets, reason: title);
      }
      // The non-builder ListView over-estimates its extent; drag to the real end and settle.
      await tester.drag(menuList, const Offset(0, -3000));
      await tester.pumpAndSettle();
      expect(find.text('Déconnexion'), findsOneWidget);
      expect(find.textContaining('version'), findsOneWidget);
    });
  });
}
