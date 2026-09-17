<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Exceptions "normales" du cycle HTTP : jamais journalisées.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        ValidationException::class,
        AuthenticationException::class,
        ModelNotFoundException::class,
        AuthorizationException::class,
    ];

    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Messages génériques par code HTTP pour les exceptions HTTP non couvertes explicitement.
     *
     * @var array<int, string>
     */
    private const HTTP_MESSAGES = [
        400 => 'Requête invalide.',
        405 => 'Méthode non autorisée.',
        408 => 'Délai d’attente dépassé.',
        409 => 'Conflit avec l’état actuel de la ressource.',
        410 => 'Ressource disparue.',
        413 => 'Contenu trop volumineux.',
        415 => 'Format non pris en charge.',
        419 => 'Session expirée.',
        422 => 'Données invalides.',
        423 => 'Ressource verrouillée.',
        500 => 'Erreur interne du serveur.',
        502 => 'Service tiers indisponible.',
        503 => 'Service temporairement indisponible.',
        504 => 'Délai d’attente dépassé.',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->renderable(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return new JsonResponse(['message' => 'Non authentifié.'], 401);
            }
        });

        $this->renderable(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return new JsonResponse(['message' => 'Introuvable.'], 404);
            }
        });

        $this->renderable(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return new JsonResponse(['message' => 'Action non autorisée.'], 403);
            }
        });

        $this->renderable(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return new JsonResponse(
                    ['message' => 'Trop de requêtes, réessaie dans une minute.'],
                    429,
                    $e->getHeaders()
                );
            }
        });

        // Filet de sécurité : toute autre erreur sur l'API devient un JSON propre.
        $this->renderable(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // Laissé au framework : 422 {message, errors} (traduit via lang/fr).
            if ($e instanceof ValidationException) {
                return null;
            }

            // Exceptions maison qui savent se rendre elles-mêmes (OffUnavailableException…).
            if (method_exists($e, 'render')) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                if ($status >= 500 && config('app.debug')) {
                    return null;
                }

                $message = $e->getMessage() !== '' && $status < 500
                    ? $e->getMessage()
                    : (self::HTTP_MESSAGES[$status] ?? 'Erreur.');

                return new JsonResponse(['message' => $message], $status, $e->getHeaders());
            }

            if (config('app.debug')) {
                return null;
            }

            return new JsonResponse(['message' => 'Erreur interne du serveur.'], 500);
        });
    }

    /**
     * Contexte de journalisation : jamais les données de la requête (santé, mots de passe).
     *
     * @return array<string, mixed>
     */
    protected function context(): array
    {
        try {
            return array_filter([
                'userId' => Auth::id(),
            ]);
        } catch (Throwable) {
            return [];
        }
    }
}
