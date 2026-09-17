import '../core/api_client.dart';

/// Food payload (§0.3 + §3.2 additions). Values are per 100 g/ml.
class Food {
  final int id;
  final String? barcode;
  final String name;
  final String? brand;
  final String? imageUrl;
  final double? calories;
  final double? fat;
  final double? carbs;
  final double? proteins;
  final double? fiber;
  final double? sugar;
  final double? salt;
  final double? servingSizeG;
  final String? servingLabel;
  final String? category;
  final List<String> allergens;
  final String perUnit; // 100g | 100ml
  final String sourceType; // manual | open_food_facts | recipe
  final int? createdByUserId;
  final bool isOwner;
  final bool isVerified;
  final bool isEstimate;
  final bool isFavorite;
  final DateTime? sourceFetchedAt;
  final DateTime? createdAt;
  final DateTime? updatedAt;

  const Food({
    required this.id,
    this.barcode,
    required this.name,
    this.brand,
    this.imageUrl,
    this.calories,
    this.fat,
    this.carbs,
    this.proteins,
    this.fiber,
    this.sugar,
    this.salt,
    this.servingSizeG,
    this.servingLabel,
    this.category,
    this.allergens = const [],
    this.perUnit = '100g',
    this.sourceType = 'manual',
    this.createdByUserId,
    this.isOwner = false,
    this.isVerified = false,
    this.isEstimate = false,
    this.isFavorite = false,
    this.sourceFetchedAt,
    this.createdAt,
    this.updatedAt,
  });

  factory Food.fromJson(Map<String, dynamic> json) => Food(
        id: parseIntOr(json['id'], 0),
        barcode: parseString(json['barcode']),
        name: parseString(json['name']) ?? 'Produit sans nom',
        brand: parseString(json['brand']),
        imageUrl: parseString(json['image_url']),
        calories: parseNum(json['calories']),
        fat: parseNum(json['fat']),
        carbs: parseNum(json['carbs']),
        proteins: parseNum(json['proteins']),
        fiber: parseNum(json['fiber']),
        sugar: parseNum(json['sugar']),
        salt: parseNum(json['salt']),
        servingSizeG: parseNum(json['serving_size_g']),
        servingLabel: parseString(json['serving_label']),
        category: parseString(json['category']),
        allergens: ApiClient.asStringList(json['allergens']),
        perUnit: parseString(json['per_unit']) ?? '100g',
        sourceType: parseString(json['source_type']) ?? 'manual',
        createdByUserId: parseInt(json['created_by_user_id']),
        isOwner: parseBool(json['is_owner']),
        isVerified: parseBool(json['is_verified']),
        isEstimate: parseBool(json['is_estimate']),
        isFavorite: parseBool(json['is_favorite']),
        sourceFetchedAt: parseDate(json['source_fetched_at']),
        createdAt: parseDate(json['created_at']),
        updatedAt: parseDate(json['updated_at']),
      );

  /// Body for `POST /foods` / `PUT /foods/{id}` (nulls dropped).
  Map<String, dynamic> toJson() {
    final map = <String, dynamic>{
      'barcode': barcode,
      'name': name,
      'brand': brand,
      'image_url': imageUrl,
      'calories': calories,
      'fat': fat,
      'carbs': carbs,
      'proteins': proteins,
      'fiber': fiber,
      'sugar': sugar,
      'salt': salt,
      'serving_size_g': servingSizeG,
      'serving_label': servingLabel,
      'category': category,
      'source_type': sourceType,
    };
    map.removeWhere((key, value) => value == null);
    return map;
  }

  bool get isFromOpenFoodFacts => sourceType == 'open_food_facts';

  bool get isLiquid => perUnit == '100ml';

  /// « Open Food Facts » / « Ajouté par la communauté » / « Vérifié ».
  String get sourceLabel {
    if (isVerified) return 'Vérifié';
    if (isFromOpenFoodFacts) return 'Open Food Facts';
    if (sourceType == 'recipe') return 'Recette';
    return 'Ajouté par la communauté';
  }

  Food copyWith({bool? isFavorite}) => Food(
        id: id,
        barcode: barcode,
        name: name,
        brand: brand,
        imageUrl: imageUrl,
        calories: calories,
        fat: fat,
        carbs: carbs,
        proteins: proteins,
        fiber: fiber,
        sugar: sugar,
        salt: salt,
        servingSizeG: servingSizeG,
        servingLabel: servingLabel,
        category: category,
        allergens: allergens,
        perUnit: perUnit,
        sourceType: sourceType,
        createdByUserId: createdByUserId,
        isOwner: isOwner,
        isVerified: isVerified,
        isEstimate: isEstimate,
        isFavorite: isFavorite ?? this.isFavorite,
        sourceFetchedAt: sourceFetchedAt,
        createdAt: createdAt,
        updatedAt: updatedAt,
      );
}

/// Pagination meta `{current_page, last_page, per_page, total, off_queried?}`.
class PageMeta {
  final int currentPage;
  final int lastPage;
  final int perPage;
  final int total;
  final bool offQueried;

  const PageMeta({
    this.currentPage = 1,
    this.lastPage = 1,
    this.perPage = 20,
    this.total = 0,
    this.offQueried = false,
  });

  factory PageMeta.fromJson(Map<String, dynamic> json) => PageMeta(
        currentPage: parseIntOr(json['current_page'], 1),
        lastPage: parseIntOr(json['last_page'], 1),
        perPage: parseIntOr(json['per_page'], 20),
        total: parseIntOr(json['total'], 0),
        offQueried: parseBool(json['off_queried']),
      );

  bool get hasMore => currentPage < lastPage;
}

/// A page of results with optional meta.
class Paged<T> {
  final List<T> items;
  final PageMeta meta;

  const Paged({required this.items, this.meta = const PageMeta()});

  bool get isEmpty => items.isEmpty;
}
