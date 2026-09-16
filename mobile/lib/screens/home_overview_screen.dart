import 'package:flutter/material.dart';

class HomeOverviewScreen extends StatefulWidget {
  final String userName;
  final VoidCallback onGoToStock;

  const HomeOverviewScreen({
    super.key,
    required this.userName,
    required this.onGoToStock,
  });

  @override
  State<HomeOverviewScreen> createState() => _HomeOverviewScreenState();
}

class _HomeOverviewScreenState extends State<HomeOverviewScreen> {
  int _recipeIndex = 0;

  static const _recipes = [
    _RecipeSlide(
      title: 'Bowl saumon avocat',
      description: 'Riche en proteines et en bons lipides, ideal pour un repas complet apres une matinee active.',
      imagePath: 'assets/images/recipes/recipe-1.jpg',
      kcal: '620 kcal',
    ),
    _RecipeSlide(
      title: 'Salade mediterraneenne',
      description: 'Assiette legere et equilibree avec legumes croquants, source de fibres et glucides moderees.',
      imagePath: 'assets/images/recipes/recipe-2.jpg',
      kcal: '540 kcal',
    ),
  ];

  void _showPreviousRecipe() {
    setState(() {
      _recipeIndex = _recipeIndex == 0 ? _recipes.length - 1 : _recipeIndex - 1;
    });
  }

  void _showNextRecipe() {
    setState(() {
      _recipeIndex = _recipeIndex == _recipes.length - 1 ? 0 : _recipeIndex + 1;
    });
  }

  @override
  Widget build(BuildContext context) {
    final activeRecipe = _recipes[_recipeIndex];

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
      children: [
        _CardContainer(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Expanded(
                    child: Text(
                      'Vue nutrition',
                      style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: Color(0xFF0F172A)),
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF7FEE7),
                      borderRadius: BorderRadius.circular(999),
                      border: Border.all(color: const Color(0xFFD9F99D)),
                    ),
                    child: const Text(
                      'Aujourd\'hui',
                      style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: Color(0xFF4D7C0F)),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              Row(
                children: const [
                  Expanded(
                    child: _MacroCard(
                      label: 'Proteines',
                      value: '34%',
                      progress: 0.34,
                      color: Color(0xFF10B981),
                    ),
                  ),
                  SizedBox(width: 10),
                  Expanded(
                    child: _MacroCard(
                      label: 'Glucides',
                      value: '41%',
                      progress: 0.41,
                      color: Color(0xFF84CC16),
                    ),
                  ),
                  SizedBox(width: 10),
                  Expanded(
                    child: _MacroCard(
                      label: 'Lipides',
                      value: '25%',
                      progress: 0.25,
                      color: Color(0xFFF59E0B),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Row(
                children: const [
                  Expanded(
                    child: _MetricCard(
                      title: 'Hydratation',
                      value: '1.7L',
                      objective: 'Objectif 2.5L',
                      progress: 0.68,
                      color: Color(0xFF10B981),
                    ),
                  ),
                  SizedBox(width: 10),
                  Expanded(
                    child: _MetricCard(
                      title: 'Calories',
                      value: '1860',
                      objective: 'Objectif 2500 kcal',
                      progress: 0.74,
                      color: Color(0xFF0F172A),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Align(
                alignment: Alignment.centerRight,
                child: TextButton.icon(
                  onPressed: widget.onGoToStock,
                  icon: const Icon(Icons.kitchen_outlined),
                  label: const Text('Ouvrir le stock'),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        _CardContainer(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: const [
              Text(
                'Activite',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: Color(0xFF0F172A)),
              ),
              SizedBox(height: 12),
              Row(
                children: [
                  Expanded(child: _ActivityCell(label: 'Pas', value: '8 420')),
                  SizedBox(width: 10),
                  Expanded(child: _ActivityCell(label: 'Sport', value: '42 min')),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        _CardContainer(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Expanded(
                    child: Text(
                      'Exemple de recette',
                      style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: Color(0xFF0F172A)),
                    ),
                  ),
                  _RecipeNavButton(icon: Icons.arrow_back, onTap: _showPreviousRecipe),
                  const SizedBox(width: 8),
                  _RecipeNavButton(icon: Icons.arrow_forward, onTap: _showNextRecipe),
                ],
              ),
              const SizedBox(height: 12),
              Container(
                decoration: BoxDecoration(
                  color: const Color(0xFFF8FAFC),
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: const Color(0xFFE2E8F0)),
                ),
                clipBehavior: Clip.antiAlias,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    AspectRatio(
                      aspectRatio: 16 / 10,
                      child: Image.asset(
                        activeRecipe.imagePath,
                        fit: BoxFit.cover,
                        errorBuilder: (context, error, stackTrace) {
                          return Container(
                            color: const Color(0xFFF1F5F9),
                            alignment: Alignment.center,
                            child: const Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(Icons.image_not_supported_outlined, color: Color(0xFF64748B), size: 30),
                                SizedBox(height: 8),
                                Text(
                                  'Image indisponible',
                                  style: TextStyle(color: Color(0xFF64748B), fontWeight: FontWeight.w600),
                                ),
                              ],
                            ),
                          );
                        },
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  activeRecipe.title,
                                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: Color(0xFF0F172A)),
                                ),
                              ),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                                decoration: BoxDecoration(
                                  color: const Color(0xFFF7FEE7),
                                  borderRadius: BorderRadius.circular(999),
                                  border: Border.all(color: const Color(0xFFD9F99D)),
                                ),
                                child: Text(
                                  activeRecipe.kcal,
                                  style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: Color(0xFF4D7C0F)),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 8),
                          Text(
                            activeRecipe.description,
                            style: const TextStyle(color: Color(0xFF475569), height: 1.45),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        _CardContainer(
          child: Wrap(
            spacing: 10,
            runSpacing: 10,
            children: const [
              _MealCell(label: 'Petit-dej', value: '420 kcal'),
              _MealCell(label: 'Dejeuner', value: '680 kcal'),
              _MealCell(label: 'Collation', value: '220 kcal'),
              _MealCell(label: 'Diner', value: '540 kcal'),
            ],
          ),
        ),
      ],
    );
  }
}

class _RecipeSlide {
  final String title;
  final String description;
  final String imagePath;
  final String kcal;

  const _RecipeSlide({
    required this.title,
    required this.description,
    required this.imagePath,
    required this.kcal,
  });
}

class _CardContainer extends StatelessWidget {
  final Widget child;

  const _CardContainer({required this.child});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(28),
        border: Border.all(color: const Color(0xFFE2E8F0)),
        boxShadow: const [
          BoxShadow(
            color: Color(0x0F0F172A),
            blurRadius: 22,
            offset: Offset(0, 12),
          ),
        ],
      ),
      child: child,
    );
  }
}

class _MacroCard extends StatelessWidget {
  final String label;
  final String value;
  final double progress;
  final Color color;

  const _MacroCard({
    required this.label,
    required this.value,
    required this.progress,
    required this.color,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, letterSpacing: 0.9, color: Color(0xFF64748B)),
          ),
          const SizedBox(height: 8),
          Text(
            value,
            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700, color: Color(0xFF0F172A)),
          ),
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              minHeight: 7,
              value: progress,
              backgroundColor: const Color(0xFFE2E8F0),
              valueColor: AlwaysStoppedAnimation<Color>(color),
            ),
          ),
        ],
      ),
    );
  }
}

class _MetricCard extends StatelessWidget {
  final String title;
  final String value;
  final String objective;
  final double progress;
  final Color color;

  const _MetricCard({
    required this.title,
    required this.value,
    required this.objective,
    required this.progress,
    required this.color,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500, color: Color(0xFF64748B))),
          const SizedBox(height: 8),
          Text(value, style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w700, color: Color(0xFF020617))),
          const SizedBox(height: 10),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              minHeight: 8,
              value: progress,
              backgroundColor: const Color(0xFFE2E8F0),
              valueColor: AlwaysStoppedAnimation<Color>(color),
            ),
          ),
          const SizedBox(height: 8),
          Text(objective, style: const TextStyle(fontSize: 11, color: Color(0xFF64748B))),
        ],
      ),
    );
  }
}

class _ActivityCell extends StatelessWidget {
  final String label;
  final String value;

  const _ActivityCell({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: const TextStyle(fontSize: 12, color: Color(0xFF64748B))),
          const SizedBox(height: 4),
          Text(value, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700, color: Color(0xFF0F172A))),
        ],
      ),
    );
  }
}

class _RecipeNavButton extends StatelessWidget {
  final IconData icon;
  final VoidCallback onTap;

  const _RecipeNavButton({required this.icon, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: Container(
        width: 42,
        height: 42,
        decoration: BoxDecoration(
          border: Border.all(color: const Color(0xFFE2E8F0)),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Icon(icon, size: 20, color: const Color(0xFF475569)),
      ),
    );
  }
}

class _MealCell extends StatelessWidget {
  final String label;
  final String value;

  const _MealCell({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    final screenWidth = MediaQuery.sizeOf(context).width;
    final width = ((screenWidth - 88) / 2).clamp(132.0, 220.0).toDouble();

    return Container(
      width: width,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: const TextStyle(fontSize: 12, color: Color(0xFF64748B))),
          const SizedBox(height: 4),
          Text(value, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: Color(0xFF0F172A))),
        ],
      ),
    );
  }
}
