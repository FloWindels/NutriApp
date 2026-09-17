<?php

namespace App\Services\Meals;

use App\Enums\MealType;
use App\Http\Resources\DaySummaryResource;
use App\Models\Meal;
use App\Models\User;
use App\Services\MealCalculator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lecture d'une journée de repas (brief §4.2) : repas avec éléments (et aliment) chargés,
 * triés par heure par défaut du type, + résumé nutritionnel de MealCalculator::daySummary().
 */
class MealDayService
{
    public function __construct(private readonly MealCalculator $calculator)
    {
    }

    /**
     * Journée complète prête à sérialiser (`data` de GET /meals, `day` des mutations).
     */
    public function summary(User $user, string $date): DaySummaryResource
    {
        return new DaySummaryResource([
            'summary' => $this->calculator->daySummary($user, $date),
            'meals' => $this->meals($user, $date),
        ]);
    }

    /**
     * Repas d'une journée, éléments et aliments chargés, ordonnés par heure du type.
     *
     * @return Collection<int, Meal>
     */
    public function meals(User $user, string $date): Collection
    {
        $meals = $this->baseQuery($user)
            ->where('date', $date)
            ->get();

        $order = $this->typeOrder();

        return $meals
            ->sortBy(fn (Meal $meal) => ($order[$this->typeValue($meal)] ?? 99) * 100000 + $meal->id)
            ->values();
    }

    /**
     * Un repas de l'utilisateur (404 « Introuvable. » sinon), éléments et aliments chargés.
     */
    public function meal(User $user, int $id): Meal
    {
        return $this->baseQuery($user)->findOrFail($id);
    }

    /**
     * Ordre d'affichage des types : rang par heure par défaut (petit_dejeuner, dejeuner, collation, diner).
     *
     * @return array<string, int>
     */
    public function typeOrder(): array
    {
        $hours = MealCalculator::DEFAULT_HOURS;
        asort($hours);

        return array_flip(array_keys($hours));
    }

    private function baseQuery(User $user)
    {
        return Meal::query()
            ->where('user_id', $user->id)
            ->with(['items' => fn ($q) => $q->orderBy('id'), 'items.food']);
    }

    private function typeValue(Meal $meal): string
    {
        return $meal->type instanceof MealType ? $meal->type->value : (string) $meal->type;
    }
}
