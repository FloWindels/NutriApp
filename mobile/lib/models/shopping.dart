import '../core/api_client.dart';

/// Shopping list row (§11).
class ShoppingItem {
  final int id;
  final int? userId;
  final int? householdId;
  final int? foodId;
  final String label;
  final double? quantity;
  final String? unit;
  final bool checked;
  final String source; // manuel | auto_stock | planificateur | recommandation
  final DateTime? createdAt;
  final DateTime? updatedAt;

  const ShoppingItem({
    required this.id,
    this.userId,
    this.householdId,
    this.foodId,
    required this.label,
    this.quantity,
    this.unit,
    this.checked = false,
    this.source = 'manuel',
    this.createdAt,
    this.updatedAt,
  });

  factory ShoppingItem.fromJson(Map<String, dynamic> json) => ShoppingItem(
        id: parseIntOr(json['id'], 0),
        userId: parseInt(json['user_id']),
        householdId: parseInt(json['household_id']),
        foodId: parseInt(json['food_id']),
        label: parseString(json['label']) ?? 'Article',
        quantity: parseNum(json['quantity']),
        unit: parseString(json['unit']),
        checked: parseBool(json['checked']),
        source: parseString(json['source']) ?? 'manuel',
        createdAt: parseDate(json['created_at']),
        updatedAt: parseDate(json['updated_at']),
      );

  ShoppingItem copyWith({bool? checked, double? quantity, String? unit, String? label}) => ShoppingItem(
        id: id,
        userId: userId,
        householdId: householdId,
        foodId: foodId,
        label: label ?? this.label,
        quantity: quantity ?? this.quantity,
        unit: unit ?? this.unit,
        checked: checked ?? this.checked,
        source: source,
        createdAt: createdAt,
        updatedAt: updatedAt,
      );
}

/// `GET /shopping-list` → `{data, counts:{total, checked}}`.
class ShoppingList {
  final List<ShoppingItem> items;
  final int total;
  final int checkedCount;

  const ShoppingList({this.items = const [], this.total = 0, this.checkedCount = 0});

  factory ShoppingList.fromJson(Map<String, dynamic> json) {
    final items = ApiClient.asList(json['data']).map(ShoppingItem.fromJson).toList();
    final counts = ApiClient.asMap(json['counts']);
    return ShoppingList(
      items: items,
      total: parseIntOr(counts['total'], items.length),
      checkedCount: parseIntOr(counts['checked'], items.where((i) => i.checked).length),
    );
  }

  List<ShoppingItem> get unchecked => items.where((i) => !i.checked).toList();

  List<ShoppingItem> get checked => items.where((i) => i.checked).toList();
}

/// `POST /shopping-list/generate` response.
class ShoppingGenerateResult {
  final String? message;
  final ShoppingList list;
  final int addedCount;

  const ShoppingGenerateResult({this.message, required this.list, this.addedCount = 0});

  factory ShoppingGenerateResult.fromJson(Map<String, dynamic> json) => ShoppingGenerateResult(
        message: parseString(json['message']),
        list: ShoppingList.fromJson(json),
        addedCount: parseIntOr(json['added_count'], 0),
      );
}
