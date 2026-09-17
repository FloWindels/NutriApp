<?php

namespace App\Services\Sport;

use App\Models\User;
use App\Models\WorkoutSession;
use Carbon\CarbonImmutable;

/**
 * Série de jours consécutifs (terminant aujourd'hui, ou hier si rien aujourd'hui)
 * avec ≥ 1 séance/activité terminée d'au moins 10 minutes (brief §13.3).
 */
class StreakCalculator
{
    public const MIN_MINUTES = 10;

    public const LOOKBACK_DAYS = 400;

    public function streakDays(User $user, string $today): int
    {
        $dates = WorkoutSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'terminee')
            ->where('duration_min', '>=', self::MIN_MINUTES)
            ->where('date', '<=', $today)
            ->where('date', '>=', CarbonImmutable::parse($today)->subDays(self::LOOKBACK_DAYS)->toDateString())
            ->distinct()
            ->orderByDesc('date')
            ->pluck('date')
            ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : substr((string) $d, 0, 10))
            ->all();

        if ($dates === []) {
            return 0;
        }

        $set = array_fill_keys($dates, true);
        $cursor = CarbonImmutable::parse($today);

        if (! isset($set[$cursor->toDateString()])) {
            $cursor = $cursor->subDay();
            if (! isset($set[$cursor->toDateString()])) {
                return 0;
            }
        }

        $streak = 0;
        while (isset($set[$cursor->toDateString()])) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }
}
