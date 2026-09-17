import '../core/api_client.dart';
import 'recipe.dart';

/// `members:[{user_id, name, role, share_profile, calories_cibles|null, regime|null, joined_at}]`.
class HouseholdMember {
  final int userId;
  final String name;
  final String role; // proprietaire | membre
  final bool shareProfile;
  final double? caloriesCibles;
  final String? regime;
  final DateTime? joinedAt;

  const HouseholdMember({
    required this.userId,
    required this.name,
    this.role = 'membre',
    this.shareProfile = true,
    this.caloriesCibles,
    this.regime,
    this.joinedAt,
  });

  factory HouseholdMember.fromJson(Map<String, dynamic> json) => HouseholdMember(
        userId: parseIntOr(json['user_id'] ?? json['id'], 0),
        name: parseString(json['name']) ?? 'Membre',
        role: parseString(json['role']) ?? 'membre',
        shareProfile: parseBool(json['share_profile'], fallback: true),
        caloriesCibles: parseNum(json['calories_cibles']),
        regime: parseString(json['regime']),
        joinedAt: parseDate(json['joined_at']),
      );

  bool get isOwner => role == 'proprietaire';

  String get roleLabel => isOwner ? 'Propriétaire' : 'Membre';
}

/// `GET /household` → `data` (null when no household).
class Household {
  final int id;
  final String name;
  final String? inviteCode; // owner only
  final String role;
  final List<HouseholdMember> members;
  final int stockItemsCount;

  const Household({
    required this.id,
    required this.name,
    this.inviteCode,
    this.role = 'membre',
    this.members = const [],
    this.stockItemsCount = 0,
  });

  factory Household.fromJson(Map<String, dynamic> json) => Household(
        id: parseIntOr(json['id'], 0),
        name: parseString(json['name']) ?? 'Foyer',
        inviteCode: parseString(json['invite_code']),
        role: parseString(json['role']) ?? 'membre',
        members: ApiClient.asList(json['members']).map(HouseholdMember.fromJson).toList(),
        stockItemsCount: parseIntOr(json['stock_items_count'], 0),
      );

  bool get isOwner => role == 'proprietaire';

  HouseholdMember? member(int userId) {
    for (final m in members) {
      if (m.userId == userId) return m;
    }
    return null;
  }
}

/// `GET /household/preview?invite_code=` → `{name, members_count}`.
class HouseholdPreview {
  final String name;
  final int membersCount;

  const HouseholdPreview({required this.name, this.membersCount = 0});

  factory HouseholdPreview.fromJson(Map<String, dynamic> json) => HouseholdPreview(
        name: parseString(json['name']) ?? 'Foyer',
        membersCount: parseIntOr(json['members_count'], 0),
      );
}

/// `POST /household/join` response.
class HouseholdJoinResult {
  final String? message;
  final Household household;
  final int mergedStockItems;

  const HouseholdJoinResult({this.message, required this.household, this.mergedStockItems = 0});

  factory HouseholdJoinResult.fromJson(Map<String, dynamic> json) => HouseholdJoinResult(
        message: parseString(json['message']),
        household: Household.fromJson(ApiClient.asMap(json['data'])),
        mergedStockItems: parseIntOr(json['merged_stock_items'], 0),
      );
}

/// One member row of a common-meal preview.
class CommonMealMember {
  final int userId;
  final String name;
  final double? targetKcal;
  final double portions;
  final double? calories;
  final bool isEstimate;
  final String? label;

  const CommonMealMember({
    required this.userId,
    required this.name,
    this.targetKcal,
    this.portions = 1,
    this.calories,
    this.isEstimate = true,
    this.label,
  });

  factory CommonMealMember.fromJson(Map<String, dynamic> json) => CommonMealMember(
        userId: parseIntOr(json['user_id'], 0),
        name: parseString(json['name']) ?? 'Membre',
        targetKcal: parseNum(json['target_kcal']),
        portions: parseNumOr(json['portions'], 1),
        calories: parseNum(json['calories']),
        isEstimate: parseBool(json['is_estimate'], fallback: true),
        label: parseString(json['label']),
      );

  CommonMealMember copyWith({double? portions, double? calories}) => CommonMealMember(
        userId: userId,
        name: name,
        targetKcal: targetKcal,
        portions: portions ?? this.portions,
        calories: calories ?? this.calories,
        isEstimate: isEstimate,
        label: label,
      );
}

/// `POST /household/common-meal/preview` → `{recipe:{id,title,per_serving}, members:[…]}`.
class CommonMealPreview {
  final int recipeId;
  final String recipeTitle;
  final PerServing perServing;
  final List<CommonMealMember> members;

  const CommonMealPreview({
    required this.recipeId,
    required this.recipeTitle,
    this.perServing = const PerServing(),
    this.members = const [],
  });

  factory CommonMealPreview.fromJson(Map<String, dynamic> json) {
    final recipe = ApiClient.asMap(json['recipe']);
    return CommonMealPreview(
      recipeId: parseIntOr(recipe['id'], 0),
      recipeTitle: parseString(recipe['title']) ?? 'Recette',
      perServing: PerServing.fromJson(ApiClient.asMap(recipe['per_serving'])),
      members: ApiClient.asList(json['members']).map(CommonMealMember.fromJson).toList(),
    );
  }
}

/// `POST /household/common-meal` → `{created:[{user_id, meal_id}]}`.
class CommonMealCreated {
  final int userId;
  final int mealId;

  const CommonMealCreated({required this.userId, required this.mealId});

  factory CommonMealCreated.fromJson(Map<String, dynamic> json) => CommonMealCreated(
        userId: parseIntOr(json['user_id'], 0),
        mealId: parseIntOr(json['meal_id'], 0),
      );
}
