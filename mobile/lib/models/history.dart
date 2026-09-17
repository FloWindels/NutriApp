import '../core/api_client.dart';
import 'meal.dart';
import 'weight.dart';

/// `summary:{avg_calories, days_logged, adherence_pct}`.
class HistorySummary {
  final double? avgCalories;
  final int daysLogged;
  final double? adherencePct;

  const HistorySummary({this.avgCalories, this.daysLogged = 0, this.adherencePct});

  factory HistorySummary.fromJson(Map<String, dynamic> json) => HistorySummary(
        avgCalories: parseNum(json['avg_calories']),
        daysLogged: parseIntOr(json['days_logged'], 0),
        adherencePct: parseNum(json['adherence_pct']),
      );
}

/// `GET /history?from=&to=` → `data`.
class HistoryData {
  final List<MealHistoryRow> days;
  final List<WeightLog> weights;
  final HistorySummary summary;

  const HistoryData({this.days = const [], this.weights = const [], this.summary = const HistorySummary()});

  factory HistoryData.fromJson(Map<String, dynamic> json) => HistoryData(
        days: ApiClient.asList(json['days']).map(MealHistoryRow.fromJson).toList(),
        weights: ApiClient.asList(json['weights']).map(WeightLog.fromJson).toList(),
        summary: HistorySummary.fromJson(ApiClient.asMap(json['summary'])),
      );

  bool get isEmpty => days.isEmpty && weights.isEmpty;
}
