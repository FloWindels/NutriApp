<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Le modèle local est saturé : toutes les places de génération sont prises et la file d'attente
 * est pleine, ou l'attente a dépassé le délai. Hérite de LlmUnavailableException pour que chaque
 * repli existant (règles Mavi'oh, saisie manuelle) s'applique sans changement ; le message, lui,
 * dit à la personne que ça vaut la peine de réessayer.
 */
class LlmBusyException extends LlmUnavailableException
{
    public const MESSAGE = 'L’IA est très demandée en ce moment. Réessaie dans une minute.';

    public function __construct(public readonly int $retryAfter = 60, ?Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, $previous);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => self::MESSAGE], 503, ['Retry-After' => (string) $this->retryAfter]);
    }
}
