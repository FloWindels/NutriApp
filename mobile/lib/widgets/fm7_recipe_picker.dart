import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/strings.dart';
import '../models/recipe.dart';
import '../services/recipe_service.dart';
import '../theme/app_theme.dart';
import 'empty_state.dart';
import 'error_state.dart';
import 'loading_state.dart';
import 'recipe_tile.dart';

/// Inline recipe search (debounced, request-id guarded) reused by the planner
/// « Recette » tab and by the household common-meal card.
class Fm7RecipeSearch extends StatefulWidget {
  const Fm7RecipeSearch({
    super.key,
    required this.onSelected,
    this.selectedId,
    this.listHeight = 240,
    this.autofocus = false,
  });

  /// Called when a recipe row is tapped.
  final ValueChanged<Recipe> onSelected;

  /// Highlights the currently selected recipe.
  final int? selectedId;

  /// Height of the results area.
  final double listHeight;

  final bool autofocus;

  @override
  State<Fm7RecipeSearch> createState() => _Fm7RecipeSearchState();
}

class _Fm7RecipeSearchState extends State<Fm7RecipeSearch> {
  final _controller = TextEditingController();
  final _service = RecipeService();

  Timer? _debounce;
  int _runId = 0;
  List<Recipe> _recipes = const [];
  bool _loading = true;
  String? _error;
  String _query = '';

  @override
  void initState() {
    super.initState();
    _search('');
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _controller.dispose();
    super.dispose();
  }

  void _onChanged(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () => _search(value));
  }

  Future<void> _search(String query) async {
    final run = ++_runId;
    setState(() {
      _loading = true;
      _error = null;
      _query = query.trim();
    });
    try {
      final paged = await _service.list(q: query.trim().isEmpty ? null : query.trim(), perPage: 30);
      if (!mounted || run != _runId) return;
      setState(() {
        _recipes = paged.items;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted || run != _runId) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          controller: _controller,
          autofocus: widget.autofocus,
          textInputAction: TextInputAction.search,
          decoration: const InputDecoration(
            hintText: 'Rechercher une recette',
            prefixIcon: Icon(Icons.search_rounded),
          ),
          onChanged: _onChanged,
          onSubmitted: _search,
        ),
        const SizedBox(height: 10),
        SizedBox(height: widget.listHeight, child: _results()),
      ],
    );
  }

  Widget _results() {
    if (_loading) return const LoadingState();
    final error = _error;
    if (error != null) {
      return ErrorState(message: error, onRetry: () => _search(_query), compact: true);
    }
    if (_recipes.isEmpty) {
      return EmptyState(
        icon: Icons.menu_book_outlined,
        title: _query.isEmpty ? 'Aucune recette' : 'Aucun résultat',
        message: _query.isEmpty
            ? 'Crée une recette depuis la section Recettes pour la planifier ici.'
            : 'Essaie un autre mot-clé ou planifie un titre libre.',
        compact: true,
      );
    }
    return ListView.separated(
      padding: EdgeInsets.zero,
      physics: const AlwaysScrollableScrollPhysics(),
      itemCount: _recipes.length,
      separatorBuilder: (_, _) => const SizedBox(height: 6),
      itemBuilder: (context, index) {
        final recipe = _recipes[index];
        final selected = widget.selectedId != null && widget.selectedId == recipe.id;
        return DecoratedBox(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: selected ? MaviohColors.primary : Colors.transparent, width: 1.4),
          ),
          child: RecipeTile(
            recipe: recipe,
            dense: true,
            onTap: () => widget.onSelected(recipe),
            trailing: selected ? const Icon(Icons.check_circle_rounded, color: MaviohColors.primary) : null,
          ),
        );
      },
    );
  }
}

/// Modal wrapper around [Fm7RecipeSearch] returning the picked recipe.
class Fm7RecipePickerSheet {
  Fm7RecipePickerSheet._();

  static Future<Recipe?> show(BuildContext context, {int? selectedId, String title = 'Choisir une recette'}) {
    return showModalBottomSheet<Recipe>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (ctx) {
        final height = MediaQuery.sizeOf(ctx).height;
        return Padding(
          padding: EdgeInsets.fromLTRB(20, 4, 20, 20 + MediaQuery.viewInsetsOf(ctx).bottom),
          child: SizedBox(
            height: (height * 0.72).clamp(380.0, 680.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        title,
                        style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
                      ),
                    ),
                    IconButton(
                      tooltip: AppStrings.close,
                      onPressed: () => Navigator.of(ctx).pop(),
                      icon: const Icon(Icons.close_rounded),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Expanded(
                  child: Fm7RecipeSearch(
                    selectedId: selectedId,
                    listHeight: (height * 0.72).clamp(380.0, 680.0) - 130,
                    onSelected: (recipe) => Navigator.of(ctx).pop(recipe),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}
