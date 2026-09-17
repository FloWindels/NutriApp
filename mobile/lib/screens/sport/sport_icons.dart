import 'package:flutter/material.dart';

/// Maps a `sports.icon` hint (addendum §A.1) to a Material icon.
///
/// Falls back to the sport category icon, then to a generic one.
IconData sportIconFor(String? icon, {String? category}) {
  switch (icon) {
    case 'directions_run':
      return Icons.directions_run;
    case 'directions_walk':
      return Icons.directions_walk;
    case 'directions_bike':
      return Icons.directions_bike;
    case 'pool':
      return Icons.pool;
    case 'fitness_center':
      return Icons.fitness_center;
    case 'sports_soccer':
      return Icons.sports_soccer;
    case 'sports_tennis':
      return Icons.sports_tennis;
    case 'sports_basketball':
      return Icons.sports_basketball;
    case 'sports_volleyball':
      return Icons.sports_volleyball;
    case 'sports_handball':
      return Icons.sports_handball;
    case 'sports_golf':
      return Icons.sports_golf;
    case 'sports_martial_arts':
      return Icons.sports_martial_arts;
    case 'sports_gymnastics':
      return Icons.sports_gymnastics;
    case 'self_improvement':
      return Icons.self_improvement;
    case 'hiking':
      return Icons.hiking;
    case 'downhill_skiing':
      return Icons.downhill_skiing;
    case 'snowboarding':
      return Icons.snowboarding;
    case 'rowing':
      return Icons.rowing;
    case 'kayaking':
      return Icons.kayaking;
    case 'surfing':
      return Icons.surfing;
    case 'skateboarding':
      return Icons.skateboarding;
    case 'roller_skating':
      return Icons.roller_skating;
    case 'ice_skating':
      return Icons.ice_skating;
    case 'nordic_walking':
      return Icons.nordic_walking;
    case 'scuba_diving':
      return Icons.scuba_diving;
    case 'sports_handball_outlined':
      return Icons.sports_handball;
  }
  return sportCategoryIcon(category);
}

/// Icon for a sport category (`endurance|force|collectif|…`).
IconData sportCategoryIcon(String? category) {
  switch (category) {
    case 'endurance':
      return Icons.directions_run;
    case 'force':
      return Icons.fitness_center;
    case 'collectif':
      return Icons.sports_soccer;
    case 'raquette':
      return Icons.sports_tennis;
    case 'aquatique':
      return Icons.pool;
    case 'combat':
      return Icons.sports_martial_arts;
    case 'bien_etre':
      return Icons.self_improvement;
    case 'glisse':
      return Icons.downhill_skiing;
    default:
      return Icons.sports;
  }
}

/// Icon for an equipment key (`aucun|halteres|barre|…`).
IconData equipmentIcon(String? equipment) {
  switch (equipment) {
    case 'aucun':
      return Icons.accessibility_new;
    case 'halteres':
    case 'barre':
      return Icons.fitness_center;
    case 'kettlebell':
      return Icons.sports_gymnastics;
    case 'elastiques':
      return Icons.linear_scale;
    case 'banc':
      return Icons.chair_alt;
    case 'barre_traction':
      return Icons.horizontal_rule;
    case 'machine':
      return Icons.precision_manufacturing;
    case 'tapis':
      return Icons.directions_run;
    case 'velo':
      return Icons.directions_bike;
    default:
      return Icons.fitness_center;
  }
}

/// Icon for a workout block key.
IconData blockIcon(String key) {
  switch (key) {
    case 'echauffement':
      return Icons.local_fire_department_outlined;
    case 'retour_au_calme':
      return Icons.self_improvement;
    default:
      return Icons.fitness_center;
  }
}
