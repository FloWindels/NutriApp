<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * Open Food Facts injoignable (réseau, délai dépassé, erreur 5xx).
 * Rendu en 502 {message:'Open Food Facts indisponible.'} sur l'API.
 */
class OffUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Open Food Facts indisponible.', ?Throwable $previous = null)
    {
        parent::__construct($message, 502, $previous);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => 'Open Food Facts indisponible.'], 502);
    }
}
