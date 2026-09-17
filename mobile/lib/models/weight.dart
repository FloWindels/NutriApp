import '../core/api_client.dart';

/// `{date, weight_kg}`.
class WeightLog {
  final DateTime date;
  final double weightKg;

  const WeightLog({required this.date, required this.weightKg});

  factory WeightLog.fromJson(Map<String, dynamic> json) => WeightLog(
        date: parseDate(json['date']) ?? DateTime.now(),
        weightKg: parseNumOr(json['weight_kg'] ?? json['weight'], 0),
      );

  Map<String, dynamic> toJson() => {
        'date': '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}',
        'weight_kg': weightKg,
      };
}
