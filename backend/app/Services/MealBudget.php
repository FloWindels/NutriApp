<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\User;

/**
 * Budget calorique par repas (brief §4.2) :
 * parts {petit_dejeuner .25, dejeuner .35, collation .10, diner .30} ; en jeûne intermittent,
 * un type dont l'heure par défaut sort de la fenêtre alimentaire a une part 0 (renormalisation).
 */
class MealBudget
{
    public const SHARES = [
        'petit_dejeuner' => 0.25,
        'dejeuner' => 0.35,
        'collation' => 0.10,
        'diner' => 0.30,
    ];

    public const BUDGET_MIN = 150.0;

    public const FENETRE_DEFAUT = ['debut' => '12:00', 'fin' => '20:00'];

    public function __construct(
        private readonly MealCalculator $calculator,
        private readonly NutritionCalculator $nutrition,
    ) {
    }

    /**
     * Budget restant pour un repas donné aujourd'hui (kcal, arrondi à l'unité).
     */
    public function forMeal(User $user, string $date, string $type): float
    {
        $summary = $this->calculator->daySummary($user, $date);

        return $this->budgetFromSummary(
            (float) ($summary['remaining']['calories'] ?? 0.0),
            $type,
            $summary['logged_types'] ?? [],
            $summary['regime'] ?? null,
        );
    }

    /**
     * Budget théorique d'un repas pour la planification : cibles × part(type).
     */
    public function forPlanning(User $user, string $type): float
    {
        $profile = $user->relationLoaded('profile')
            ? $user->getRelation('profile')
            : Profile::query()->where('user_id', $user->id)->first();

        if ($profile === null) {
            return 0.0;
        }

        $cibles = $this->nutrition->ciblesEffectives($profile);
        $calories = (float) ($cibles['calories'] ?? 0);

        return round($calories * $this->share($type, $profile->regime_alimentaire));
    }

    /**
     * Cœur pur (testable sans base) : `max(150, restant × part(type) / Σ parts des types non
     * enregistrés incl. type)` ; tout enregistré → `max(0, restant)` ; type hors fenêtre → 0.
     *
     * @param  array<int, string>  $loggedTypes
     */
    public function budgetFromSummary(float $remaining, string $type, array $loggedTypes, ?string $regime): float
    {
        $shares = $this->shares($regime);
        $shareType = $shares[$type] ?? 0.0;

        if ($shareType <= 0) {
            return 0.0;
        }

        $notLogged = array_filter(
            array_keys($shares),
            fn (string $t) => $shares[$t] > 0 && ! in_array($t, $loggedTypes, true)
        );

        if ($notLogged === []) {
            return round(max(0.0, $remaining));
        }

        $denominator = $shareType;
        foreach ($notLogged as $t) {
            if ($t !== $type) {
                $denominator += $shares[$t];
            }
        }

        return round(max(self::BUDGET_MIN, $remaining * $shareType / $denominator));
    }

    /**
     * Parts par type, renormalisées si le régime impose une fenêtre alimentaire.
     *
     * @return array<string, float>
     */
    public function shares(?string $regime): array
    {
        $shares = self::SHARES;

        if ($regime !== 'jeune_intermittent') {
            return $shares;
        }

        $fenetre = config('diets.jeune_intermittent.regles.fenetre_alimentaire', self::FENETRE_DEFAUT);
        $debut = (string) ($fenetre['debut'] ?? self::FENETRE_DEFAUT['debut']);
        $fin = (string) ($fenetre['fin'] ?? self::FENETRE_DEFAUT['fin']);

        foreach ($shares as $type => $share) {
            $heure = MealCalculator::defaultHour($type);
            if ($heure < $debut || $heure > $fin) {
                $shares[$type] = 0.0;
            }
        }

        $total = array_sum($shares);
        if ($total <= 0) {
            return self::SHARES;
        }

        foreach ($shares as $type => $share) {
            $shares[$type] = $share / $total;
        }

        return $shares;
    }

    public function share(string $type, ?string $regime): float
    {
        return $this->shares($regime)[$type] ?? 0.0;
    }
}
