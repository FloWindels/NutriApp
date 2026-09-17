import '../core/api_client.dart';
import '../models/food.dart';
import '../models/recipe.dart';

/// Recipes (§5).
class RecipeService {
  RecipeService({ApiClient? client}) : _client = client;

  final ApiClient? _client;

  ApiClient get _api => _client ?? ApiClient.instance;

  /// `GET /recipes?q=&mine=1&tag=&meal_type=&max_calories=&page=&per_page=`.
  Future<Paged<Recipe>> list({
    String? q,
    bool mine = false,
    String? tag,
    String? mealType,
    double? maxCalories,
    int page = 1,
    int perPage = 100,
  }) async {
    final json = await _api.getJson('/recipes', query: {
      if (q != null && q.trim().isNotEmpty) 'q': q.trim(),
      if (mine) 'mine': 1,
      'tag': tag,
      'meal_type': mealType,
      'max_calories': maxCalories,
      'page': page,
      'per_page': perPage,
    });
    return Paged(
      items: ApiClient.asList(json['data']).map(Recipe.fromJson).toList(),
      meta: PageMeta.fromJson(ApiClient.asMap(json['meta'])),
    );
  }

  /// `GET /recipes/{recipe}`.
  Future<Recipe> get(int id) async {
    final json = await _api.getJson('/recipes/$id');
    return Recipe.fromJson(ApiClient.asMap(json['data']));
  }

  /// `POST /recipes`.
  Future<Recipe> create(Map<String, dynamic> body) async {
    final json = await _api.postJson('/recipes', body: body);
    return Recipe.fromJson(ApiClient.asMap(json['data']));
  }

  /// `PUT /recipes/{recipe}`.
  Future<Recipe> update(int id, Map<String, dynamic> body) async {
    final json = await _api.putJson('/recipes/$id', body: body);
    return Recipe.fromJson(ApiClient.asMap(json['data']));
  }

  /// `DELETE /recipes/{recipe}`.
  Future<void> delete(int id) => _api.deleteJson('/recipes/$id');

  /// `POST /recipes/estimate {ingredients}`.
  Future<RecipeEstimate> estimate(List<RecipeIngredient> ingredients) async {
    final json = await _api.postJson('/recipes/estimate', body: {
      'ingredients': ingredients.map((i) => i.toJson()).toList(),
    });
    return RecipeEstimate.fromJson(ApiClient.asMap(json['data']));
  }
}
