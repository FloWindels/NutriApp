import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/session.dart';
import '../core/strings.dart';
import '../models/dashboard.dart';
import '../navigation/app_sections.dart';
import '../theme/app_theme.dart';
import '../widgets/add_to_meal_sheet.dart';
import '../widgets/notification_bell.dart';
import 'dashboard_screen.dart';
import 'diet_screen.dart';
import 'family_screen.dart';
import 'food_search_screen.dart';
import 'meals_screen.dart';
import 'menu_screen.dart';
import 'notifications_screen.dart';
import 'planner_screen.dart';
import 'profile_screen.dart';
import 'recipes_screen.dart';
import 'recommendations_screen.dart';
import 'settings_screen.dart';
import 'shopping_list_screen.dart';
import 'sport_screen.dart';
import 'stock_screen.dart';

/// App shell: NavigationBar (Accueil · Repas · Stock · Sport · Plus) + IndexedStack.
///
/// Sections outside the 4 tabs open inside the « Plus » tab (`_plusSlug`) with a
/// back arrow. Children receive `onNavigate(slug)` for quick actions.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, this.initialTab = 0, this.initialPlusSlug});

  final int initialTab;
  final String? initialPlusSlug;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  static const int _plusTab = 4;

  late int _tab;
  String? _plusSlug;
  bool _bannerDismissed = false;

  @override
  void initState() {
    super.initState();
    _tab = widget.initialTab.clamp(0, _plusTab);
    _plusSlug = widget.initialPlusSlug != null && AppSections.exists(widget.initialPlusSlug!) ? widget.initialPlusSlug : null;
    if (_plusSlug != null) _tab = _plusTab;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      NotificationBell.refresh();
      Session.instance.loadPortions();
    });
  }

  /// Quick-action navigation used by children (`onNavigate(slug)`).
  void navigate(String slug) {
    final tabIndex = AppSections.tabIndexFor(slug);
    setState(() {
      if (tabIndex != null) {
        _tab = tabIndex;
        _plusSlug = null;
      } else if (AppSections.exists(slug)) {
        _tab = _plusTab;
        _plusSlug = slug;
      } else {
        _tab = _plusTab;
        _plusSlug = null;
      }
    });
  }

  void _selectTab(int index) {
    setState(() {
      if (index == _plusTab && _tab == _plusTab) {
        _plusSlug = null; // tapping Plus again returns to the grid
      }
      _tab = index;
    });
    NotificationBell.refresh();
  }

  String get _title {
    if (_tab == _plusTab) {
      return _plusSlug == null ? AppStrings.tabMore : AppSections.bySlug(_plusSlug!).title;
    }
    return AppSections.bySlug(AppSections.tabSlugs[_tab]).title;
  }

  bool get _showFab => _tab == 0 || _tab == 1;

  void _handlePop() {
    if (_plusSlug != null) {
      setState(() => _plusSlug = null);
    } else if (_tab != 0) {
      setState(() => _tab = 0);
    } else {
      SystemNavigator.pop();
    }
  }

  Future<void> _openAddToMeal() async {
    String? nextType;
    final cached = Session.instance.cached('dashboard');
    if (cached != null) nextType = Dashboard.fromJson(cached.data).nextMealType;
    await AddToMealSheet.show(context, mealType: nextType);
  }

  void _openNotifications() {
    Navigator.of(context).push(
      MaterialPageRoute<void>(builder: (_) => NotificationsScreen(onNavigate: (slug) {
        Navigator.of(context).popUntil((route) => route.isFirst);
        navigate(slug);
      })),
    );
  }

  Widget _plusBody() {
    final slug = _plusSlug;
    if (slug == null) return MenuScreen(onNavigate: navigate);
    switch (slug) {
      case 'recherche-aliments':
        return const FoodSearchScreen();
      case 'recettes':
        return const RecipesScreen();
      case 'recommandations-repas-journee':
        return RecommendationsScreen(onNavigate: navigate);
      case 'regime-reconnu':
        return DietScreen(onNavigate: navigate);
      case 'planificateur-semaine':
        return PlannerScreen(onNavigate: navigate);
      case 'liste-course':
        return ShoppingListScreen(onNavigate: navigate);
      case 'famille':
        return FamilyScreen(onNavigate: navigate);
      case 'profil':
        return ProfileScreen(userName: Session.instance.user?.name ?? '');
      case 'settings':
        return SettingsScreen(onNavigate: navigate);
      default:
        return MenuScreen(onNavigate: navigate);
    }
  }

  @override
  Widget build(BuildContext context) {
    final canPop = _tab == 0 && _plusSlug == null;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        if (canPop) {
          SystemNavigator.pop();
        } else {
          _handlePop();
        }
      },
      child: Scaffold(
        appBar: AppBar(
          leading: _tab == _plusTab && _plusSlug != null
              ? IconButton(
                  tooltip: AppStrings.back,
                  onPressed: () => setState(() => _plusSlug = null),
                  icon: const Icon(Icons.arrow_back_rounded),
                )
              : null,
          title: Text(_title, maxLines: 1, overflow: TextOverflow.ellipsis),
          actions: [
            NotificationBell(onPressed: _openNotifications),
            const SizedBox(width: 4),
          ],
        ),
        body: Column(
          children: [
            _ProfileBanner(
              dismissed: _bannerDismissed,
              onDismiss: () => setState(() => _bannerDismissed = true),
              onComplete: () => navigate('profil'),
            ),
            Expanded(
              child: IndexedStack(
                index: _tab,
                children: [
                  DashboardScreen(onNavigate: navigate),
                  MealsScreen(onNavigate: navigate),
                  const StockScreen(),
                  SportScreen(onNavigate: navigate),
                  _plusBody(),
                ],
              ),
            ),
          ],
        ),
        floatingActionButton: _showFab
            ? FloatingActionButton(
                tooltip: 'Ajouter à un repas',
                onPressed: _openAddToMeal,
                child: const Icon(Icons.add_rounded),
              )
            : null,
        floatingActionButtonLocation: FloatingActionButtonLocation.endFloat,
        bottomNavigationBar: NavigationBar(
          selectedIndex: _tab,
          onDestinationSelected: _selectTab,
          labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
          destinations: const [
            NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home_rounded), label: AppStrings.tabHome),
            NavigationDestination(icon: Icon(Icons.restaurant_outlined), selectedIcon: Icon(Icons.restaurant_rounded), label: AppStrings.tabMeals),
            NavigationDestination(icon: Icon(Icons.kitchen_outlined), selectedIcon: Icon(Icons.kitchen_rounded), label: AppStrings.tabStock),
            NavigationDestination(icon: Icon(Icons.fitness_center_outlined), selectedIcon: Icon(Icons.fitness_center_rounded), label: AppStrings.tabSport),
            NavigationDestination(icon: Icon(Icons.grid_view_outlined), selectedIcon: Icon(Icons.grid_view_rounded), label: AppStrings.tabMore),
          ],
        ),
      ),
    );
  }
}

/// MaterialBanner shown while `has_profile == false`.
class _ProfileBanner extends StatelessWidget {
  final bool dismissed;
  final VoidCallback onDismiss;
  final VoidCallback onComplete;

  const _ProfileBanner({required this.dismissed, required this.onDismiss, required this.onComplete});

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: Session.instance,
      builder: (context, _) {
        final user = Session.instance.user;
        if (dismissed || user == null || user.hasProfile) return const SizedBox.shrink();
        return MaterialBanner(
          backgroundColor: MaviohColors.warningBg,
          dividerColor: const Color(0xFFFDE68A),
          leading: const Icon(Icons.person_outline_rounded, color: MaviohColors.warning),
          content: const Text(
            AppStrings.profileIncomplete,
            style: TextStyle(color: MaviohColors.warning, fontWeight: FontWeight.w600),
          ),
          actions: [
            TextButton(onPressed: onDismiss, child: const Text('Plus tard')),
            FilledButton(onPressed: onComplete, child: const Text('Compléter')),
          ],
        );
      },
    );
  }
}
