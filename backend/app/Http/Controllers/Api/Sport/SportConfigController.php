<?php

namespace App\Http\Controllers\Api\Sport;

use App\Enums\Equipment;
use App\Enums\ExerciseLevel;
use App\Enums\Intensity;
use App\Enums\Lieu;
use App\Enums\SportCategory;
use App\Services\Sport\SportVocab;
use App\Services\Sport\WorkoutAiGenerator;
use App\Services\SportNutrition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /sport/config (addendum §C.1) : disponibilité du coach IA, coefficient de réintégration
 * des calories et **tous les vocabulaires** du module avec leurs libellés français — les clients
 * (Flutter, Next.js) n'ont aucune liste à coder en dur.
 */
class SportConfigController extends SportController
{
    public function __construct(
        private readonly WorkoutAiGenerator $generator,
        private readonly SportNutrition $nutrition,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $disponible = $this->generator->iaDisponible($user);

        return $this->json([
            'data' => [
                'ia_disponible' => $disponible,
                'llm_model' => $disponible ? $this->generator->llmModel() : null,
                'coef_calories' => $this->nutrition->coefficient($user),
                'coefs_calories' => SportNutrition::COEFFICIENTS,
                'vocab' => [
                    'lieux' => SportVocab::options(Lieu::labels()),
                    'zones' => SportVocab::options(SportVocab::ZONES),
                    'focus' => SportVocab::options(SportVocab::FOCUS),
                    'objectifs' => SportVocab::options(SportVocab::OBJECTIFS),
                    'niveaux' => SportVocab::options(ExerciseLevel::labels()),
                    'materiel' => SportVocab::options(Equipment::labels()),
                    'intensites' => SportVocab::options(Intensity::labels()),
                    'categories_sport' => SportVocab::options(SportCategory::labels()),
                    'types_sport' => SportVocab::options(SportVocab::SPORT_TYPES),
                ],
            ],
        ]);
    }
}
