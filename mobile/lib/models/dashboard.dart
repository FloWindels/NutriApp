import '../core/api_client.dart';
import 'meal.dart';
import 'recommendation.dart';
import 'weight.dart';

/// `meals:[{id, type, name, calories, items_count}]`.
class DashboardMeal {
  final int id;
  final String type;
  final String? name;
  final double calories;
  final int itemsCount;

  const DashboardMeal({required this.id, required this.type, this.name, this.calories = 0, this.itemsCount = 0});

  factory DashboardMeal.fromJson(Map<String, dynamic> json) => DashboardMeal(
        id: parseIntOr(json['id'], 0),
        type: parseString(json['type']) ?? 'dejeuner',
        name: parseString(json['name']),
        calories: parseNumOr(json['calories'], 0),
        itemsCount: parseIntOr(json['items_count'], 0),
      );
}

/// `stock.expiring[]` lite item `{id, label, expires_at, days_left, stock_name}`.
class DashboardStockItem {
  final int id;
  final String label;
  final DateTime? expiresAt;
  final int? daysLeft;
  final String? stockName;

  const DashboardStockItem({required this.id, required this.label, this.expiresAt, this.daysLeft, this.stockName});

  factory DashboardStockItem.fromJson(Map<String, dynamic> json) => DashboardStockItem(
        id: parseIntOr(json['id'], 0),
        label: parseString(json['label']) ?? parseString(json['food_name']) ?? 'Article',
        expiresAt: parseDate(json['expires_at']),
        daysLeft: parseInt(json['days_left']),
        stockName: parseString(json['stock_name']),
      );
}

/// `stock:{expiring_count, expired_count, low_count, expiring:[…]}`.
class DashboardStock {
  final int expiringCount;
  final int expiredCount;
  final int lowCount;
  final List<DashboardStockItem> expiring;

  const DashboardStock({this.expiringCount = 0, this.expiredCount = 0, this.lowCount = 0, this.expiring = const []});

  factory DashboardStock.fromJson(Map<String, dynamic> json) => DashboardStock(
        expiringCount: parseIntOr(json['expiring_count'], 0),
        expiredCount: parseIntOr(json['expired_count'], 0),
        lowCount: parseIntOr(json['low_count'], 0),
        expiring: ApiClient.asList(json['expiring']).map(DashboardStockItem.fromJson).toList(),
      );

  int get total => expiringCount + expiredCount + lowCount;

  bool get hasAlerts => total > 0;
}

/// `sessions_today[] / planned[]` lite session `{id,title,status,duration_min,calories_burned}`.
class DashboardSession {
  final int id;
  final String title;
  final String status;
  final int durationMin;
  final double? caloriesBurned;
  final String? sportName;
  final String? plannedAt;

  const DashboardSession({
    required this.id,
    required this.title,
    this.status = 'prevue',
    this.durationMin = 0,
    this.caloriesBurned,
    this.sportName,
    this.plannedAt,
  });

  factory DashboardSession.fromJson(Map<String, dynamic> json) => DashboardSession(
        id: parseIntOr(json['id'], 0),
        title: parseString(json['title']) ?? parseString(json['sport_name']) ?? 'Séance',
        status: parseString(json['status']) ?? 'prevue',
        durationMin: parseIntOr(json['duration_min'] ?? json['planned_duration_min'], 0),
        caloriesBurned: parseNum(json['calories_burned']),
        sportName: parseString(json['sport_name']),
        plannedAt: parseString(json['planned_at']),
      );
}

/// `sport:{sessions_today, calories_burned, planned, week_minutes, week_sessions, streak_days}`.
class DashboardSport {
  final List<DashboardSession> sessionsToday;
  final double caloriesBurned;
  final List<DashboardSession> planned;
  final int weekMinutes;
  final int weekSessions;
  final int streakDays;

  const DashboardSport({
    this.sessionsToday = const [],
    this.caloriesBurned = 0,
    this.planned = const [],
    this.weekMinutes = 0,
    this.weekSessions = 0,
    this.streakDays = 0,
  });

  factory DashboardSport.fromJson(Map<String, dynamic> json) => DashboardSport(
        sessionsToday: ApiClient.asList(json['sessions_today']).map(DashboardSession.fromJson).toList(),
        caloriesBurned: parseNumOr(json['calories_burned'], 0),
        planned: ApiClient.asList(json['planned']).map(DashboardSession.fromJson).toList(),
        weekMinutes: parseIntOr(json['week_minutes'], 0),
        weekSessions: parseIntOr(json['week_sessions'], 0),
        streakDays: parseIntOr(json['streak_days'], 0),
      );
}

/// `weight:{current, target, history:[…], variation_hebdo_kg}`.
class DashboardWeight {
  final double? current;
  final double? target;
  final List<WeightLog> history;
  final double? variationHebdoKg;

  const DashboardWeight({this.current, this.target, this.history = const [], this.variationHebdoKg});

  factory DashboardWeight.fromJson(Map<String, dynamic> json) => DashboardWeight(
        current: parseNum(json['current']),
        target: parseNum(json['target']),
        history: ApiClient.asList(json['history']).map(WeightLog.fromJson).toList(),
        variationHebdoKg: parseNum(json['variation_hebdo_kg']),
      );
}

/// `GET /dashboard?date=` → `data` (§7).
class Dashboard {
  final DateTime date;
  final String userName;
  final String firstName;
  final bool hasProfile;
  final Totals targets;
  final Totals consumed;
  final Totals remaining;
  final double progressPct;
  final double caloriesBonus;
  final double? plancherKcal;
  final bool isEstimate;
  final List<DashboardMeal> meals;
  final String? nextMealType;
  final DashboardStock stock;
  final DashboardSport sport;
  final List<Recommendation> recommendations;
  final DashboardWeight weight;
  final int notificationsUnread;

  const Dashboard({
    required this.date,
    this.userName = '',
    this.firstName = '',
    this.hasProfile = false,
    this.targets = const Totals(),
    this.consumed = const Totals(),
    this.remaining = const Totals(),
    this.progressPct = 0,
    this.caloriesBonus = 0,
    this.plancherKcal,
    this.isEstimate = true,
    this.meals = const [],
    this.nextMealType,
    this.stock = const DashboardStock(),
    this.sport = const DashboardSport(),
    this.recommendations = const [],
    this.weight = const DashboardWeight(),
    this.notificationsUnread = 0,
  });

  factory Dashboard.fromJson(Map<String, dynamic> json) {
    final user = ApiClient.asMap(json['user']);
    final name = parseString(user['name']) ?? '';
    return Dashboard(
      date: parseDate(json['date']) ?? DateTime.now(),
      userName: name,
      firstName: parseString(user['first_name']) ?? (name.isEmpty ? '' : name.split(' ').first),
      hasProfile: parseBool(json['has_profile']),
      targets: Totals.fromJson(ApiClient.asMap(json['targets'])),
      consumed: Totals.fromJson(ApiClient.asMap(json['consumed'])),
      remaining: Totals.fromJson(ApiClient.asMap(json['remaining'])),
      progressPct: parseNumOr(json['progress_pct'], 0),
      caloriesBonus: parseNumOr(json['calories_bonus'], 0),
      plancherKcal: parseNum(json['plancher_kcal']),
      isEstimate: parseBool(json['is_estimate'], fallback: true),
      meals: ApiClient.asList(json['meals']).map(DashboardMeal.fromJson).toList(),
      nextMealType: parseString(json['next_meal_type']),
      stock: DashboardStock.fromJson(ApiClient.asMap(json['stock'])),
      sport: DashboardSport.fromJson(ApiClient.asMap(json['sport'])),
      recommendations: ApiClient.asList(json['recommendations']).map(Recommendation.fromJson).toList(),
      weight: DashboardWeight.fromJson(ApiClient.asMap(json['weight'])),
      notificationsUnread: parseIntOr(json['notifications_unread'], 0),
    );
  }

  /// Meal for the next type (null when nothing logged yet).
  DashboardMeal? get nextMeal {
    if (nextMealType == null) return null;
    for (final m in meals) {
      if (m.type == nextMealType) return m;
    }
    return null;
  }

  bool get isOverBudget => remaining.calories < 0;
}
