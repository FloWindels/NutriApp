<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserSetting;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

/**
 * Horloge « côté utilisateur » : le stockage est en UTC, mais « aujourd'hui », « après 20 h »
 * ou « début de semaine » se calculent dans le fuseau de l'utilisateur (user_settings.timezone,
 * défaut Europe/Paris). `now()->toDateString()` est interdit dans les contrôleurs/services.
 */
final class Clock
{
    public const DEFAULT_TIMEZONE = 'Europe/Paris';

    /** @var array<int, string> cache par requête (id utilisateur → fuseau) */
    private static array $timezones = [];

    /**
     * Fuseau horaire de l'utilisateur (validé, sinon Europe/Paris).
     */
    public static function timezone(User $user): string
    {
        $key = (int) $user->getKey();

        if ($key > 0 && isset(self::$timezones[$key])) {
            return self::$timezones[$key];
        }

        $tz = null;

        if ($user->relationLoaded('settings')) {
            $tz = $user->getRelation('settings')?->timezone;
        } elseif ($key > 0) {
            $tz = UserSetting::query()->where('user_id', $key)->value('timezone');
        }

        $tz = self::validTimezone($tz);

        if ($key > 0) {
            self::$timezones[$key] = $tz;
        }

        return $tz;
    }

    /**
     * Date du jour (Y-m-d) dans le fuseau de l'utilisateur.
     */
    public static function today(User $user): string
    {
        return self::now($user)->toDateString();
    }

    /**
     * Instant courant dans le fuseau de l'utilisateur.
     */
    public static function now(User $user): Carbon
    {
        return Carbon::now(self::timezone($user));
    }

    /**
     * Heure courante (0–23) dans le fuseau de l'utilisateur.
     */
    public static function hour(User $user): int
    {
        return (int) self::now($user)->format('G');
    }

    /**
     * Lundi de la semaine contenant $date (ou aujourd'hui) — Y-m-d.
     */
    public static function weekStart(User $user, ?string $date = null): string
    {
        $base = $date
            ? CarbonImmutable::parse($date, self::timezone($user))
            : CarbonImmutable::now(self::timezone($user));

        return $base->startOfWeek(CarbonInterface::MONDAY)->toDateString();
    }

    /**
     * Normalise une date saisie (Y-m-d ou vide) : retourne Y-m-d, aujourd'hui par défaut.
     */
    public static function date(User $user, ?string $date): string
    {
        if ($date === null || trim($date) === '') {
            return self::today($user);
        }

        return CarbonImmutable::parse($date, self::timezone($user))->toDateString();
    }

    /**
     * Vide le cache par requête (utile dans les tests ou après un changement de fuseau).
     */
    public static function forget(?User $user = null): void
    {
        if ($user === null) {
            self::$timezones = [];

            return;
        }

        unset(self::$timezones[(int) $user->getKey()]);
    }

    private static function validTimezone(mixed $tz): string
    {
        if (! is_string($tz) || $tz === '') {
            return self::DEFAULT_TIMEZONE;
        }

        try {
            new DateTimeZone($tz);

            return $tz;
        } catch (Throwable) {
            return self::DEFAULT_TIMEZONE;
        }
    }
}
