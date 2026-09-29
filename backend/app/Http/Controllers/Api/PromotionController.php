<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Magasins\StorePromotionRequest;
use App\Http\Requests\Magasins\UpdatePromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Models\Magasin;
use App\Models\MagasinProduit;
use App\Models\Promotion;
use App\Services\Magasins\LibelleProduit;
use App\Services\Magasins\PromotionsActives;
use App\Services\Magasins\RecherchePromotions;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Promotions d'une enseigne (capacité `courses`), et leur relevé automatique (capacité `ia` en
 * plus).
 *
 * Les promotions ne sont pas des données de référence comme l'assortiment : elles sont datées,
 * elles pèsent sur les propositions de repas et sur le total estimé, et une partie d'entre elles
 * a été relevée par un modèle. Elles appartiennent donc au module courses, et chaque réponse
 * porte le drapeau `verifiee` et la `source` de chaque ligne.
 */
class PromotionController extends Controller
{
    public const MSG_IA_REQUISE = 'Le relevé automatique des promotions fait partie d’une offre payante.';

    public function __construct(
        private readonly PromotionsActives $actives,
        private readonly RecherchePromotions $recherche,
    ) {
    }

    public function index(Request $request, int $magasin): JsonResponse
    {
        $enseigne = $this->magasin($magasin);
        $jour = Clock::today($request->user());

        return response()->json([
            'data' => PromotionResource::collection($this->actives->pour($enseigne, $jour))->resolve($request),
            'magasin_id' => $enseigne->id,
            'jour' => $jour,
            'avertissement' => RecherchePromotions::AVERTISSEMENT,
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function store(StorePromotionRequest $request, int $magasin): JsonResponse
    {
        $enseigne = $this->magasin($magasin);
        $data = $request->validated();

        $promotion = Promotion::query()->forceCreate([
            'magasin_id' => $enseigne->id,
            'magasin_produit_id' => $this->produit($enseigne, $data['magasin_produit_id'] ?? null)?->id,
            'libelle' => $data['libelle'],
            'libelle_normalise' => LibelleProduit::normaliser($data['libelle']),
            'prix_promotionnel' => isset($data['prix_promotionnel']) ? round((float) $data['prix_promotionnel'], 2) : null,
            'prix_avant' => isset($data['prix_avant']) ? round((float) $data['prix_avant'], 2) : null,
            'debut' => $data['debut'],
            'fin' => $data['fin'],
            'source' => Promotion::SOURCE_MANUELLE,
            // Quelqu'un l'a tapée en regardant le prospectus : elle est vérifiée par construction.
            'verifiee' => true,
        ]);

        $promotion->load('produit:id,libelle,rayon,prix_indicatif,unite,quantite_reference');

        return response()->json([
            'message' => 'Promotion enregistrée.',
            'data' => (new PromotionResource($promotion))->resolve($request),
        ], 201, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function update(UpdatePromotionRequest $request, int $promotion): JsonResponse
    {
        $model = Promotion::query()->findOrFail($promotion);
        $data = $request->validated();

        $changes = [];

        if (array_key_exists('libelle', $data)) {
            $changes['libelle'] = mb_substr(trim((string) $data['libelle']), 0, 160);
            $changes['libelle_normalise'] = LibelleProduit::normaliser($changes['libelle']);
        }
        if (array_key_exists('magasin_produit_id', $data)) {
            $changes['magasin_produit_id'] = $data['magasin_produit_id'] === null
                ? null
                : $this->produit($model->magasin()->firstOrFail(), (int) $data['magasin_produit_id'])?->id;
        }
        foreach (['prix_promotionnel', 'prix_avant'] as $champ) {
            if (array_key_exists($champ, $data)) {
                $changes[$champ] = $data[$champ] === null ? null : round((float) $data[$champ], 2);
            }
        }
        foreach (['debut', 'fin'] as $champ) {
            if (array_key_exists($champ, $data)) {
                $changes[$champ] = $data[$champ];
            }
        }
        if (array_key_exists('verifiee', $data)) {
            $changes['verifiee'] = (bool) $data['verifiee'];
        }

        if ($changes !== []) {
            $model->forceFill($changes)->save();
        }

        $model->load('produit:id,libelle,rayon,prix_indicatif,unite,quantite_reference');

        return response()->json([
            'message' => 'Promotion mise à jour.',
            'data' => (new PromotionResource($model))->resolve($request),
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function destroy(int $promotion): JsonResponse
    {
        Promotion::query()->findOrFail($promotion)->delete();

        return response()->json(['message' => 'Promotion supprimée.']);
    }

    /**
     * Relevé automatique. Deux verrous : `courses` par la route, `ia` ici — parce que c'est la
     * seule action du module qui consomme un modèle.
     */
    public function rechercher(Request $request, int $magasin): JsonResponse
    {
        $user = $request->user();

        if (! $user->peut('ia')) {
            return response()->json([
                'message' => self::MSG_IA_REQUISE,
                'capacite_requise' => 'ia',
                'offre_actuelle' => $user->offreEffective()->value,
            ], 402);
        }

        return response()->json(
            $this->recherche->pour($user, $this->magasin($magasin)),
            200,
            [],
            JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    // ------------------------------------------------------------------------------------

    private function magasin(int $id): Magasin
    {
        return Magasin::query()->where('actif', true)->findOrFail($id);
    }

    /**
     * Un produit d'une AUTRE enseigne ne peut jamais être rattaché : ce serait afficher le prix
     * de Colruyt sur une promotion Lidl.
     */
    private function produit(Magasin $magasin, mixed $id): ?MagasinProduit
    {
        if ($id === null) {
            return null;
        }

        return MagasinProduit::query()
            ->where('magasin_id', $magasin->id)
            ->find((int) $id);
    }
}
