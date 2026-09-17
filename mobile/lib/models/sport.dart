import '../core/api_client.dart';
import '../core/strings.dart';
import 'meal.dart';

/// Catalog exercise (§13.1).
class Exercise {
  final int id;
  final String name;
  final String slug;
  final String category; // force|cardio|mobilite|gainage
  final String muscleGroup;
  final String equipment;
  final String level;
  final double met;
  final int? defaultSets;
  final int? defaultReps;
  final int? defaultDurationSec;
  final String instructions;
  final List<String> contraindications;
  final bool isPublic;

  const Exercise({
    required this.id,
    required this.name,
    this.slug = '',
    this.category = 'force',
    this.muscleGroup = 'corps_entier',
    this.equipment = 'aucun',
    this.level = 'debutant',
    this.met = 3.5,
    this.defaultSets,
    this.defaultReps,
    this.defaultDurationSec,
    this.instructions = '',
    this.contraindications = const [],
    this.isPublic = true,
  });

  factory Exercise.fromJson(Map<String, dynamic> json) => Exercise(
        id: parseIntOr(json['id'] ?? json['exercise_id'], 0),
        name: parseString(json['name']) ?? 'Exercice',
        slug: parseString(json['slug']) ?? '',
        category: parseString(json['category']) ?? 'force',
        muscleGroup: parseString(json['muscle_group']) ?? 'corps_entier',
        equipment: parseString(json['equipment']) ?? 'aucun',
        level: parseString(json['level']) ?? 'debutant',
        met: parseNumOr(json['met'], 3.5),
        defaultSets: parseInt(json['default_sets']),
        defaultReps: parseInt(json['default_reps']),
        defaultDurationSec: parseInt(json['default_duration_sec']),
        instructions: parseString(json['instructions']) ?? '',
        contraindications: ApiClient.asStringList(json['contraindications']),
        isPublic: parseBool(json['is_public'], fallback: true),
      );
}

/// Sport catalog entry (addendum §A.1 / C.1).
class Sport {
  final int id;
  final String name;
  final String slug;
  final String category;
  final double metFaible;
  final double metModeree;
  final double metElevee;
  final String? icon;
  final bool isPublic;
  final bool isMine;

  const Sport({
    required this.id,
    required this.name,
    this.slug = '',
    this.category = 'autre',
    this.metFaible = 4,
    this.metModeree = 6,
    this.metElevee = 8,
    this.icon,
    this.isPublic = true,
    this.isMine = false,
  });

  factory Sport.fromJson(Map<String, dynamic> json) => Sport(
        id: parseIntOr(json['id'], 0),
        name: parseString(json['name']) ?? 'Sport',
        slug: parseString(json['slug']) ?? '',
        category: parseString(json['category']) ?? 'autre',
        metFaible: parseNumOr(json['met_faible'], 4),
        metModeree: parseNumOr(json['met_moderee'], 6),
        metElevee: parseNumOr(json['met_elevee'], 8),
        icon: parseString(json['icon']),
        isPublic: parseBool(json['is_public'], fallback: true),
        isMine: parseBool(json['is_mine']),
      );

  double metFor(String intensity) {
    switch (intensity) {
      case 'faible':
        return metFaible;
      case 'elevee':
        return metElevee;
      default:
        return metModeree;
    }
  }
}

/// `sport_plans` row (addendum §A.3).
class SportPlan {
  final int id;
  final DateTime date;
  final int? sportId;
  final String sportName;
  final String? sportIcon;
  final int plannedDurationMin;
  final String? plannedAt; // HH:mm
  final String? lieu;
  final String? notes;
  final String status; // prevu | realise | annule
  final int? sessionId;
  final String? recurrenceId;

  const SportPlan({
    required this.id,
    required this.date,
    this.sportId,
    required this.sportName,
    this.sportIcon,
    this.plannedDurationMin = 30,
    this.plannedAt,
    this.lieu,
    this.notes,
    this.status = 'prevu',
    this.sessionId,
    this.recurrenceId,
  });

  factory SportPlan.fromJson(Map<String, dynamic> json) {
    final sport = json['sport'] is Map ? ApiClient.asMap(json['sport']) : const <String, dynamic>{};
    return SportPlan(
      id: parseIntOr(json['id'], 0),
      date: parseDate(json['date']) ?? DateTime.now(),
      sportId: parseInt(json['sport_id'] ?? sport['id']),
      sportName: parseString(json['sport_name']) ?? parseString(sport['name']) ?? 'Sport',
      sportIcon: parseString(json['sport_icon'] ?? sport['icon']),
      plannedDurationMin: parseIntOr(json['planned_duration_min'], 30),
      plannedAt: _time(json['planned_at']),
      lieu: parseString(json['lieu']),
      notes: parseString(json['notes']),
      status: parseString(json['status']) ?? 'prevu',
      sessionId: parseInt(json['session_id']),
      recurrenceId: parseString(json['recurrence_id']),
    );
  }

  bool get isDone => status == 'realise';

  bool get isCancelled => status == 'annule';

  bool get isRecurring => recurrenceId != null;
}

String? _time(dynamic value) {
  final text = parseString(value);
  if (text == null) return null;
  final parts = text.split(':');
  if (parts.length < 2) return text;
  return '${parts[0].padLeft(2, '0')}:${parts[1].padLeft(2, '0')}';
}

/// `workout_exercises` row embedded in a session.
class WorkoutExercise {
  final int id;
  final int? exerciseId;
  final String block; // echauffement|principal|retour_au_calme
  final int position;
  final String name;
  final int? sets;
  final int? reps;
  final int? durationSec;
  final double? weightKg;
  final int? restSec;
  final double? met;
  final bool completed;
  final String? instructions;
  final String? notes;

  const WorkoutExercise({
    required this.id,
    this.exerciseId,
    this.block = 'principal',
    this.position = 0,
    required this.name,
    this.sets,
    this.reps,
    this.durationSec,
    this.weightKg,
    this.restSec,
    this.met,
    this.completed = false,
    this.instructions,
    this.notes,
  });

  factory WorkoutExercise.fromJson(Map<String, dynamic> json) => WorkoutExercise(
        id: parseIntOr(json['id'], 0),
        exerciseId: parseInt(json['exercise_id']),
        block: parseString(json['block']) ?? 'principal',
        position: parseIntOr(json['position'], 0),
        name: parseString(json['name']) ?? 'Exercice',
        sets: parseInt(json['sets']),
        reps: parseInt(json['reps']),
        durationSec: parseInt(json['duration_sec']),
        weightKg: parseNum(json['weight_kg']),
        restSec: parseInt(json['rest_sec']),
        met: parseNum(json['met']),
        completed: parseBool(json['completed']),
        instructions: parseString(json['instructions']),
        notes: parseString(json['notes']),
      );

  Map<String, dynamic> toJson() {
    final map = <String, dynamic>{
      'exercise_id': exerciseId,
      'name': name,
      'block': block,
      'sets': sets,
      'reps': reps,
      'duration_sec': durationSec,
      'weight_kg': weightKg,
      'rest_sec': restSec,
    };
    map.removeWhere((k, v) => v == null);
    return map;
  }

  WorkoutExercise copyWith({bool? completed, int? sets, int? reps, double? weightKg}) => WorkoutExercise(
        id: id,
        exerciseId: exerciseId,
        block: block,
        position: position,
        name: name,
        sets: sets ?? this.sets,
        reps: reps ?? this.reps,
        durationSec: durationSec,
        weightKg: weightKg ?? this.weightKg,
        restSec: restSec,
        met: met,
        completed: completed ?? this.completed,
        instructions: instructions,
        notes: notes,
      );

  /// « 3 × 12 » / « 45 s » summary.
  String get prescription {
    if (sets != null && reps != null) return '$sets × $reps';
    if (durationSec != null) return '$durationSec s';
    if (sets != null) return '$sets séries';
    return '';
  }
}

/// `SessionResource` (§13.3 + addendum §A.2).
class WorkoutSession {
  final int id;
  final DateTime date;
  final String? plannedAt;
  final String title;
  final String kind; // seance | activite
  final String? goal;
  final String? level;
  final List<String> equipment;
  final List<String> focus;
  final int durationMin;
  final double? caloriesBurned;
  final String caloriesSource; // auto | manuel
  final String status; // prevue|en_cours|terminee|annulee
  final int? rpe;
  final String? notes;
  final String source; // generee|manuelle|catalogue|activite
  final int? sportId;
  final String? sportName;
  final String? lieu;
  final String? intensity;
  final double? distanceKm;
  final String? generatedBy; // regles | ia
  final String? llmModel;
  final int? sportPlanId;
  final List<String> zonesAEviter;
  final DateTime? startedAt;
  final DateTime? completedAt;
  final bool isEstimate;
  final List<WorkoutExercise> exercises;

  const WorkoutSession({
    required this.id,
    required this.date,
    this.plannedAt,
    required this.title,
    this.kind = 'seance',
    this.goal,
    this.level,
    this.equipment = const [],
    this.focus = const [],
    this.durationMin = 0,
    this.caloriesBurned,
    this.caloriesSource = 'auto',
    this.status = 'prevue',
    this.rpe,
    this.notes,
    this.source = 'manuelle',
    this.sportId,
    this.sportName,
    this.lieu,
    this.intensity,
    this.distanceKm,
    this.generatedBy,
    this.llmModel,
    this.sportPlanId,
    this.zonesAEviter = const [],
    this.startedAt,
    this.completedAt,
    this.isEstimate = true,
    this.exercises = const [],
  });

  factory WorkoutSession.fromJson(Map<String, dynamic> json) => WorkoutSession(
        id: parseIntOr(json['id'], 0),
        date: parseDate(json['date']) ?? DateTime.now(),
        plannedAt: _time(json['planned_at']),
        title: parseString(json['title']) ?? parseString(json['sport_name']) ?? 'Séance',
        kind: parseString(json['kind']) ?? 'seance',
        goal: parseString(json['goal']),
        level: parseString(json['level']),
        equipment: ApiClient.asStringList(json['equipment']),
        focus: ApiClient.asStringList(json['focus']),
        durationMin: parseIntOr(json['duration_min'], 0),
        caloriesBurned: parseNum(json['calories_burned']),
        caloriesSource: parseString(json['calories_source']) ?? 'auto',
        status: parseString(json['status']) ?? 'prevue',
        rpe: parseInt(json['rpe']),
        notes: parseString(json['notes']),
        source: parseString(json['source']) ?? 'manuelle',
        sportId: parseInt(json['sport_id']),
        sportName: parseString(json['sport_name']) ?? parseString(json['activity_type']),
        lieu: parseString(json['lieu']),
        intensity: parseString(json['intensity']),
        distanceKm: parseNum(json['distance_km']),
        generatedBy: parseString(json['generated_by']),
        llmModel: parseString(json['llm_model']),
        sportPlanId: parseInt(json['sport_plan_id']),
        zonesAEviter: ApiClient.asStringList(json['zones_a_eviter']),
        startedAt: parseDate(json['started_at']),
        completedAt: parseDate(json['completed_at']),
        isEstimate: parseBool(json['is_estimate'], fallback: true),
        exercises: ApiClient.asList(json['exercises']).map(WorkoutExercise.fromJson).toList()
          ..sort((a, b) => a.position.compareTo(b.position)),
      );

  bool get isActivity => kind == 'activite';

  bool get isPlanned => status == 'prevue';

  bool get isInProgress => status == 'en_cours';

  bool get isDone => status == 'terminee';

  bool get isCancelled => status == 'annulee';

  bool get isManualCalories => caloriesSource == 'manuel';

  String get statusLabel => AppStrings.sessionStatusLabels[status] ?? status;

  /// Exercises grouped by block in canonical order.
  Map<String, List<WorkoutExercise>> get blocks {
    final result = <String, List<WorkoutExercise>>{};
    for (final key in const ['echauffement', 'principal', 'retour_au_calme']) {
      final list = exercises.where((e) => e.block == key).toList();
      if (list.isNotEmpty) result[key] = list;
    }
    for (final e in exercises) {
      if (!result.containsKey(e.block)) result[e.block] = exercises.where((x) => x.block == e.block).toList();
    }
    return result;
  }
}

/// Exercise inside a generated proposal.
class ProposalExercise {
  final int? exerciseId;
  final String name;
  final String? category;
  final String? muscleGroup;
  final String? equipment;
  final int? sets;
  final int? reps;
  final int? durationSec;
  final int? restSec;
  final double? distanceKm;
  final String? intensity;
  final String instructions;
  final double? met;
  final double? weightKg;

  const ProposalExercise({
    this.exerciseId,
    required this.name,
    this.category,
    this.muscleGroup,
    this.equipment,
    this.sets,
    this.reps,
    this.durationSec,
    this.restSec,
    this.distanceKm,
    this.intensity,
    this.instructions = '',
    this.met,
    this.weightKg,
  });

  factory ProposalExercise.fromJson(Map<String, dynamic> json) => ProposalExercise(
        exerciseId: parseInt(json['exercise_id']),
        name: parseString(json['name']) ?? 'Exercice',
        category: parseString(json['category']),
        muscleGroup: parseString(json['muscle_group']),
        equipment: parseString(json['equipment']),
        sets: parseInt(json['sets']),
        reps: parseInt(json['reps']),
        durationSec: parseInt(json['duration_sec']),
        restSec: parseInt(json['rest_sec']),
        distanceKm: parseNum(json['distance_km']),
        intensity: parseString(json['intensity']),
        instructions: parseString(json['instructions']) ?? '',
        met: parseNum(json['met']),
        weightKg: parseNum(json['weight_kg']),
      );

  /// Body row for `POST /sport/sessions.exercises[]`.
  Map<String, dynamic> toSessionJson(String block) {
    final map = <String, dynamic>{
      'exercise_id': exerciseId,
      'name': name,
      'block': block,
      'sets': sets,
      'reps': reps,
      'duration_sec': durationSec,
      'weight_kg': weightKg,
      'rest_sec': restSec,
      'distance_km': distanceKm,
      'intensity': intensity,
    };
    map.removeWhere((k, v) => v == null);
    return map;
  }

  ProposalExercise copyWithExercise(Exercise e) => ProposalExercise(
        exerciseId: e.id,
        name: e.name,
        category: e.category,
        muscleGroup: e.muscleGroup,
        equipment: e.equipment,
        sets: sets ?? e.defaultSets,
        reps: reps ?? e.defaultReps,
        durationSec: durationSec ?? e.defaultDurationSec,
        restSec: restSec,
        distanceKm: distanceKm,
        intensity: intensity,
        instructions: e.instructions,
        met: e.met,
        weightKg: weightKg,
      );

  String get prescription {
    if (sets != null && reps != null) return '$sets × $reps';
    if (durationSec != null) {
      if (durationSec! >= 60 && durationSec! % 60 == 0) return '${durationSec! ~/ 60} min';
      return '$durationSec s';
    }
    if (distanceKm != null) return '$distanceKm km';
    return '';
  }
}

/// `blocks:[{key, name, exercises}]`.
class ProposalBlock {
  final String key;
  final String name;
  final List<ProposalExercise> exercises;

  const ProposalBlock({required this.key, required this.name, this.exercises = const []});

  factory ProposalBlock.fromJson(Map<String, dynamic> json) {
    final key = parseString(json['key']) ?? 'principal';
    return ProposalBlock(
      key: key,
      name: parseString(json['name']) ?? AppStrings.blockLabels[key] ?? key,
      exercises: ApiClient.asList(json['exercises']).map(ProposalExercise.fromJson).toList(),
    );
  }

  ProposalBlock copyWith({List<ProposalExercise>? exercises}) =>
      ProposalBlock(key: key, name: name, exercises: exercises ?? this.exercises);
}

/// `POST /sport/sessions/generate` → `data` (addendum §C.4).
class SessionProposal {
  final String title;
  final String? sportType;
  final String? lieu;
  final String? goal;
  final String? level;
  final int durationMin;
  final List<String> equipment;
  final List<String> focus;
  final List<String> zonesAEviter;
  final String? intensity;
  final double? caloriesEstimate;
  final bool isEstimate;
  final String generatedBy; // ia | regles
  final String? llmModel;
  final List<String> explication;
  final List<String> warnings;
  final List<ProposalBlock> blocks;

  const SessionProposal({
    required this.title,
    this.sportType,
    this.lieu,
    this.goal,
    this.level,
    this.durationMin = 30,
    this.equipment = const [],
    this.focus = const [],
    this.zonesAEviter = const [],
    this.intensity,
    this.caloriesEstimate,
    this.isEstimate = true,
    this.generatedBy = 'regles',
    this.llmModel,
    this.explication = const [],
    this.warnings = const [],
    this.blocks = const [],
  });

  factory SessionProposal.fromJson(Map<String, dynamic> json) => SessionProposal(
        title: parseString(json['title']) ?? 'Séance',
        sportType: parseString(json['sport_type']),
        lieu: parseString(json['lieu']),
        goal: parseString(json['goal']),
        level: parseString(json['level']),
        durationMin: parseIntOr(json['duration_min'], 30),
        equipment: ApiClient.asStringList(json['equipment']),
        focus: ApiClient.asStringList(json['focus']),
        zonesAEviter: ApiClient.asStringList(json['zones_a_eviter']),
        intensity: parseString(json['intensity']),
        caloriesEstimate: parseNum(json['calories_estimate']),
        isEstimate: parseBool(json['is_estimate'], fallback: true),
        generatedBy: parseString(json['generated_by']) ?? 'regles',
        llmModel: parseString(json['llm_model']),
        explication: ApiClient.asStringList(json['explication']),
        warnings: ApiClient.asStringList(json['warnings']),
        blocks: ApiClient.asList(json['blocks']).map(ProposalBlock.fromJson).toList(),
      );

  bool get isAi => generatedBy == 'ia';

  /// Body for `POST /sport/sessions` (`{date, sport_plan_id?, planned_at?, status?}` added by the caller).
  Map<String, dynamic> toSessionJson({
    required DateTime date,
    String? plannedAt,
    String status = 'prevue',
    int? sportPlanId,
    int? sportId,
  }) {
    final exercises = <Map<String, dynamic>>[];
    for (final block in blocks) {
      for (final e in block.exercises) {
        exercises.add(e.toSessionJson(block.key));
      }
    }
    final y = date.year.toString().padLeft(4, '0');
    final m = date.month.toString().padLeft(2, '0');
    final d = date.day.toString().padLeft(2, '0');
    final map = <String, dynamic>{
      'date': '$y-$m-$d',
      'title': title,
      'kind': 'seance',
      'goal': goal,
      'level': level,
      'equipment': equipment,
      'focus': focus,
      'zones_a_eviter': zonesAEviter,
      'lieu': lieu,
      'sport_type': sportType,
      'sport_id': sportId,
      'intensity': intensity,
      'duration_min': durationMin,
      'planned_at': plannedAt,
      'status': status,
      'sport_plan_id': sportPlanId,
      'generated_by': generatedBy,
      'llm_model': llmModel,
      'calories_estimate': caloriesEstimate,
      'source': generatedBy == 'ia' ? 'generee' : 'generee',
      'exercises': exercises,
    };
    map.removeWhere((k, v) => v == null);
    return map;
  }

  SessionProposal copyWith({List<ProposalBlock>? blocks}) => SessionProposal(
        title: title,
        sportType: sportType,
        lieu: lieu,
        goal: goal,
        level: level,
        durationMin: durationMin,
        equipment: equipment,
        focus: focus,
        zonesAEviter: zonesAEviter,
        intensity: intensity,
        caloriesEstimate: caloriesEstimate,
        isEstimate: isEstimate,
        generatedBy: generatedBy,
        llmModel: llmModel,
        explication: explication,
        warnings: warnings,
        blocks: blocks ?? this.blocks,
      );
}

/// `next_plan {date, sport_name, planned_duration_min, planned_at}`.
class NextPlan {
  final DateTime date;
  final String sportName;
  final int plannedDurationMin;
  final String? plannedAt;
  final int? id;

  const NextPlan({required this.date, required this.sportName, this.plannedDurationMin = 30, this.plannedAt, this.id});

  factory NextPlan.fromJson(Map<String, dynamic> json) => NextPlan(
        id: parseInt(json['id']),
        date: parseDate(json['date']) ?? DateTime.now(),
        sportName: parseString(json['sport_name']) ?? 'Séance',
        plannedDurationMin: parseIntOr(json['planned_duration_min'], 30),
        plannedAt: _time(json['planned_at']),
      );
}

/// `GET /sport/summary?date=` → `data`.
class SportSummary {
  final List<WorkoutSession> todaySessions;
  final double todayCaloriesBurned;
  final int todayMinutes;
  final int weekSessions;
  final int weekMinutes;
  final double weekCalories;
  final int streakDays;
  final SportBonus nutrition;
  final String? recoPre;
  final String? recoPost;
  final int? activeSessionId;
  final int coefCalories;
  final NextPlan? nextPlan;
  final List<SportPlan> weekPlans;

  const SportSummary({
    this.todaySessions = const [],
    this.todayCaloriesBurned = 0,
    this.todayMinutes = 0,
    this.weekSessions = 0,
    this.weekMinutes = 0,
    this.weekCalories = 0,
    this.streakDays = 0,
    this.nutrition = const SportBonus(),
    this.recoPre,
    this.recoPost,
    this.activeSessionId,
    this.coefCalories = 100,
    this.nextPlan,
    this.weekPlans = const [],
  });

  factory SportSummary.fromJson(Map<String, dynamic> json) {
    final today = ApiClient.asMap(json['today']);
    final week = ApiClient.asMap(json['week']);
    final recos = ApiClient.asMap(json['recos']);
    return SportSummary(
      todaySessions: ApiClient.asList(today['sessions']).map(WorkoutSession.fromJson).toList(),
      todayCaloriesBurned: parseNumOr(today['calories_burned'], 0),
      todayMinutes: parseIntOr(today['minutes'], 0),
      weekSessions: parseIntOr(week['sessions'], 0),
      weekMinutes: parseIntOr(week['minutes'], 0),
      weekCalories: parseNumOr(week['calories'], 0),
      streakDays: parseIntOr(json['streak_days'], 0),
      nutrition: SportBonus.fromJson(ApiClient.asMap(json['nutrition'])),
      recoPre: parseString(recos['pre']),
      recoPost: parseString(recos['post']),
      activeSessionId: parseInt(json['active_session_id']),
      coefCalories: parseIntOr(json['coef_calories'], 100),
      nextPlan: json['next_plan'] is Map ? NextPlan.fromJson(ApiClient.asMap(json['next_plan'])) : null,
      weekPlans: ApiClient.asList(json['week_plans']).map(SportPlan.fromJson).toList(),
    );
  }
}

/// `GET /sport/config` → `data`.
class SportConfig {
  final bool iaDisponible;
  final String? llmModel;
  final int coefCalories;
  final Map<String, List<VocabItem>> vocab;

  const SportConfig({
    this.iaDisponible = false,
    this.llmModel,
    this.coefCalories = 100,
    this.vocab = const {},
  });

  factory SportConfig.fromJson(Map<String, dynamic> json) {
    final vocabJson = ApiClient.asMap(json['vocab']);
    final vocab = <String, List<VocabItem>>{};
    vocabJson.forEach((key, value) {
      vocab[key] = ApiClient.asList(value)
          .map((e) => VocabItem(parseString(e['key']) ?? '', parseString(e['label']) ?? parseString(e['key']) ?? ''))
          .where((v) => v.key.isNotEmpty)
          .toList();
    });
    return SportConfig(
      iaDisponible: parseBool(json['ia_disponible']),
      llmModel: parseString(json['llm_model']),
      coefCalories: parseIntOr(json['coef_calories'], 100),
      vocab: vocab,
    );
  }

  /// Server vocab when present, else the local fallback from [AppStrings].
  List<VocabItem> vocabOr(String key, List<VocabItem> fallback) {
    final list = vocab[key];
    return (list == null || list.isEmpty) ? fallback : list;
  }

  List<VocabItem> get lieux => vocabOr('lieux', AppStrings.sportLieux);
  List<VocabItem> get zones => vocabOr('zones', AppStrings.sportZones);
  List<VocabItem> get focus => vocabOr('focus', AppStrings.sportFocus);
  List<VocabItem> get objectifs => vocabOr('objectifs', AppStrings.sportObjectifs);
  List<VocabItem> get niveaux => vocabOr('niveaux', AppStrings.sportNiveaux);
  List<VocabItem> get materiel => vocabOr('materiel', AppStrings.sportMateriel);
  List<VocabItem> get intensites => vocabOr('intensites', AppStrings.sportIntensites);
  List<VocabItem> get categoriesSport => vocabOr('categories_sport', AppStrings.sportCategories);
}

/// One day of `GET /sport/calendar`.
class SportCalendarDay {
  final DateTime date;
  final List<SportPlan> plans;
  final List<WorkoutSession> sessions;

  const SportCalendarDay({required this.date, this.plans = const [], this.sessions = const []});

  factory SportCalendarDay.fromJson(Map<String, dynamic> json) => SportCalendarDay(
        date: parseDate(json['date']) ?? DateTime.now(),
        plans: ApiClient.asList(json['plans']).map(SportPlan.fromJson).toList(),
        sessions: ApiClient.asList(json['sessions']).map(WorkoutSession.fromJson).toList(),
      );

  bool get hasPlanned => plans.any((p) => p.status == 'prevu');

  bool get hasDone => sessions.any((s) => s.isDone) || plans.any((p) => p.isDone);

  bool get hasCancelled => plans.any((p) => p.isCancelled) || sessions.any((s) => s.isCancelled);

  bool get isEmpty => plans.isEmpty && sessions.isEmpty;
}

/// `GET /sport/calendar?from=&to=` → `data`.
class SportCalendar {
  final List<SportCalendarDay> days;
  final int plannedCount;
  final int doneCount;
  final int minutesDone;
  final double caloriesDone;
  final String? generatedBy;

  const SportCalendar({
    this.days = const [],
    this.plannedCount = 0,
    this.doneCount = 0,
    this.minutesDone = 0,
    this.caloriesDone = 0,
    this.generatedBy,
  });

  factory SportCalendar.fromJson(Map<String, dynamic> json, {String? generatedBy}) {
    final summary = ApiClient.asMap(json['summary']);
    return SportCalendar(
      days: ApiClient.asList(json['days']).map(SportCalendarDay.fromJson).toList(),
      plannedCount: parseIntOr(summary['planned_count'], 0),
      doneCount: parseIntOr(summary['done_count'], 0),
      minutesDone: parseIntOr(summary['minutes_done'], 0),
      caloriesDone: parseNumOr(summary['calories_done'], 0),
      generatedBy: generatedBy,
    );
  }

  SportCalendarDay? day(DateTime date) {
    for (final d in days) {
      if (d.date.year == date.year && d.date.month == date.month && d.date.day == date.day) return d;
    }
    return null;
  }
}

/// `POST /sport/calories/estimate` → `{calories, met, poids_kg, is_estimate}`.
class CaloriesEstimate {
  final double calories;
  final double? met;
  final double? poidsKg;
  final bool isEstimate;

  const CaloriesEstimate({this.calories = 0, this.met, this.poidsKg, this.isEstimate = true});

  factory CaloriesEstimate.fromJson(Map<String, dynamic> json) => CaloriesEstimate(
        calories: parseNumOr(json['calories'], 0),
        met: parseNum(json['met']),
        poidsKg: parseNum(json['poids_kg']),
        isEstimate: parseBool(json['is_estimate'], fallback: true),
      );
}

/// `POST /sport/calendar/{id}/log` → `{plan, session}` + `nutrition`.
class PlanLogResult {
  final String? message;
  final SportPlan plan;
  final WorkoutSession session;
  final SportBonus nutrition;

  const PlanLogResult({this.message, required this.plan, required this.session, this.nutrition = const SportBonus()});

  factory PlanLogResult.fromJson(Map<String, dynamic> json) {
    final data = ApiClient.asMap(json['data']);
    return PlanLogResult(
      message: parseString(json['message']),
      plan: SportPlan.fromJson(ApiClient.asMap(data['plan'])),
      session: WorkoutSession.fromJson(ApiClient.asMap(data['session'])),
      nutrition: SportBonus.fromJson(ApiClient.asMap(json['nutrition'])),
    );
  }
}

/// `POST /sport/sessions/{id}/complete` → `{data, nutrition, reco_post}`.
class SessionCompleteResult {
  final String? message;
  final WorkoutSession session;
  final SportBonus nutrition;
  final String? recoPost;

  const SessionCompleteResult({this.message, required this.session, this.nutrition = const SportBonus(), this.recoPost});

  factory SessionCompleteResult.fromJson(Map<String, dynamic> json) => SessionCompleteResult(
        message: parseString(json['message']),
        session: WorkoutSession.fromJson(ApiClient.asMap(json['data'])),
        nutrition: SportBonus.fromJson(ApiClient.asMap(json['nutrition'])),
        recoPost: parseString(json['reco_post']),
      );
}

/// `POST /sport/activities` → `{data, nutrition}`.
class ActivityResult {
  final String? message;
  final WorkoutSession session;
  final SportBonus nutrition;

  const ActivityResult({this.message, required this.session, this.nutrition = const SportBonus()});

  factory ActivityResult.fromJson(Map<String, dynamic> json) => ActivityResult(
        message: parseString(json['message']),
        session: WorkoutSession.fromJson(ApiClient.asMap(json['data'])),
        nutrition: SportBonus.fromJson(ApiClient.asMap(json['nutrition'])),
      );
}

/// `POST /sport/calendar/recurring` → `{data:[…], recurrence_id}`.
class RecurringPlansResult {
  final String? message;
  final List<SportPlan> plans;
  final String? recurrenceId;

  const RecurringPlansResult({this.message, this.plans = const [], this.recurrenceId});

  factory RecurringPlansResult.fromJson(Map<String, dynamic> json) => RecurringPlansResult(
        message: parseString(json['message']),
        plans: ApiClient.asList(json['data']).map(SportPlan.fromJson).toList(),
        recurrenceId: parseString(json['recurrence_id']),
      );
}
