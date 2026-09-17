<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * Génération IA impossible (clé absente, quota, refus, réseau).
 * Le générateur de séances (M8) l'attrape pour basculer sur les règles Mavi'oh ;
 * si elle remonte jusqu'à l'API, elle est rendue en 503.
 */
class LlmUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Génération IA indisponible.', ?Throwable $previous = null)
    {
        parent::__construct($message, 503, $previous);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => 'Génération IA indisponible.'], 503);
    }
}
