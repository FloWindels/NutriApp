import 'package:flutter/material.dart';

import '../core/strings.dart';
import '../theme/app_theme.dart';

/// One of the 13 navigation sections (single source of truth).
class AppSection {
  final String slug;
  final String title;
  final String category; // Nutrition | Planification | Compte
  final IconData icon;
  final Color tone;
  final String description;

  const AppSection({
    required this.slug,
    required this.title,
    required this.category,
    required this.icon,
    required this.tone,
    this.description = '',
  });
}

/// The frozen 13-section table (brief header).
class AppSections {
  AppSections._();

  static const List<String> categoryOrder = [
    AppStrings.categoryNutrition,
    AppStrings.categoryPlanning,
    AppStrings.categoryAccount,
  ];

  static const List<AppSection> all = [
    AppSection(
      slug: 'dashboard',
      title: AppStrings.sectionDashboard,
      category: AppStrings.categoryNutrition,
      icon: Icons.home_outlined,
      tone: MaviohColors.emerald,
      description: 'Ton budget du jour, tes repas et tes conseils.',
    ),
    AppSection(
      slug: 'historique-repas-journee',
      title: AppStrings.sectionMeals,
      category: AppStrings.categoryNutrition,
      icon: Icons.restaurant_outlined,
      tone: MaviohColors.indigo,
      description: 'Enregistre ce que tu manges, repas par repas.',
    ),
    AppSection(
      slug: 'recherche-aliments',
      title: AppStrings.sectionFoodSearch,
      category: AppStrings.categoryNutrition,
      icon: Icons.search_outlined,
      tone: MaviohColors.cyan,
      description: 'Cherche un aliment ou scanne un code-barres.',
    ),
    AppSection(
      slug: 'recettes',
      title: AppStrings.sectionRecipes,
      category: AppStrings.categoryNutrition,
      icon: Icons.menu_book_outlined,
      tone: Color(0xFF10B981),
      description: 'Tes recettes et celles de la communauté.',
    ),
    AppSection(
      slug: 'recommandations-repas-journee',
      title: AppStrings.sectionCoach,
      category: AppStrings.categoryNutrition,
      icon: Icons.tips_and_updates_outlined,
      tone: MaviohColors.teal,
      description: 'Les conseils du coach pour aujourd’hui.',
    ),
    AppSection(
      slug: 'regime-reconnu',
      title: AppStrings.sectionDiet,
      category: AppStrings.categoryNutrition,
      icon: Icons.verified_outlined,
      tone: MaviohColors.fuchsia,
      description: 'Ton régime et sa cohérence avec tes repas.',
    ),
    AppSection(
      slug: 'stock',
      title: AppStrings.sectionStock,
      category: AppStrings.categoryPlanning,
      icon: Icons.kitchen_outlined,
      tone: MaviohColors.lime,
      description: 'Frigo, congélateur, placard et dates de péremption.',
    ),
    AppSection(
      slug: 'planificateur-semaine',
      title: AppStrings.sectionPlanner,
      category: AppStrings.categoryPlanning,
      icon: Icons.calendar_month_outlined,
      tone: MaviohColors.orange,
      description: 'Planifie tes repas de la semaine.',
    ),
    AppSection(
      slug: 'liste-course',
      title: AppStrings.sectionShopping,
      category: AppStrings.categoryPlanning,
      icon: Icons.shopping_cart_outlined,
      tone: MaviohColors.slate,
      description: 'Ce qu’il te manque, généré depuis le stock et le planning.',
    ),
    AppSection(
      slug: 'sport',
      title: AppStrings.sectionSport,
      category: AppStrings.categoryPlanning,
      icon: Icons.fitness_center_outlined,
      tone: MaviohColors.amber,
      description: 'Séances, calendrier et calories brûlées.',
    ),
    AppSection(
      slug: 'famille',
      title: AppStrings.sectionFamily,
      category: AppStrings.categoryAccount,
      icon: Icons.family_restroom_outlined,
      tone: MaviohColors.sky,
      description: 'Partage ton stock et tes repas avec ton foyer.',
    ),
    AppSection(
      slug: 'profil',
      title: AppStrings.sectionProfile,
      category: AppStrings.categoryAccount,
      icon: Icons.person_outline,
      tone: MaviohColors.rose,
      description: 'Tes objectifs, ton activité et tes préférences.',
    ),
    AppSection(
      slug: 'settings',
      title: AppStrings.sectionSettings,
      category: AppStrings.categoryAccount,
      icon: Icons.settings_outlined,
      tone: MaviohColors.violet,
      description: 'Compte, rappels et informations.',
    ),
  ];

  /// Slugs handled by the bottom NavigationBar (index → slug).
  static const List<String> tabSlugs = ['dashboard', 'historique-repas-journee', 'stock', 'sport'];

  static AppSection bySlug(String slug) => all.firstWhere((s) => s.slug == slug, orElse: () => all.first);

  static bool exists(String slug) => all.any((s) => s.slug == slug);

  static List<AppSection> byCategory(String category) => all.where((s) => s.category == category).toList();

  /// Tab index for a slug, or null when the slug lives in the Plus tab.
  static int? tabIndexFor(String slug) {
    final index = tabSlugs.indexOf(slug);
    return index < 0 ? null : index;
  }
}
