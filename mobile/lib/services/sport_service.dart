import 'package:dio/dio.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../models/food.dart';
import '../models/sport.dart';

/// Sport module (§13 + addendum §C).
class SportService {
  SportService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  // ----- C.1 Sports catalog & config ---------------------------------------

  /// `GET /sport/sports?q=&category=`.
  Future<List<Sport>> sports({String? q, String? category}) async {
    final json = await _api.getJson('/sport/sports', query: {
      if (q != null && q.trim().isNotEmpty) 'q': q.trim(),
      'category': category,
    });
    return ApiClient.asList(json['data']).map(Sport.fromJson).toList();
  }

  /// `POST /sport/sports {name, category, met_moderee?}`.
  Future<Sport> createSport({required String name, required String category, double? metModeree}) async {
    final json = await _api.postJson('/sport/sports', body: {
      'name': name.trim(),
      'category': category,
      'met_moderee': ?metModeree,
    });
    return Sport.fromJson(ApiClient.asMap(json['data']));
  }

  /// `PUT /sport/sports/{id}` (own custom only).
  Future<Sport> updateSport(int id, Map<String, dynamic> body) async {
    final json = await _api.putJson('/sport/sports/$id', body: body);
    return Sport.fromJson(ApiClient.asMap(json['data']));
  }

  /// `DELETE /sport/sports/{id}` (own custom only; 422 when used).
  Future<void> deleteSport(int id) => _api.deleteJson('/sport/sports/$id');

  /// `GET /sport/config`.
  Future<SportConfig> config() async {
    final json = await _api.getJson('/sport/config');
    return SportConfig.fromJson(ApiClient.asMap(json['data']));
  }

  /// `GET /sport/exercises?equipment=&muscle=&level=&category=&q=&page=` (public).
  Future<Paged<Exercise>> exercises({
    String? equipment,
    String? muscle,
    String? level,
    String? category,
    String? q,
    int page = 1,
  }) async {
    final json = await _api.getJson('/sport/exercises', query: {
      'equipment': equipment,
      'muscle': muscle,
      'level': level,
      'category': category,
      if (q != null && q.trim().isNotEmpty) 'q': q.trim(),
      'page': page,
    });
    return Paged(
      items: ApiClient.asList(json['data']).map(Exercise.fromJson).toList(),
      meta: PageMeta.fromJson(ApiClient.asMap(json['meta'])),
    );
  }

  // ----- Summary ------------------------------------------------------------

  /// `GET /sport/summary?date=`.
  Future<SportSummary> summary({DateTime? date}) async {
    final json = await _api.getJson('/sport/summary', query: {if (date != null) 'date': isoDate(date)});
    return SportSummary.fromJson(ApiClient.asMap(json['data']));
  }

  // ----- C.2 Calendar -------------------------------------------------------

  /// `GET /sport/calendar?from=&to=` (max 62 days).
  Future<SportCalendar> calendar({required DateTime from, required DateTime to}) async {
    final json = await _api.getJson('/sport/calendar', query: {'from': isoDate(from), 'to': isoDate(to)});
    return SportCalendar.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /sport/calendar {date, sport_id?|sport_name, planned_duration_min, planned_at?, lieu?, notes?}`.
  Future<SportPlan> createPlan({
    required DateTime date,
    int? sportId,
    String? sportName,
    required int plannedDurationMin,
    String? plannedAt,
    String? lieu,
    String? notes,
  }) async {
    final json = await _api.postJson('/sport/calendar', body: {
      'date': isoDate(date),
      'sport_id': ?sportId,
      if (sportName != null && sportName.trim().isNotEmpty) 'sport_name': sportName.trim(),
      'planned_duration_min': plannedDurationMin,
      'planned_at': ?plannedAt,
      'lieu': ?lieu,
      if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
    });
    return SportPlan.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /sport/calendar/recurring`.
  Future<RecurringPlansResult> createRecurringPlan({
    required int weekday,
    int? sportId,
    String? sportName,
    required int plannedDurationMin,
    String? plannedAt,
    String? lieu,
    String? notes,
    required int weeks,
    DateTime? startDate,
  }) async {
    final json = await _api.postJson('/sport/calendar/recurring', body: {
      'weekday': weekday,
      'sport_id': ?sportId,
      if (sportName != null && sportName.trim().isNotEmpty) 'sport_name': sportName.trim(),
      'planned_duration_min': plannedDurationMin,
      'planned_at': ?plannedAt,
      'lieu': ?lieu,
      if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
      'weeks': weeks,
      if (startDate != null) 'start_date': isoDate(startDate),
    });
    return RecurringPlansResult.fromJson(json);
  }

  /// `PUT /sport/calendar/{id}`.
  Future<SportPlan> updatePlan(int id, Map<String, dynamic> body) async {
    final json = await _api.putJson('/sport/calendar/$id', body: body);
    return SportPlan.fromJson(ApiClient.asMap(json['data']));
  }

  /// `DELETE /sport/calendar/{id}?serie=1`.
  Future<void> deletePlan(int id, {bool serie = false}) =>
      _api.deleteJson('/sport/calendar/$id', query: {if (serie) 'serie': 1});

  /// `POST /sport/calendar/{id}/log`.
  Future<PlanLogResult> logPlan(
    int id, {
    required int durationMin,
    String intensity = 'moderee',
    double? distanceKm,
    double? caloriesBurned,
    int? rpe,
    String? notes,
  }) async {
    final json = await _api.postJson('/sport/calendar/$id/log', body: {
      'duration_min': durationMin,
      'intensity': intensity,
      'distance_km': ?distanceKm,
      'calories_burned': ?caloriesBurned,
      'rpe': ?rpe,
      if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
    });
    return PlanLogResult.fromJson(json);
  }

  /// `POST /sport/calendar/{id}/propose {mode?, overrides…}` (may take 20–60 s with IA).
  Future<SessionProposal> proposeForPlan(int id, {Map<String, dynamic> overrides = const {}, CancelToken? cancelToken}) async {
    final json = await _api.postJson(
      '/sport/calendar/$id/propose',
      body: overrides,
      cancelToken: cancelToken,
      receiveTimeout: const Duration(seconds: 90),
    );
    return SessionProposal.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /sport/calendar/plan-week {week_start, days?, mode?, replace?}`.
  Future<SportCalendar> planWeek({
    required DateTime weekStart,
    List<int>? days,
    String? mode,
    bool replace = false,
    CancelToken? cancelToken,
  }) async {
    final json = await _api.postJson(
      '/sport/calendar/plan-week',
      body: {
        'week_start': isoDate(weekStart),
        'days': ?days,
        'mode': ?mode,
        'replace': replace,
      },
      cancelToken: cancelToken,
      receiveTimeout: const Duration(seconds: 90),
    );
    return SportCalendar.fromJson(ApiClient.asMap(json['data']), generatedBy: parseString(json['generated_by']));
  }

  // ----- C.3 Activities & sessions -----------------------------------------

  /// `POST /sport/activities`.
  Future<ActivityResult> logActivity({
    DateTime? date,
    int? sportId,
    String? sportName,
    required int durationMin,
    String intensity = 'moderee',
    double? distanceKm,
    double? caloriesBurned,
    String? notes,
    int? sportPlanId,
  }) async {
    final json = await _api.postJson('/sport/activities', body: {
      if (date != null) 'date': isoDate(date),
      'sport_id': ?sportId,
      if (sportName != null && sportName.trim().isNotEmpty) 'sport_name': sportName.trim(),
      'duration_min': durationMin,
      'intensity': intensity,
      'distance_km': ?distanceKm,
      'calories_burned': ?caloriesBurned,
      if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
      'sport_plan_id': ?sportPlanId,
    });
    return ActivityResult.fromJson(json);
  }

  /// `POST /sport/calories/estimate {sport_id?|sport_name?, duration_min, intensity?, met?}`.
  Future<CaloriesEstimate> estimateCalories({
    int? sportId,
    String? sportName,
    required int durationMin,
    String? intensity,
    double? met,
  }) async {
    final json = await _api.postJson('/sport/calories/estimate', body: {
      'sport_id': ?sportId,
      if (sportName != null && sportName.trim().isNotEmpty) 'sport_name': sportName.trim(),
      'duration_min': durationMin,
      'intensity': ?intensity,
      'met': ?met,
    });
    return CaloriesEstimate.fromJson(ApiClient.asMap(json['data']));
  }

  /// `GET /sport/sessions?from=&to=&status=`.
  Future<List<WorkoutSession>> sessions({DateTime? from, DateTime? to, String? status}) async {
    final json = await _api.getJson('/sport/sessions', query: {
      if (from != null) 'from': isoDate(from),
      if (to != null) 'to': isoDate(to),
      'status': status,
    });
    return ApiClient.asList(json['data']).map(WorkoutSession.fromJson).toList();
  }

  /// `GET /sport/sessions/{id}`.
  Future<WorkoutSession> session(int id) async {
    final json = await _api.getJson('/sport/sessions/$id');
    return WorkoutSession.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /sport/sessions/generate` (IA may take 20–60 s; cancellable).
  Future<SessionProposal> generate(Map<String, dynamic> body, {CancelToken? cancelToken}) async {
    final json = await _api.postJson(
      '/sport/sessions/generate',
      body: body,
      cancelToken: cancelToken,
      receiveTimeout: const Duration(seconds: 90),
    );
    return SessionProposal.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /sport/sessions` → 201.
  Future<WorkoutSession> createSession(Map<String, dynamic> body) async {
    final json = await _api.postJson('/sport/sessions', body: body);
    return WorkoutSession.fromJson(ApiClient.asMap(json['data']));
  }

  /// `PUT /sport/sessions/{id}`.
  Future<WorkoutSession> updateSession(int id, Map<String, dynamic> body) async {
    final json = await _api.putJson('/sport/sessions/$id', body: body);
    return WorkoutSession.fromJson(ApiClient.asMap(json['data']));
  }

  /// `PUT /sport/sessions/{id} {calories_burned}` — null switches back to auto.
  Future<WorkoutSession> setCalories(int id, double? caloriesBurned) =>
      updateSession(id, {'calories_burned': caloriesBurned});

  /// `POST /sport/sessions/{id}/start`.
  Future<WorkoutSession> start(int id) async {
    final json = await _api.postJson('/sport/sessions/$id/start');
    return WorkoutSession.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /sport/sessions/{id}/complete {duration_min?, rpe?, exercises:[{id, completed, sets?, reps?, weight_kg?}]}`.
  Future<SessionCompleteResult> complete(
    int id, {
    int? durationMin,
    int? rpe,
    List<Map<String, dynamic>> exercises = const [],
  }) async {
    final json = await _api.postJson('/sport/sessions/$id/complete', body: {
      'duration_min': ?durationMin,
      'rpe': ?rpe,
      if (exercises.isNotEmpty) 'exercises': exercises,
    });
    return SessionCompleteResult.fromJson(json);
  }

  /// `POST /sport/sessions/{id}/cancel`.
  Future<WorkoutSession> cancel(int id) async {
    final json = await _api.postJson('/sport/sessions/$id/cancel');
    return WorkoutSession.fromJson(ApiClient.asMap(json['data']));
  }

  /// `DELETE /sport/sessions/{id}`.
  Future<void> deleteSession(int id) => _api.deleteJson('/sport/sessions/$id');
}
