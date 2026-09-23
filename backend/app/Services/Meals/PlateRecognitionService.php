<?php

namespace App\Services\Meals;

use App\Contracts\LlmVisionClient;
use App\Models\Food;
use App\Models\User;
use App\Services\Foods\FoodCatalog;
use App\Support\LlmProvider;

/**
 * Reconnaissance d'une photo d'assiette.
 *
 * Deux principes tiennent tout le service. D'abord il n'écrit RIEN : il renvoie une
 * proposition en mémoire, que l'utilisateur vérifie et corrige avant d'enregistrer son repas.
 * Ensuite les valeurs nutritionnelles retenues viennent du catalogue d'aliments, pas du modèle :
 * celui-ci nomme et estime une quantité, le serveur rattache le nom à une fiche existante. Une
 * ligne qui ne trouve aucune fiche garde les valeurs proposées, explicitement signalées.
 */
class PlateRecognitionService
{
    /** Au-delà, une photo deviendrait une rafale d'appels vers Open Food Facts. */
    private const MAX_APPELS_OFF = 3;

    public function __construct(
        private readonly LlmVisionClient $vision,
        private readonly PlateRecognitionSchema $schema,
        private readonly FoodCatalog $catalog,
    ) {}

    /**
     * @return array{
     *     aliments: list<array<string, mixed>>,
     *     description: string,
     *     confiance_globale: float,
     *     avertissements: list<string>,
     *     source: string,
     *     llm_model: string|null
     * }
     */
    public function analyze(User $user, string $base64Image, string $mediaType): array
    {
        $brut = $this->vision->analyzePlate($base64Image, $mediaType, $this->context($user), PlateRecognitionSchema::json());
        $propre = $this->schema->normalize($brut);

        $appelsOff = 0;
        $lignes = [];

        foreach ($propre['aliments'] as $ligne) {
            $food = $this->catalog->resolveIngredient(null, $ligne['nom']);

            // Un seul enrichissement Open Food Facts par nom inconnu, et pas plus de trois par photo.
            if ($food === null
                && $appelsOff < self::MAX_APPELS_OFF
                && mb_strlen($ligne['nom']) >= FoodCatalog::OFF_MIN_TERM_LENGTH
            ) {
                $appelsOff++;

                if ($this->catalog->importSearchResults($ligne['nom'])) {
                    $food = $this->catalog->resolveIngredient(null, $ligne['nom']);
                }
            }

            $lignes[] = $this->ligne($ligne, $food);
        }

        // array_merge et non l'union `+` : celle-ci conserverait les lignes brutes de $propre.
        return array_merge($propre, [
            'aliments' => $lignes,
            'source' => 'ia',
            'llm_model' => LlmProvider::visionModelName(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @return array<string, mixed>
     */
    private function ligne(array $ligne, ?Food $food): array
    {
        return [
            'nom' => $ligne['nom'],
            'marque' => $ligne['marque'],
            'quantite' => $ligne['quantite'],
            'unite' => $ligne['unite'],
            'confiance' => $ligne['confiance'],
            // Rattaché au catalogue : les macros viendront de la fiche, calculées par le serveur.
            'food' => $food === null ? null : [
                'id' => (int) $food->id,
                'name' => (string) $food->name,
                'brand' => $food->brand,
                'per_unit' => $food->per_unit ?? '100g',
                'calories' => $food->calories,
                'proteins' => $food->proteins,
                'carbs' => $food->carbs,
                'fat' => $food->fat,
                'is_verified' => (bool) $food->is_verified,
            ],
            // Sinon, les valeurs proposées par le modèle, à vérifier ligne par ligne.
            'valeurs_proposees' => $food !== null ? null : [
                'calories' => $ligne['calories'],
                'proteines' => $ligne['proteines'],
                'glucides' => $ligne['glucides'],
                'lipides' => $ligne['lipides'],
            ],
        ];
    }

    /**
     * Contexte anonymisé : rien qui identifie la personne, seulement ce qui aide à nommer
     * correctement les aliments.
     *
     * @return array<string, mixed>
     */
    private function context(User $user): array
    {
        $profile = $user->profile()->first();

        return [
            'langue' => 'fr',
            'regime_alimentaire' => $profile?->regime_alimentaire,
            'allergenes' => array_values((array) ($profile?->allergenes ?? [])),
        ];
    }
}
