<?php

namespace App\Services\Recipes;

use App\Contracts\LlmRecipeClient;
use App\Models\Food;
use App\Models\User;
use App\Services\Foods\FoodCatalog;
use App\Services\Stock\StockAlerts;
use App\Support\Clock;
use App\Support\LlmProvider;

/**
 * Recette proposée à partir de ce que la personne a chez elle.
 *
 * Trois principes tiennent ce service, et ils sont les mêmes que pour la photo d'assiette.
 * Il n'écrit RIEN : la proposition est vérifiée puis enregistrée par le chemin habituel.
 * Les valeurs nutritionnelles viennent du catalogue d'aliments, jamais du modèle. Et les
 * allergènes sont une contrainte de sécurité vérifiée côté serveur : une consigne dans le
 * prompt ne suffit pas, un modèle peut se tromper.
 */
class RecipeGenerator
{
    /** Au-delà, le contexte devient trop long pour un petit modèle local. */
    private const MAX_STOCK = 40;

    public function __construct(
        private readonly LlmRecipeClient $client,
        private readonly RecipeProposalSchema $schema,
        private readonly StockAlerts $stock,
        private readonly FoodCatalog $catalog,
        private readonly RecipeNutritionEstimator $estimator,
    ) {}

    /**
     * @return array<string, mixed>  Proposition enrichie, prête à être vérifiée par la personne
     */
    public function generate(User $user, string $demande): array
    {
        $profile = $user->profile()->first();
        $allergenes = $this->minuscules((array) ($profile?->allergenes ?? []));
        $exclus = $this->minuscules((array) ($profile?->aliments_exclus ?? []));

        $brut = $this->client->proposeRecipe($demande, $this->context($user, $profile, $allergenes, $exclus), RecipeProposalSchema::json());
        $propre = $this->schema->normalize($brut);

        $ingredients = [];
        $retires = [];

        foreach ($propre['ingredients'] as $ligne) {
            $food = $this->catalog->resolveIngredient(null, $ligne['nom']);

            // Vérification de sécurité : le modèle a pu passer outre sa consigne.
            $refus = $this->refus($ligne['nom'], $food, $allergenes, $exclus);

            if ($refus !== null) {
                $retires[] = $refus;

                continue;
            }

            $ingredients[] = [
                'name' => $ligne['nom'],
                // L'ean aide la résolution ultérieure, et le catalogue le connaît déjà.
                'ean' => $food?->barcode,
                'amount' => $ligne['quantite'],
                'unit' => $ligne['unite'],
                'du_stock' => $ligne['du_stock'],
                'food_id' => $food?->id,
            ];
        }

        // Les macros sont recalculées ici, à partir des fiches réelles.
        $estimation = $this->estimator->estimate(
            array_map(fn (array $i) => ['name' => $i['name'], 'ean' => $i['ean'], 'amount' => $i['amount'], 'unit' => $i['unit']], $ingredients),
            (float) $propre['portions'],
        );

        return [
            'titre' => $propre['titre'],
            'description' => $this->descriptionAvecEtapes($propre),
            'portions' => $propre['portions'],
            'temps_preparation_min' => $propre['temps_preparation_min'],
            'ingredients' => $ingredients,
            'etapes' => $propre['etapes'],
            'remarque' => $propre['remarque'],
            'ingredients_retires' => $retires,
            'estimation' => $estimation,
            'source' => 'ia',
            'llm_model' => LlmProvider::modelName(),
        ];
    }

    /**
     * Un ingrédient est refusé s'il heurte un allergène ou un aliment exclu, que la
     * correspondance porte sur le nom proposé ou sur les allergènes de la fiche résolue.
     *
     * @param  list<string>  $allergenes
     * @param  list<string>  $exclus
     * @return array{nom: string, raison: string}|null
     */
    private function refus(string $nom, ?Food $food, array $allergenes, array $exclus): ?array
    {
        $nomPlie = $this->plier($nom);

        foreach ($allergenes as $allergene) {
            if ($allergene !== '' && str_contains($nomPlie, $allergene)) {
                return ['nom' => $nom, 'raison' => 'allergène déclaré : '.$allergene];
            }
        }

        foreach ($exclus as $exclu) {
            if ($exclu !== '' && str_contains($nomPlie, $exclu)) {
                return ['nom' => $nom, 'raison' => 'aliment exclu : '.$exclu];
            }
        }

        foreach ($this->minuscules((array) ($food?->allergens ?? [])) as $declare) {
            foreach ($allergenes as $allergene) {
                if ($allergene !== '' && str_contains($declare, $allergene)) {
                    return ['nom' => $nom, 'raison' => 'la fiche de cet aliment mentionne : '.$allergene];
                }
            }
        }

        return null;
    }

    /**
     * Contexte anonymisé : ni nom, ni adresse, ni identifiant.
     *
     * @param  list<string>  $allergenes
     * @param  list<string>  $exclus
     * @return array<string, mixed>
     */
    private function context(User $user, ?object $profile, array $allergenes, array $exclus): array
    {
        $aujourdhui = Clock::today($user);
        $fenetre = StockAlerts::windowFor($user);

        $stock = $this->stock->itemsFor($user)
            ->take(self::MAX_STOCK)
            ->map(fn ($item) => [
                'aliment' => $item->food?->name ?? $item->food_name,
                'quantite' => $item->quantity,
                'unite' => $item->unit,
                'peremption' => StockAlerts::expiryStatus($item, $aujourdhui, $fenetre),
            ])
            ->values()
            ->all();

        return [
            'stock' => $stock,
            'regime_alimentaire' => $profile?->regime_alimentaire,
            'allergenes' => $allergenes,
            'aliments_exclus' => $exclus,
            'objectif' => $profile?->objectif_type,
            'calories_cibles' => $profile?->calories_cibles,
            'profil_mineur' => $profile?->age !== null && $profile->age < 18,
        ];
    }

    /** Les étapes rejoignent la description : le modèle de recette n'a pas de champ dédié. */
    private function descriptionAvecEtapes(array $propre): string
    {
        $morceaux = [];

        if ($propre['description'] !== '') {
            $morceaux[] = $propre['description'];
        }

        foreach ($propre['etapes'] as $index => $etape) {
            $morceaux[] = ($index + 1).'. '.$etape;
        }

        return mb_substr(implode("\n", $morceaux), 0, 2000);
    }

    /**
     * @param  array<int, mixed>  $valeurs
     * @return list<string>
     */
    private function minuscules(array $valeurs): array
    {
        return array_values(array_filter(array_map(fn ($v) => $this->plier((string) $v), $valeurs)));
    }

    /** Minuscules sans accents, pour comparer « Gluten » et « gluten ». */
    private function plier(string $valeur): string
    {
        return strtr(mb_strtolower(trim($valeur), 'UTF-8'), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
    }
}
