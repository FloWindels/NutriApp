import '../core/api_client.dart';

/// `locations:[{id, name, items_count}]`.
class StockLocation {
  final int id;
  final String name;
  final int itemsCount;

  const StockLocation({required this.id, required this.name, this.itemsCount = 0});

  factory StockLocation.fromJson(Map<String, dynamic> json) => StockLocation(
        id: parseIntOr(json['id'], 0),
        name: parseString(json['name']) ?? 'Lieu',
        itemsCount: parseIntOr(json['items_count'], 0),
      );

  Map<String, dynamic> toJson() => {'id': id, 'name': name, 'items_count': itemsCount};
}

/// Nutrition snapshot embedded in a stock item (`food:{…}|null`).
class StockItemFood {
  final double? calories;
  final double? proteins;
  final double? carbs;
  final double? fat;
  final String? imageUrl;
  final double? servingSizeG;

  const StockItemFood({this.calories, this.proteins, this.carbs, this.fat, this.imageUrl, this.servingSizeG});

  factory StockItemFood.fromJson(Map<String, dynamic> json) => StockItemFood(
        calories: parseNum(json['calories']),
        proteins: parseNum(json['proteins']),
        carbs: parseNum(json['carbs']),
        fat: parseNum(json['fat']),
        imageUrl: parseString(json['image_url']),
        servingSizeG: parseNum(json['serving_size_g']),
      );
}

/// Stock item (§0.3 + §6.2 additions).
class StockItem {
  final int id;
  final int stockId;
  final String stockName;
  final int? foodId;
  final String foodName;
  final String? foodBarcode;
  final String? foodBrand;
  final double quantity;
  final String unit;
  final DateTime? expiresAt;
  final int? daysLeft;
  final double? minQuantity;
  final DateTime? openedAt;
  final DateTime? depletedAt;
  final bool isDepleted;
  final String expiryKind; // dlc | ddm
  final String expiryStatus; // ok|bientot|aujourdhui|perime|ddm_depassee|inconnu
  final StockItemFood? food;
  final int? householdId;
  final DateTime? createdAt;
  final DateTime? updatedAt;

  const StockItem({
    required this.id,
    required this.stockId,
    required this.stockName,
    this.foodId,
    required this.foodName,
    this.foodBarcode,
    this.foodBrand,
    this.quantity = 0,
    this.unit = 'g',
    this.expiresAt,
    this.daysLeft,
    this.minQuantity,
    this.openedAt,
    this.depletedAt,
    this.isDepleted = false,
    this.expiryKind = 'dlc',
    this.expiryStatus = 'inconnu',
    this.food,
    this.householdId,
    this.createdAt,
    this.updatedAt,
  });

  factory StockItem.fromJson(Map<String, dynamic> json) {
    final quantity = parseNumOr(json['quantity'], 0);
    final daysLeft = parseInt(json['days_left']);
    final expiryKind = parseString(json['expiry_kind']) ?? 'dlc';
    return StockItem(
      id: parseIntOr(json['id'], 0),
      stockId: parseIntOr(json['stock_id'], 0),
      stockName: parseString(json['stock_name']) ?? '',
      foodId: parseInt(json['food_id']),
      foodName: parseString(json['food_name']) ?? parseString(json['label']) ?? 'Article',
      foodBarcode: parseString(json['food_barcode']),
      foodBrand: parseString(json['food_brand']),
      quantity: quantity,
      unit: parseString(json['unit']) ?? 'g',
      expiresAt: parseDate(json['expires_at']),
      daysLeft: daysLeft,
      minQuantity: parseNum(json['min_quantity']),
      openedAt: parseDate(json['opened_at']),
      depletedAt: parseDate(json['depleted_at']),
      isDepleted: parseBool(json['is_depleted'], fallback: quantity <= 0),
      expiryKind: expiryKind,
      expiryStatus: parseString(json['expiry_status']) ?? _deriveStatus(daysLeft, expiryKind),
      food: json['food'] is Map ? StockItemFood.fromJson(ApiClient.asMap(json['food'])) : null,
      householdId: parseInt(json['household_id']),
      createdAt: parseDate(json['created_at']),
      updatedAt: parseDate(json['updated_at']),
    );
  }

  static String _deriveStatus(int? daysLeft, String kind) {
    if (daysLeft == null) return 'inconnu';
    if (daysLeft < 0) return kind == 'ddm' ? 'ddm_depassee' : 'perime';
    if (daysLeft == 0) return 'aujourdhui';
    if (daysLeft <= 3) return 'bientot';
    return 'ok';
  }

  bool get isExpired => expiryStatus == 'perime';

  bool get isLow => minQuantity != null && quantity <= minQuantity!;

  /// Label used in meals / lists.
  String get label => foodName;
}

/// `alerts:{expiring_count, expired_count, low_count}`.
class StockAlerts {
  final int expiringCount;
  final int expiredCount;
  final int lowCount;

  const StockAlerts({this.expiringCount = 0, this.expiredCount = 0, this.lowCount = 0});

  factory StockAlerts.fromJson(Map<String, dynamic> json) => StockAlerts(
        expiringCount: parseIntOr(json['expiring_count'], 0),
        expiredCount: parseIntOr(json['expired_count'], 0),
        lowCount: parseIntOr(json['low_count'], 0),
      );

  int get total => expiringCount + expiredCount + lowCount;

  bool get isEmpty => total == 0;
}

/// `GET /stocks` payload.
class StockPayload {
  final List<StockItem> items;
  final List<StockLocation> locations;
  final StockAlerts alerts;
  final int? householdId;

  const StockPayload({
    this.items = const [],
    this.locations = const [],
    this.alerts = const StockAlerts(),
    this.householdId,
  });

  factory StockPayload.fromJson(Map<String, dynamic> json) => StockPayload(
        items: ApiClient.asList(json['data']).map(StockItem.fromJson).toList(),
        locations: ApiClient.asList(json['locations']).map(StockLocation.fromJson).toList(),
        alerts: StockAlerts.fromJson(ApiClient.asMap(json['alerts'])),
        householdId: parseInt(json['household_id']),
      );
}

/// `GET /stocks/alerts` → `{expiring, expired, low}`.
class StockAlertLists {
  final List<StockItem> expiring;
  final List<StockItem> expired;
  final List<StockItem> low;

  const StockAlertLists({this.expiring = const [], this.expired = const [], this.low = const []});

  factory StockAlertLists.fromJson(Map<String, dynamic> json) => StockAlertLists(
        expiring: ApiClient.asList(json['expiring']).map(StockItem.fromJson).toList(),
        expired: ApiClient.asList(json['expired']).map(StockItem.fromJson).toList(),
        low: ApiClient.asList(json['low']).map(StockItem.fromJson).toList(),
      );
}

/// `stock_decrement:{stock_item_id, previous_quantity, new_quantity, unit, depleted}`.
class StockDecrement {
  final int stockItemId;
  final double previousQuantity;
  final double newQuantity;
  final String unit;
  final bool depleted;

  const StockDecrement({
    required this.stockItemId,
    required this.previousQuantity,
    required this.newQuantity,
    required this.unit,
    this.depleted = false,
  });

  factory StockDecrement.fromJson(Map<String, dynamic> json) => StockDecrement(
        stockItemId: parseIntOr(json['stock_item_id'] ?? json['id'], 0),
        previousQuantity: parseNumOr(json['previous_quantity'], 0),
        newQuantity: parseNumOr(json['new_quantity'] ?? json['quantity'], 0),
        unit: parseString(json['unit']) ?? '',
        depleted: parseBool(json['depleted']),
      );
}

/// `POST /stocks/items/{item}/consume` response `data`.
class ConsumeResult {
  final int? mealItemId;
  final int? mealId;
  final double? mealItemCalories;
  final StockDecrement stockItem;

  const ConsumeResult({this.mealItemId, this.mealId, this.mealItemCalories, required this.stockItem});

  factory ConsumeResult.fromJson(Map<String, dynamic> json) {
    final mealItem = json['meal_item'] is Map ? ApiClient.asMap(json['meal_item']) : null;
    return ConsumeResult(
      mealItemId: mealItem == null ? null : parseInt(mealItem['id']),
      mealId: mealItem == null ? null : parseInt(mealItem['meal_id']),
      mealItemCalories: mealItem == null ? null : parseNum(mealItem['calories']),
      stockItem: StockDecrement.fromJson(ApiClient.asMap(json['stock_item'])),
    );
  }
}
