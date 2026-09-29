<?php

namespace Tests\Support;

use App\Contracts\LlmPlannerClient;
use App\Exceptions\LlmUnavailableException;
use RuntimeException;

/**
 * Client factice qui organise la semaine : aucune requête réseau n'est possible pendant les tests.
 *
 * Trois modes, comme pour le coach sportif :
 *  - `canned` (défaut) : renvoie la réponse fournie, ou place la première recette autorisée
 *    sur chaque créneau ;
 *  - `throwing` : lève une exception, comme une panne réseau ou un quota dépassé ;
 *  - `refusal` : renvoie une réponse inexploitable, rejetée par le schéma.
 *
 * Utilisation : `$this->app->instance(LlmPlannerClient::class, FakeLlmPlannerClient::canned([...]))`.
 */
class FakeLlmPlannerClient implements LlmPlannerClient
{
    public const MODE_CANNED = 'canned';

    public const MODE_THROWING = 'throwing';

    public const MODE_REFUSAL = 'refusal';

    /** Nombre d'appels reçus (assertions « aucun appel » / « un seul appel »). */
    public int $calls = 0;

    public ?string $lastDemande = null;

    /** @var array<string, mixed>|null */
    public ?array $lastContext = null;

    /**
     * @param  array<string, mixed>|null  $payload  Réponse brute renvoyée en mode « canned »
     */
    public function __construct(
        public string $mode = self::MODE_CANNED,
        private ?array $payload = null,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function canned(?array $payload = null): self
    {
        return new self(self::MODE_CANNED, $payload);
    }

    public static function throwing(): self
    {
        return new self(self::MODE_THROWING);
    }

    public static function refusal(): self
    {
        return new self(self::MODE_REFUSAL);
    }

    public function composeWeek(string $demande, array $context, array $schema): array
    {
        $this->calls++;
        $this->lastDemande = $demande;
        $this->lastContext = $context;

        return match ($this->mode) {
            self::MODE_THROWING => throw new RuntimeException('Modèle injoignable (test).'),
            self::MODE_REFUSAL => throw new LlmUnavailableException('Réponse illisible (test).'),
            default => $this->payload ?? self::defaultWeek($context),
        };
    }

    /**
     * Réponse minimale valide : sur chaque créneau, la première recette que le serveur autorise
     * pour ce type de repas.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function defaultWeek(array $context): array
    {
        $parRepas = [];
        foreach ((array) ($context['recettes_disponibles'] ?? []) as $recette) {
            foreach ((array) ($recette['repas'] ?? []) as $repas) {
                $parRepas[$repas] ??= (int) $recette['id'];
            }
        }

        $creneaux = [];
        foreach ((array) ($context['creneaux'] ?? []) as $creneau) {
            $id = $parRepas[$creneau['repas']] ?? null;

            if ($id !== null) {
                $creneaux[] = ['date' => $creneau['date'], 'repas' => $creneau['repas'], 'recette_id' => $id];
            }
        }

        return ['creneaux' => $creneaux, 'explication' => ['Semaine composée pour le test.']];
    }
}
