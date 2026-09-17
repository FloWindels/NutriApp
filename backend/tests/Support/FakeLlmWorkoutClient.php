<?php

namespace Tests\Support;

use App\Contracts\LlmWorkoutClient;
use App\Exceptions\LlmUnavailableException;
use RuntimeException;

/**
 * Client LLM factice (addendum §C.4) : aucune requête réseau n'est possible pendant les tests.
 *
 * Trois modes :
 *  - `canned` (défaut) : renvoie la proposition fournie (ou une proposition minimale valide) ;
 *  - `throwing` : lève une exception, comme un quota dépassé ou une panne réseau ;
 *  - `refusal` : renvoie une réponse inexploitable (refus du modèle), rejetée par le schéma.
 *
 * Utilisation : `$this->app->instance(LlmWorkoutClient::class, FakeLlmWorkoutClient::canned([...]))`.
 */
class FakeLlmWorkoutClient implements LlmWorkoutClient
{
    public const MODE_CANNED = 'canned';

    public const MODE_THROWING = 'throwing';

    public const MODE_REFUSAL = 'refusal';

    /** Nombre d'appels reçus (assertions « aucun appel » / « un seul appel »). */
    public int $generateCalls = 0;

    public int $weekCalls = 0;

    /** Dernier contexte / dernière demande / dernier catalogue reçus. */
    public ?array $lastContext = null;

    public ?array $lastRequest = null;

    public ?array $lastCatalog = null;

    /**
     * @param  array<string, mixed>|null  $payload  Réponse brute renvoyée en mode « canned »
     * @param  array<string, mixed>|null  $weekPayload  Réponse brute de la planification hebdomadaire
     */
    public function __construct(
        public string $mode = self::MODE_CANNED,
        private ?array $payload = null,
        private ?array $weekPayload = null,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function canned(?array $payload = null, ?array $weekPayload = null): self
    {
        return new self(self::MODE_CANNED, $payload, $weekPayload);
    }

    public static function throwing(): self
    {
        return new self(self::MODE_THROWING);
    }

    public static function refusal(): self
    {
        return new self(self::MODE_REFUSAL);
    }

    public function generate(array $context, array $request, array $catalog): array
    {
        $this->generateCalls++;
        $this->lastContext = $context;
        $this->lastRequest = $request;
        $this->lastCatalog = $catalog;

        return match ($this->mode) {
            // Panne réseau / quota : une exception quelconque doit suffire au repli sur les règles.
            self::MODE_THROWING => throw new RuntimeException('Quota Anthropic dépassé (test).'),
            // Refus du modèle : réponse sans bloc exploitable → LlmUnavailableException au schéma.
            self::MODE_REFUSAL => ['refusal' => true, 'blocks' => []],
            default => $this->payload ?? self::defaultProposal($request, $catalog),
        };
    }

    public function generateWeekPlan(array $context, array $request): array
    {
        $this->weekCalls++;
        $this->lastContext = $context;
        $this->lastRequest = $request;

        return match ($this->mode) {
            self::MODE_THROWING => throw new LlmUnavailableException('Planification IA indisponible (test).'),
            self::MODE_REFUSAL => ['days' => []],
            default => $this->weekPayload ?? self::defaultWeek($request),
        };
    }

    /**
     * Proposition minimale valide : un exercice du catalogue dans chaque bloc.
     *
     * @param  array<string, mixed>  $request
     * @param  list<array<string, mixed>>  $catalog
     * @return array<string, mixed>
     */
    public static function defaultProposal(array $request, array $catalog): array
    {
        $first = $catalog[0] ?? null;

        return [
            'title' => 'Séance test du coach',
            'sport_type' => $request['type_sport'] ?? 'musculation',
            'intensity' => 'moderee',
            'calories_estimate' => 180,
            'explication' => ['Séance construite pour le test.'],
            'warnings' => [],
            'blocks' => [
                [
                    'key' => 'echauffement',
                    'exercises' => [[
                        'exercise_id' => null,
                        'name' => 'Mobilisation articulaire',
                        'category' => 'mobilite',
                        'duration_sec' => 180,
                        'instructions' => 'Mobilise doucement chaque articulation.',
                    ]],
                ],
                [
                    'key' => 'principal',
                    'exercises' => [[
                        'exercise_id' => $first['exercise_id'] ?? null,
                        'name' => $first['name'] ?? 'Squat au poids du corps',
                        'category' => $first['category'] ?? 'force',
                        'sets' => 3,
                        'reps' => 12,
                        'rest_sec' => 60,
                    ]],
                ],
                [
                    'key' => 'retour_au_calme',
                    'exercises' => [[
                        'exercise_id' => null,
                        'name' => 'Étirements doux',
                        'category' => 'mobilite',
                        'duration_sec' => 180,
                    ]],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public static function defaultWeek(array $request): array
    {
        $days = [];
        foreach ((array) ($request['jours'] ?? [1, 3, 5]) as $weekday) {
            $days[] = [
                'weekday' => (int) $weekday,
                'sport' => 'Course à pied',
                'duration_min' => 40,
                'focus' => 'endurance',
                'lieu' => 'exterieur',
                'note' => 'Allure confortable.',
            ];
        }

        return ['days' => $days, 'explication' => ['Semaine construite pour le test.']];
    }
}
