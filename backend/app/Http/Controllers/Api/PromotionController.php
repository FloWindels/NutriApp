<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Magasins\StorePromotionRequest;
use App\Http\Requests\Magasins\UpdatePromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Models\Magasin;
use App\Models\MagasinProduit;
use App\Models\Promotion;
use App\Services\Admin\AdminJournal;
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
        private readonly AdminJournal $journal,
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

        $this->journal->enregistrer(
            $request->user(),
            'creer_promotion',
            'promotion',
            $promotion->id,
            $this->libelle($enseigne, $promotion),
            'Promotion saisie à la main ('.$promotion->debut?->format('Y-m-d').' → '.$promotion->fin?->format('Y-m-d').').',
        );

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

            $this->journal->enregistrer(
                $request->user(),
                'modifier_promotion',
                'promotion',
                $model->id,
                $this->libelle($model->magasin()->first(), $model),
                'Champs modifiés : '.implode(', ', array_diff(array_keys($changes), ['libelle_normalise'])).'.',
            );
        }

        $model->load('produit:id,libelle,rayon,prix_indicatif,unite,quantite_reference');

        return response()->json([
            'message' => 'Promotion mise à jour.',
            'data' => (new PromotionResource($model))->resolve($request),
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function destroy(Request $request, int $promotion): JsonResponse
    {
        $model = Promotion::query()->findOrFail($promotion);
        $libelle = $this->libelle($model->magasin()->first(), $model);
        $model->delete();

        $this->journal->enregistrer(
            $request->user(),
            'supprimer_promotion',
            'promotion',
            $promotion,
            $libelle,
            'Promotion retirée du catalogue partagé.',
        );

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

    /** « Lidl — Pâtes 500 g » : lisible dans le journal même après suppression de la ligne. */
    private function libelle(?Magasin $magasin, Promotion $promotion): string
    {
        $enseigne = $magasin?->enseigne?->label();

        return ($enseigne !== null ? $enseigne.' — ' : '').$promotion->libelle;
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
