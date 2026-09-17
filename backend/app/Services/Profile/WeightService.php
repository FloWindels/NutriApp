<?php

namespace App\Services\Profile;

use App\Models\Profile;
use App\Models\User;
use App\Models\WeightLog;
use App\Services\NutritionCalculator;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Journal de poids (brief §2.5) : upsert par (user, date), mise à jour de profiles.poids
 * quand la pesée est celle du jour, et recalcul des cibles avec hystérésis.
 */
class WeightService
{
    /** Écart de poids (kg) déclenchant un recalcul des cibles. */
    public const SEUIL_ECART_KG = 1.0;

    /** Ancienneté (jours) des cibles déclenchant un recalcul. */
    public const SEUIL_JOURS = 14;

    public function __construct(private readonly NutritionCalculator $nutrition)
    {
    }

    /**
     * @return Collection<int, WeightLog>
     */
    public function list(User $user, ?string $from = null, ?string $to = null): Collection
    {
        $query = $user->weightLogs()->orderBy('date');

        if ($from) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('date', '<=', $to);
        }

        return $query->get();
    }

    /**
     * Crée ou met à jour la pesée du jour donné (défaut : aujourd'hui côté utilisateur).
     *
     * @return array{log: WeightLog, created: bool, profil_mis_a_jour: bool, cibles_recalculees: bool}
     */
    public function upsert(User $user, ?string $date, float $weightKg): array
    {
        $today = Clock::today($user);
        $date = Clock::date($user, $date);

        return DB::transaction(function () use ($user, $date, $today, $weightKg) {
            $log = $user->weightLogs()->where('date', $date)->first();
            $created = $log === null;

            if ($created) {
                $log = new WeightLog(['date' => $date, 'weight_kg' => $weightKg]);
                $log->user_id = $user->id;
                $log->save();
            } else {
                $log->weight_kg = $weightKg;
                $log->save();
            }

            $profilMisAJour = false;
            $ciblesRecalculees = false;

            if ($date === $today) {
                $profile = $user->profile()->first();
                if ($profile) {
                    $profilMisAJour = true;
                    $ciblesRecalculees = $this->applyTodayWeight($profile, $weightKg, $today);
                }
            }

            return [
                'log' => $log->refresh(),
                'created' => $created,
                'profil_mis_a_jour' => $profilMisAJour,
                'cibles_recalculees' => $ciblesRecalculees,
            ];
        });
    }

    /**
     * Supprime la pesée d'une date ; 404 (ModelNotFoundException) si absente.
     */
    public function delete(User $user, string $date): void
    {
        $user->weightLogs()->where('date', $date)->firstOrFail()->delete();
    }

    /**
     * Poids le plus récent connu à une date (journal ≤ date, sinon profil), ou null.
     */
    public function latestWeight(User $user, string $date): ?float
    {
        $log = $user->weightLogs()->whereDate('date', '<=', $date)->orderByDesc('date')->first();
        if ($log) {
            return (float) $log->weight_kg;
        }

        $poids = $user->profile()->value('poids');

        return $poids === null ? null : (float) $poids;
    }

    // ------------------------------------------------------------------------------------

    /**
     * profiles.poids = poids du jour ; recalcul des cibles seulement si le calcul est automatique
     * et (écart ≥ 1 kg avec poids_reference ou cibles vieilles de ≥ 14 jours). Retourne true si recalculé.
     */
    private function applyTodayWeight(Profile $profile, float $weightKg, string $today): bool
    {
        $profile->poids = $weightKg;

        $recompute = false;
        if ($profile->objectif_calcul_auto) {
            $reference = $profile->poids_reference;
            $calculeesLe = $profile->cibles_calculees_le;

            $ecart = $reference === null ? null : abs($weightKg - (float) $reference);
            $age = $calculeesLe === null
                ? null
                : CarbonImmutable::parse($calculeesLe->format('Y-m-d'))->diffInDays(CarbonImmutable::parse($today), false);

            $recompute = $reference === null
                || $calculeesLe === null
                || $ecart >= self::SEUIL_ECART_KG
                || $age >= self::SEUIL_JOURS;
        }

        if ($recompute && $this->nutrition->isComplete($profile)) {
            $besoins = $this->nutrition->compute($this->nutrition->inputFromProfile($profile, $today));

            $profile->calories_cibles = (int) $besoins['calories_recommandees'];
            $profile->proteines_cibles = (int) $besoins['proteines_g'];
            $profile->glucides_cibles = (int) $besoins['glucides_g'];
            $profile->lipides_cibles = (int) $besoins['lipides_g'];
            $profile->poids_reference = $weightKg;
            $profile->cibles_calculees_le = $today;
        } else {
            $recompute = false;
        }

        $profile->save();

        return $recompute;
    }
}
