<?php

namespace App\Http\Controllers\Api;

use App\Enums\Rayon;
use App\Http\Controllers\Controller;
use App\Http\Requests\Magasins\IndexProduitsRequest;
use App\Http\Resources\MagasinProduitResource;
use App\Http\Resources\MagasinResource;
use App\Models\Magasin;
use App\Models\MagasinProduit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalogue **public** des magasins et de leurs assortiments, sans authentification, sous
 * `throttle:30,1` — comme le catalogue des exercices et celui des régimes.
 *
 * Public parce que ce sont des données de référence : savoir que Lidl vend du riz basmati autour
 * de 1,79 € n'engage personne et ne révèle rien. Ce qui est payant, c'est ce qu'on en fait —
 * rattacher sa liste de courses, estimer son panier, relever les promotions.
 */
class MagasinController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $magasins = Magasin::query()
            ->where('actif', true)
            ->withCount('produits')
            ->orderBy('enseigne')
            ->orderBy('nom')
            ->get();

        return response()->json([
            'data' => MagasinResource::collection($magasins)->resolve($request),
            'rayons' => Rayon::catalogue(),
            'avertissement' => MagasinProduitResource::AVERTISSEMENT,
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function produits(IndexProduitsRequest $request, int $magasin): JsonResponse
    {
        $enseigne = Magasin::query()->where('actif', true)->findOrFail($magasin);
        $validated = $request->validated();

        $query = MagasinProduit::query()->where('magasin_id', $enseigne->id);

        $terme = isset($validated['q']) ? mb_strtolower(trim((string) $validated['q'])) : '';
        if ($terme !== '') {
            $like = '%'.addcslashes($terme, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(libelle) LIKE ?', [$like])
                    ->orWhere('libelle_normalise', 'LIKE', $like);
            });
        }

        if (! empty($validated['rayon'])) {
            $query->where('rayon', $validated['rayon']);
        }

        $perPage = min(max((int) ($validated['per_page'] ?? IndexProduitsRequest::PER_PAGE_DEFAULT), 1), IndexProduitsRequest::PER_PAGE_MAX);
        $page = max((int) ($validated['page'] ?? 1), 1);

        $total = (clone $query)->count();

        // Tri par rayon dans l'ordre de traversée du magasin : la colonne stocke une clé, pas un
        // rang, donc l'ordre alphabétique de la base ne veut rien dire. On trie en PHP sur la
        // page demandée, ce qui suffit — un assortiment tient en quelques centaines de lignes.
        $produits = $query->orderBy('libelle')->orderBy('id')->get();

        $produits = $produits
            ->sortBy(fn (MagasinProduit $produit) => sprintf(
                '%02d|%s',
                Rayon::ordreDe($produit->rayon?->value),
                mb_strtolower((string) $produit->libelle),
            ))
            ->slice(($page - 1) * $perPage, $perPage)
            ->values();

        return response()->json([
            'data' => MagasinProduitResource::collection($produits)->resolve($request),
            'magasin' => (new MagasinResource($enseigne))->resolve($request),
            'rayons' => Rayon::catalogue(),
            'avertissement' => MagasinProduitResource::AVERTISSEMENT,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
