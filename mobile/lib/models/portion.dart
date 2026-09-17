import '../core/api_client.dart';

/// One entry of `GET /portions` → `{unit, label, label_short, grams|null, step, is_estimate}`.
class Portion {
  final String unit;
  final String label;
  final String labelShort;
  final double? grams;
  final double step;
  final bool isEstimate;

  const Portion({
    required this.unit,
    required this.label,
    required this.labelShort,
    this.grams,
    this.step = 1,
    this.isEstimate = false,
  });

  factory Portion.fromJson(Map<String, dynamic> json) => Portion(
        unit: parseString(json['unit']) ?? 'g',
        label: parseString(json['label']) ?? parseString(json['unit']) ?? 'g',
        labelShort: parseString(json['label_short']) ?? parseString(json['label']) ?? parseString(json['unit']) ?? 'g',
        grams: parseNum(json['grams']),
        step: parseNumOr(json['step'], 1),
        isEstimate: parseBool(json['is_estimate']),
      );

  Map<String, dynamic> toJson() => {
        'unit': unit,
        'label': label,
        'label_short': labelShort,
        'grams': grams,
        'step': step,
        'is_estimate': isEstimate,
      };

  /// Fallback table (§3.3) used when `/portions` is unreachable.
  static const List<Portion> defaults = [
    Portion(unit: 'g', label: 'gramme', labelShort: 'g', grams: 1, step: 10),
    Portion(unit: 'ml', label: 'millilitre', labelShort: 'ml', grams: 1, step: 10, isEstimate: true),
    Portion(unit: 'piece', label: 'pièce', labelShort: 'pièce', step: 0.5, isEstimate: true),
    Portion(unit: 'portion', label: 'portion', labelShort: 'portion', step: 0.5, isEstimate: true),
    Portion(unit: 'tranche', label: 'tranche', labelShort: 'tranche', grams: 30, step: 0.5, isEstimate: true),
    Portion(unit: 'cas', label: 'cuillère à soupe', labelShort: 'c. à s.', grams: 15, step: 1, isEstimate: true),
    Portion(unit: 'cac', label: 'cuillère à café', labelShort: 'c. à c.', grams: 5, step: 1, isEstimate: true),
    Portion(unit: 'verre', label: 'verre', labelShort: 'verre', grams: 200, step: 1, isEstimate: true),
    Portion(unit: 'bol', label: 'bol', labelShort: 'bol', grams: 300, step: 1, isEstimate: true),
    Portion(unit: 'assiette', label: 'assiette', labelShort: 'assiette', grams: 350, step: 1, isEstimate: true),
    Portion(unit: 'poignee', label: 'poignée', labelShort: 'poignée', grams: 30, step: 1, isEstimate: true),
  ];

  /// Category defaults for piece/portion when the food has no serving size.
  static const Map<String, double> categoryDefaults = {
    'oeuf': 55,
    'fruit': 150,
    'legume': 120,
    'yaourt': 125,
    'biscuit': 10,
    'pain': 30,
    'jambon': 40,
    'viande': 125,
    'poisson': 130,
    'fromage': 30,
  };

  /// Finds a portion by unit in [list] (falls back to [defaults]).
  static Portion? find(List<Portion> list, String unit) {
    final source = list.isEmpty ? defaults : list;
    for (final p in source) {
      if (p.unit == unit) return p;
    }
    return null;
  }

  /// Client-side gram estimate (mirrors `Portions::toGrams`).
  /// Returns null when the unit is unknown.
  static double? toGrams(
    List<Portion> list,
    double quantity,
    String unit, {
    double? servingSizeG,
    String? category,
    double? densityGPerMl,
  }) {
    switch (unit) {
      case 'g':
        return quantity;
      case 'ml':
        return quantity * (densityGPerMl ?? 1.0);
      case 'piece':
      case 'unite':
      case 'portion':
        final base = servingSizeG ?? (category == null ? null : categoryDefaults[category.toLowerCase()]) ?? 100;
        return quantity * base;
      default:
        final p = find(list, unit);
        if (p?.grams == null) return null;
        return quantity * p!.grams!;
    }
  }
}
