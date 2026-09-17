<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * Couplage sport ↔ budget calorique (addendum §B, remplace §13.3) :
 * bonus = round(min(Σ calories_burned des séances terminées du jour, 1500) × coef / 100),
 * coef = profiles.sport_coef_calories (50|75|100, défaut 100).
 */
class SportNutrition
{
    public const CAP_KCAL = 1500;
    public const COEFFICIENTS = [50, 75, 100];
    public const COEFFICIENT_DEFAUT = 100;

    /**
     * @return array{calories_burned: float, calories_bonus: int, coefficient: int, explication: string, is_estimate: bool}
     */
    public function bonusForDay(User $user, string $date): array
    {
        $sessions = WorkoutSession::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->where('status', 'terminee')
            ->get(['id', 'calories_burned', 'calories_source']);

        $burned = round((float) $sessions->sum(fn ($s) => (float) ($s->calories_burned ?? 0)), 1);
        $coefficient = $this->coefficient($user);
        $bonus = (int) round(min($burned, self::CAP_KCAL) * $coefficient / 100);

        $isEstimate = $sessions->contains(fn ($s) => ($s->calories_source ?? 'auto') === 'auto');

        return [
            'calories_burned' => $burned,
            'calories_bonus' => $bonus,
            'coefficient' => $coefficient,
            'explication' => $this->explication($user, $date, $burned, $coefficient, $bonus),
            'is_estimate' => $isEstimate,
        ];
    }

    /**
     * Coefficient de réintégration (pourcentage) du profil, borné aux valeurs autorisées.
     */
    public function coefficient(User $user): int
    {
        $profile = $user->relationLoaded('profile')
            ? $user->getRelation('profile')
            : Profile::query()->where('user_id', $user->id)->first(['id', 'user_id', 'sport_coef_calories', 'niveau_activite']);

        $coef = (int) ($profile?->sport_coef_calories ?? self::COEFFICIENT_DEFAUT);

        return in_array($coef, self::COEFFICIENTS, true) ? $coef : self::COEFFICIENT_DEFAUT;
    }

    private function explication(User $user, string $date, float $burned, int $coefficient, int $bonus): string
    {
        $quand = $date === Clock::today($user)
            ? 'aujourd’hui'
            : 'le '.CarbonImmutable::parse($date)->locale('fr')->translatedFormat('j F');

        if ($burned <= 0) {
            return sprintf('Aucune séance terminée %s : aucune calorie réintégrée dans ton budget.', $quand);
        }

        $texte = sprintf(
            '%s kcal brûlées %s (estimation MET, hors métabolisme de base) : %d %% sont ajoutées à ton budget, soit +%d kcal à consommer.',
            $this->fr($burned),
            $quand,
            $coefficient,
            $bonus
        );

        if ($burned > self::CAP_KCAL) {
            $texte .= sprintf(' Plafond de %s kcal appliqué.', $this->fr(self::CAP_KCAL));
        }

        $niveau = $this->niveauActivite($user);
        if (in_array($niveau, ['eleve', 'tres_eleve'], true)) {
            $texte .= sprintf(
                ' Ton niveau d’activité « %s » inclut déjà du sport : attention au double comptage, choisis un niveau ≤ modéré si tu enregistres tes séances.',
                $niveau === 'eleve' ? 'élevé' : 'très élevé'
            );
        }

        return $texte;
    }

    private function niveauActivite(User $user): ?string
    {
        if ($user->relationLoaded('profile')) {
            return $user->getRelation('profile')?->niveau_activite;
        }

        return Profile::query()->where('user_id', $user->id)->value('niveau_activite');
    }

    private function fr(float $value): string
    {
        $isInt = abs($value - round($value)) < 0.05;

        return number_format($value, $isInt ? 0 : 1, ',', ' ');
    }
}
