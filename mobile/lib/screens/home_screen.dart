import 'package:flutter/material.dart';

import '../services/auth_service.dart';
import 'food_search_screen.dart';
import 'home_overview_screen.dart';
import 'login_screen.dart';
import 'profile_screen.dart';
import 'recipes_screen.dart';
import 'stock_screen.dart';

class HomeScreen extends StatefulWidget {
  final String userName;

  const HomeScreen({super.key, required this.userName});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  static const _categoryOrder = ['Nutrition', 'Planification', 'Compte'];

  static const _sections = [
    _DrawerSection(slug: 'dashboard', title: 'Accueil', category: 'Nutrition', icon: Icons.home_outlined, tone: Color(0xFF059669)),
    _DrawerSection(slug: 'recettes', title: 'Recettes', category: 'Nutrition', icon: Icons.menu_book_outlined, tone: Color(0xFF10B981)),
    _DrawerSection(slug: 'sport', title: 'Sport', category: 'Nutrition', icon: Icons.fitness_center_outlined, tone: Color(0xFFF59E0B)),
    _DrawerSection(slug: 'recherche-aliments', title: 'Recherche d’aliments', category: 'Nutrition', icon: Icons.search_outlined, tone: Color(0xFF06B6D4)),
    _DrawerSection(slug: 'historique-repas-journee', title: 'Historique des repas du jour', category: 'Nutrition', icon: Icons.history, tone: Color(0xFF6366F1)),
    _DrawerSection(slug: 'recommandations-repas-journee', title: 'Recommandations du jour', category: 'Nutrition', icon: Icons.tips_and_updates_outlined, tone: Color(0xFF14B8A6)),
    _DrawerSection(slug: 'regime-reconnu', title: 'Régime reconnu', category: 'Nutrition', icon: Icons.verified_outlined, tone: Color(0xFFD946EF)),
    _DrawerSection(slug: 'stock', title: 'Stock', category: 'Planification', icon: Icons.kitchen_outlined, tone: Color(0xFF84CC16)),
    _DrawerSection(slug: 'planificateur-semaine', title: 'Planificateur de repas', category: 'Planification', icon: Icons.calendar_month_outlined, tone: Color(0xFFF97316)),
    _DrawerSection(slug: 'liste-course', title: 'Liste de course', category: 'Planification', icon: Icons.shopping_cart_outlined, tone: Color(0xFF64748B)),
    _DrawerSection(slug: 'famille', title: 'Famille', category: 'Compte', icon: Icons.family_restroom_outlined, tone: Color(0xFF0EA5E9)),
    _DrawerSection(slug: 'profil', title: 'Profil', category: 'Compte', icon: Icons.person_outline, tone: Color(0xFFF43F5E)),
    _DrawerSection(slug: 'settings', title: 'Settings', category: 'Compte', icon: Icons.settings_outlined, tone: Color(0xFF8B5CF6)),
  ];

  String _selectedSlug = 'dashboard';

  Future<void> _logout(BuildContext context) async {
    final authService = AuthService();
    await authService.logout();

    if (!context.mounted) return;

    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(
        builder: (_) => const LoginScreen(),
      ),
      (route) => false,
    );
  }

  void _goToStock() {
    setState(() {
      _selectedSlug = 'stock';
    });
  }

  _DrawerSection get _selectedSection {
    return _sections.firstWhere((section) => section.slug == _selectedSlug, orElse: () => _sections.first);
  }

  Widget _buildCurrentPage() {
    switch (_selectedSlug) {
      case 'dashboard':
        return HomeOverviewScreen(userName: widget.userName, onGoToStock: _goToStock);
      case 'recherche-aliments':
        return const FoodSearchScreen();
      case 'recettes':
        return const RecipesScreen();
      case 'stock':
        return const StockScreen();
      case 'profil':
        return ProfileScreen(userName: widget.userName);
      default:
        return _SectionPlaceholderScreen(
          title: _selectedSection.title,
          category: _selectedSection.category,
          tone: _selectedSection.tone,
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final groupedSections = {
      for (final category in _categoryOrder) category: _sections.where((section) => section.category == category).toList(),
    };

    return Scaffold(
      appBar: AppBar(
        title: Text(_selectedSection.title),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: IconButton(
              onPressed: () => _logout(context),
              icon: const Icon(Icons.logout_rounded),
              tooltip: 'Déconnexion',
            ),
          ),
        ],
      ),
      drawer: Drawer(
        child: SafeArea(
          child: ListView(
            padding: EdgeInsets.zero,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(18, 8, 18, 18),
                child: Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF0FDF4),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Row(
                    children: [
                      Container(
                        width: 52,
                        height: 52,
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(16),
                        ),
                        child: Image.asset('assets/images/logoMoh.png', fit: BoxFit.contain),
                      ),
                      const SizedBox(width: 14),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text('Mavioh', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                            const SizedBox(height: 4),
                            Text(widget.userName, style: const TextStyle(color: Color(0xFF64748B))),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              ..._categoryOrder.map((category) {
                final items = groupedSections[category]!;
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(20, 6, 20, 8),
                      child: Text(
                        category,
                        style: const TextStyle(
                          fontSize: 11,
                          letterSpacing: 1.2,
                          fontWeight: FontWeight.w700,
                          color: Color(0xFF94A3B8),
                        ),
                      ),
                    ),
                    ...items.map((item) {
                      final selected = _selectedSlug == item.slug;
                      return Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
                        child: ListTile(
                          leading: Container(
                            width: 28,
                            height: 28,
                            decoration: BoxDecoration(
                              color: selected ? Colors.white.withValues(alpha: 0.16) : item.tone,
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: Icon(item.icon, size: 16, color: Colors.white),
                          ),
                          title: Text(
                            item.title,
                            style: TextStyle(
                              fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                              color: selected ? Colors.white : const Color(0xFF334155),
                            ),
                          ),
                          selected: selected,
                          selectedTileColor: const Color(0xFF0F5B43),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                          onTap: () {
                            Navigator.of(context).pop();
                            setState(() => _selectedSlug = item.slug);
                          },
                        ),
                      );
                    }),
                    const SizedBox(height: 6),
                  ],
                );
              }),
              Padding(
                padding: const EdgeInsets.all(18),
                child: FilledButton.tonalIcon(
                  onPressed: () => _logout(context),
                  icon: const Icon(Icons.logout_rounded),
                  label: const Text('Déconnexion'),
                ),
              ),
            ],
          ),
        ),
      ),
      body: _buildCurrentPage(),
    );
  }
}

class _DrawerSection {
  final String slug;
  final String title;
  final String category;
  final IconData icon;
  final Color tone;

  const _DrawerSection({
    required this.slug,
    required this.title,
    required this.category,
    required this.icon,
    required this.tone,
  });
}

class _SectionPlaceholderScreen extends StatelessWidget {
  final String title;
  final String category;
  final Color tone;

  const _SectionPlaceholderScreen({required this.title, required this.category, required this.tone});

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
      children: [
        Container(
          padding: const EdgeInsets.all(22),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(24),
            border: Border.all(color: const Color(0xFFE2E8F0)),
            boxShadow: const [
              BoxShadow(
                color: Color(0x0F0F172A),
                blurRadius: 18,
                offset: Offset(0, 10),
              ),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(color: tone, borderRadius: BorderRadius.circular(12)),
                child: const Icon(Icons.auto_awesome_outlined, color: Colors.white),
              ),
              const SizedBox(height: 14),
              Text(title, style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: Color(0xFF0F172A))),
              const SizedBox(height: 8),
              Text(
                'Catégorie: $category',
                style: const TextStyle(color: Color(0xFF64748B), fontWeight: FontWeight.w600),
              ),
              const SizedBox(height: 12),
              const Text(
                'Cette section est prête dans la navigation, il reste à brancher son contenu métier comme sur la version Next.',
                style: TextStyle(color: Color(0xFF475569), height: 1.45),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
