<?php

namespace App\Support;

use Illuminate\Auth\Notifications\ResetPassword;

/**
 * URL de réinitialisation du mot de passe pointant vers le front
 * (`${FRONTEND_URL}/reset-password?token=…&email=…`, brief §1).
 *
 * Le module M1 ne possède pas les providers : le hook `ResetPassword::createUrlUsing()`
 * est donc posé ici et invoqué depuis le constructeur d'AuthController (idempotent).
 */
final class PasswordResetUrl
{
    public const DEFAULT_FRONTEND_URL = 'http://localhost:3000';

    private static bool $registered = false;

    /**
     * Enregistre (une seule fois par processus) la construction de l'URL du lien de réinitialisation.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        ResetPassword::createUrlUsing(
            fn ($notifiable, string $token): string => self::build($token, (string) $notifiable->getEmailForPasswordReset())
        );
    }

    /**
     * Base du front. `config('app.frontend_url')` si un jour la clé existe, sinon la variable
     * d'environnement FRONTEND_URL (défaut http://localhost:3000).
     */
    public static function frontendUrl(): string
    {
        $url = config('app.frontend_url') ?: env('FRONTEND_URL', self::DEFAULT_FRONTEND_URL);

        return rtrim((string) $url, '/');
    }

    public static function build(string $token, string $email): string
    {
        return self::frontendUrl().'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $email,
        ]);
    }
}
