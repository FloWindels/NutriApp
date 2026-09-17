import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:mavioh/core/api_client.dart';
import 'package:mavioh/core/session.dart';
import 'package:mavioh/main.dart';
import 'package:mavioh/screens/stock_screen.dart';
import 'package:mavioh/widgets/empty_state.dart';
import 'package:mavioh/widgets/error_state.dart';
import 'package:mavioh/widgets/loading_state.dart';

import '../support/fake_api_client.dart';

Map<String, dynamic> _item({
  int id = 5,
  String name = 'Yaourt nature',
  Object quantity = '4',
  String unit = 'piece',
  bool depleted = false,
}) {
  return {
    'id': id,
    'stock_id': 1,
    'stock_name': 'Frigo',
    'food_id': 7,
    'food_name': name,
    'food_barcode': '3256540001008',
    'food_brand': 'Bio',
    'quantity': quantity,
    'unit': unit,
    'expires_at': '2026-09-20',
    'days_left': 3,
    'min_quantity': null,
    'opened_at': null,
    'depleted_at': null,
    'is_depleted': depleted,
    'expiry_kind': 'dlc',
    'expiry_status': 'bientot',
    'food': {
      'calories': 60,
      'proteins': 4,
      'carbs': 5,
      'fat': 3,
      'image_url': null,
      'serving_size_g': 125,
    },
    'household_id': null,
    'created_at': '2026-09-15T08:00:00.000000Z',
    'updated_at': '2026-09-16T08:00:00.000000Z',
  };
}

Map<String, dynamic> _payload({List<Map<String, dynamic>>? items}) => {
      'data': items ?? [_item()],
      'locations': [
        {'id': 1, 'name': 'Frigo', 'items_count': items?.length ?? 1},
        {'id': 2, 'name': 'Placard', 'items_count': 0},
      ],
      'alerts': {'expiring_count': 1, 'expired_count': 0, 'low_count': 0},
      'household_id': null,
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

  Widget wrap() => const MaviohApp(home: Scaffold(body: StockScreen()));

  testWidgets('shows the skeleton while /stocks is in flight', (tester) async {
    ApiClient.instance = FakeApiClient.build(
      {'GET /stocks': (_) => FakeResponse(_payload())},
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
    expect(find.text('Réessayer'), findsOneWidget);

    final before = FakeApiClient.calls.where((c) => c == 'GET /stocks').length;
    await tester.tap(find.text('Réessayer'));
    await tester.pumpAndSettle();

    expect(FakeApiClient.calls.where((c) => c == 'GET /stocks').length, greaterThan(before));
  });

  testWidgets('shows the empty state when the stock has no item', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /stocks': (_) => FakeResponse(_payload(items: const [])),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    expect(find.byType(EmptyState), findsOneWidget);
    expect(find.text('Ton stock est vide'), findsOneWidget);
  });

  testWidgets('renders items, locations and alert chips from the payload', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /stocks': (_) => FakeResponse(_payload(items: [_item(), _item(id: 6, name: 'Pain complet')])),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    expect(find.text('Yaourt nature'), findsOneWidget);
    expect(find.text('Pain complet'), findsOneWidget);
    expect(find.text('Frigo'), findsWidgets);
    expect(find.text('1 bientôt périmé'), findsOneWidget);
    expect(find.text('2 articles'), findsOneWidget);
  });

  testWidgets('expands the inline editor of an item', (tester) async {
    ApiClient.instance = FakeApiClient.build({
      'GET /stocks': (_) => FakeResponse(_payload()),
      'GET /portions': (_) => FakeResponse(FakePayloads.portions()),
    });

    await tester.pumpWidget(wrap());
    await tester.pumpAndSettle();

    expect(find.text('Mettre à jour'), findsNothing);

    await tester.tap(find.byTooltip('Modifier l’article'));
    await tester.pumpAndSettle();

    expect(find.text('Mettre à jour'), findsOneWidget);
    expect(find.text('Supprimer'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Quantité'), findsOneWidget);
  });
}
