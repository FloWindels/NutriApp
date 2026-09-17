<?php

namespace App\Services\Sport;

use App\Models\Profile;
use App\Models\Sport;
use App\Models\User;
use App\Models\WeightLog;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use Illuminate\Support\Str;

/**
 * Calories nettes (hors métabolisme de base) — addendum §B :
 *   net = (MET − 1) × poids_kg × heures
 * - activité libre : MET du sport selon l'intensité (défaut modérée) ;
 * - séance structurée : MET pondéré par la durée estimée de chaque exercice (défauts par catégorie :
 *   force 3,5 / 5,0 / 6,0 selon le niveau, cardio 7,0, gainage 3,0, mobilité 2,5),
 *   modulé par le RPE (×0,85 si ≤ 4, ×1 si 5–7, ×1,15 si ≥ 8).
 * Poids = dernière pesée ≤ date, sinon profiles.poids, sinon 70 kg.
 */
class CaloriesEstimator
{
    public const DEFAULT_WEIGHT_KG = 70.0;

    public const FORCE_MET_BY_LEVEL = ['debutant' => 3.5, 'intermediaire' => 5.0, 'avance' => 6.0];

    public const CATEGORY_MET = ['cardio' => 7.0, 'gainage' => 3.0, 'mobilite' => 2.5];

    /** Secondes de travail estimées par répétition quand aucune durée n'est donnée. */
    public const SECONDS_PER_REP = 3;

    public function weightFor(User $user, string $date): float
    {
        $log = WeightLog::query()
            ->where('user_id', $user->id)
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->value('weight_kg');

        if ($log !== null && (float) $log > 0) {
            return round((float) $log, 2);
        }

        $poids = $user->relationLoaded('profile')
            ? $user->getRelation('profile')?->poids
            : Profile::query()->where('user_id', $user->id)->value('poids');

        return $poids !== null && (float) $poids > 0 ? round((float) $poids, 2) : self::DEFAULT_WEIGHT_KG;
    }

    /**
     * Calories nettes pour une durée (minutes) à MET constant.
     */
    public function net(float $met, float $weightKg, float $minutes): float
    {
        if ($minutes <= 0 || $weightKg <= 0) {
            return 0.0;
        }

        return round(max(0.0, ($met - 1.0) * $weightKg * $minutes / 60), 1);
    }

    public function rpeFactor(?int $rpe): float
    {
        if ($rpe === null) {
            return 1.0;
        }

        return $rpe <= 4 ? 0.85 : ($rpe >= 8 ? 1.15 : 1.0);
    }

    /**
     * MET d'un sport (catalogue ou libellé libre) pour une intensité ; « autre » 4/6/8 en repli.
     */
    public function metForSport(?Sport $sport, ?string $sportName, ?string $intensity): float
    {
        $intensity = in_array($intensity, ['faible', 'moderee', 'elevee'], true) ? $intensity : 'moderee';

        if ($sport !== null) {
            return $sport->metFor($intensity);
        }

        if ($sportName !== null && trim($sportName) !== '') {
            $triplet = SportVocab::metTripletForSlug(Str::slug($sportName));
            if ($triplet !== null) {
                return $triplet[$intensity];
            }
        }

        return SportVocab::metTripletForType('autre')[$intensity];
    }

    public function defaultExerciseMet(?string $category, ?string $level): float
    {
        if ($category !== null && isset(self::CATEGORY_MET[$category])) {
            return self::CATEGORY_MET[$category];
        }

        return self::FORCE_MET_BY_LEVEL[$level ?? 'intermediaire'] ?? 5.0;
    }

    /**
     * Activité libre : (MET − 1) × poids × durée.
     */
    public function forActivity(float $weightKg, ?Sport $sport, ?string $sportName, int $durationMin, ?string $intensity): float
    {
        return $this->net($this->metForSport($sport, $sportName, $intensity), $weightKg, $durationMin);
    }

    /**
     * Durée estimée d'un exercice (secondes) : séries × (travail + repos).
     *
     * @param  array<string, mixed>|WorkoutExercise  $ex
     */
    public function estimatedSeconds(array|WorkoutExercise $ex): int
    {
        $get = fn (string $key) => $ex instanceof WorkoutExercise ? $ex->{$key} : ($ex[$key] ?? null);

        $sets = max(1, (int) ($get('sets') ?? 1));
        $work = $get('duration_sec') !== null
            ? (int) $get('duration_sec')
            : max(1, (int) ($get('reps') ?? 10)) * self::SECONDS_PER_REP;
        $rest = (int) ($get('rest_sec') ?? 0);

        return max(1, $sets * ($work + $rest));
    }

    /**
     * MET moyen pondéré par la durée estimée des exercices (défauts par catégorie/niveau).
     *
     * @param  iterable<array<string, mixed>|WorkoutExercise>  $exercises
     */
    public function weightedMet(iterable $exercises, ?string $level): ?float
    {
        $sum = 0.0;
        $seconds = 0;

        foreach ($exercises as $ex) {
            $met = $ex instanceof WorkoutExercise ? $ex->met : ($ex['met'] ?? null);
            $category = $ex instanceof WorkoutExercise ? null : ($ex['category'] ?? null);
            $met = $met !== null && (float) $met > 0 ? (float) $met : $this->defaultExerciseMet($category, $level);
            $sec = $this->estimatedSeconds($ex);

            $sum += $met * $sec;
            $seconds += $sec;
        }

        return $seconds > 0 ? round($sum / $seconds, 2) : null;
    }

    /**
     * Séance structurée ; sans exercice : MET du sport (intensité) ou défaut du niveau.
     * Le poids doit être fourni par l'appelant (weightFor) pour éviter tout lazy loading.
     */
    public function forSession(WorkoutSession $session, float $weightKg): float
    {
        $session->loadMissing('exercises');
        $exercises = $session->getRelation('exercises');

        if ($exercises->isEmpty()) {
            $sport = $session->relationLoaded('sport')
                ? $session->getRelation('sport')
                : ($session->sport_id ? Sport::query()->find($session->sport_id) : null);

            $met = ($sport !== null || filled($session->sport_name))
                ? $this->metForSport($sport, $session->sport_name, $session->intensity)
                : $this->defaultExerciseMet(null, $session->level);
        } else {
            $met = $this->weightedMet($exercises, $session->level) ?? $this->defaultExerciseMet(null, $session->level);
        }

        $base = $this->net($met, $weightKg, (int) $session->duration_min);

        return round($base * $this->rpeFactor($session->rpe), 1);
    }

    /**
     * Estimation d'une proposition (blocs → exercices) sur la durée annoncée.
     *
     * @param  list<array{exercises?: list<array<string, mixed>>}>  $blocks
     */
    public function forProposal(array $blocks, ?string $level, int $durationMin, float $weightKg): float
    {
        $exercises = [];
        foreach ($blocks as $block) {
            foreach ($block['exercises'] ?? [] as $ex) {
                $exercises[] = $ex;
            }
        }

        $met = $this->weightedMet($exercises, $level) ?? $this->defaultExerciseMet(null, $level);

        return $this->net($met, $weightKg, $durationMin);
    }
}
