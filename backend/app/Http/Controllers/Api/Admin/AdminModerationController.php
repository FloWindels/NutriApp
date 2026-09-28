<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Food;
use App\Models\Recipe;
use App\Models\Scopes\NonMasqueScope;
use App\Services\Admin\AdminJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Modération des contenus visibles par autrui.
 *
 * Deux seulement le sont réellement : les aliments créés par les utilisateurs, qui entrent
 * directement dans le catalogue commun — consultable sans compte, y compris par code-barres —
 * et les recettes publiées. Les sports personnels sont hors périmètre : le catalogue force
 * `is_public = false` à leur création, aucun n'est visible par un tiers.
 *
 * On masque, on ne supprime pas : le contenu reste consultable par son auteur et l'action est
 * réversible. Écrire `is_public = false` sur une recette serait indistinguable d'un choix de
 * son auteur, qui pourrait la republier d'un clic.
 */
class AdminModerationController extends Controller
{
    public function __construct(private readonly AdminJournal $journal) {}

    public function foods(Request $request): JsonResponse
    {
        $query = Food::query()
            ->withoutGlobalScope(NonMasqueScope::class)
            ->whereNotNull('created_by_user_id')
            ->orderByDesc('created_at');

        if ($request->boolean('masques')) {
            $query->whereNotNull('masque_le');
        }

        return response()->json([
            'data' => $query->limit(100)->get()->map(fn (Food $food) => [
                'id' => (int) $food->id,
                'name' => $food->name,
                'brand' => $food->brand,
                'barcode' => $food->barcode,
                'created_by_user_id' => $food->created_by_user_id,
                'masque_le' => $food->masque_le,
                'masque_motif' => $food->masque_motif,
                'created_at' => $food->created_at?->toISOString(),
            ])->all(),
        ]);
    }

    public function recipes(Request $request): JsonResponse
    {
        $query = Recipe::query()
            ->withoutGlobalScope(NonMasqueScope::class)
            ->where('is_public', true)
            ->orderByDesc('created_at');

        if ($request->boolean('masques')) {
            $query->whereNotNull('masque_le');
        }

        return response()->json([
            'data' => $query->limit(100)->get()->map(fn (Recipe $recipe) => [
                'id' => (int) $recipe->id,
                'title' => $recipe->title,
                'description' => $recipe->description,
                'created_by_user_id' => $recipe->created_by_user_id,
                'masque_le' => $recipe->masque_le,
                'masque_motif' => $recipe->masque_motif,
                'created_at' => $recipe->created_at?->toISOString(),
            ])->all(),
        ]);
    }

    public function hideFood(Request $request, int $food): JsonResponse
    {
        return $this->masquer($request, Food::class, $food, 'masquer_aliment', 'food', fn (Food $f) => $f->name);
    }

    public function showFood(Request $request, int $food): JsonResponse
    {
        return $this->demasquer($request, Food::class, $food, 'demasquer_aliment', 'food', fn (Food $f) => $f->name);
    }

    public function hideRecipe(Request $request, int $recipe): JsonResponse
    {
        return $this->masquer($request, Recipe::class, $recipe, 'masquer_recette', 'recipe', fn (Recipe $r) => $r->title);
    }

    public function showRecipe(Request $request, int $recipe): JsonResponse
    {
        return $this->demasquer($request, Recipe::class, $recipe, 'demasquer_recette', 'recipe', fn (Recipe $r) => $r->title);
    }

    /** Journal, le plus récent d'abord. */
    public function journal(): JsonResponse
    {
        return response()->json([
            'data' => AdminAction::query()
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn (AdminAction $ligne) => [
                    'id' => (int) $ligne->id,
                    'admin_email' => $ligne->admin_email,
                    'action' => $ligne->action,
                    'cible_type' => $ligne->cible_type,
                    'cible_id' => $ligne->cible_id,
                    'cible_libelle' => $ligne->cible_libelle,
                    'motif' => $ligne->motif,
                    'created_at' => $ligne->created_at?->toISOString(),
                ])->all(),
        ]);
    }

    /**
     * @param  class-string<Food|Recipe>  $modele
     * @param  callable(mixed): ?string  $libelle
     */
    private function masquer(Request $request, string $modele, int $id, string $action, string $type, callable $libelle): JsonResponse
    {
        $motif = $request->validate(['motif' => ['required', 'string', 'min:3', 'max:255']])['motif'];
        $cible = $modele::query()->withoutGlobalScope(NonMasqueScope::class)->findOrFail($id);

        $cible->forceFill([
            'masque_le' => now(),
            'masque_par_id' => $request->user()->id,
            'masque_motif' => $motif,
        ])->save();

        $this->journal->enregistrer($request->user(), $action, $type, $id, $libelle($cible), $motif);

        return response()->json(['message' => 'Contenu masqué.']);
    }

    /**
     * @param  class-string<Food|Recipe>  $modele
     * @param  callable(mixed): ?string  $libelle
     */
    private function demasquer(Request $request, string $modele, int $id, string $action, string $type, callable $libelle): JsonResponse
    {
        $motif = $request->validate(['motif' => ['required', 'string', 'min:3', 'max:255']])['motif'];
        $cible = $modele::query()->withoutGlobalScope(NonMasqueScope::class)->findOrFail($id);

        $cible->forceFill(['masque_le' => null, 'masque_par_id' => null, 'masque_motif' => null])->save();

        $this->journal->enregistrer($request->user(), $action, $type, $id, $libelle($cible), $motif);

        return response()->json(['message' => 'Contenu rétabli.']);
    }
}
