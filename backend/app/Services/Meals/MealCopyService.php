<?php

namespace App\Services\Meals;

use App\Enums\MealType;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\User;
use App\Services\MealService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POST /meals/copy (brief §4.2) : copie les instantanés des éléments d'une journée (ou d'un type)
 * vers une autre journée. Aucun effet sur le stock (les liens `stock_item_id` ne sont pas copiés).
 */
class MealCopyService
{
    public const MSG_RIEN_A_COPIER = 'Aucun repas à copier à cette date.';

    /** Colonnes d'instantané recopiées telles quelles. */
    private const COPIED_COLUMNS = [
        'food_id', 'recipe_id', 'source_type', 'label', 'quantity', 'unit', 'grams_equivalent',
        'calories', 'proteins', 'carbs', 'fat', 'fiber', 'sugar', 'salt',
        'ref_basis', 'ref_calories', 'ref_proteins', 'ref_carbs', 'ref_fat', 'ref_fiber', 'ref_sugar', 'ref_salt',
        'ref_serving_size_g', 'is_estimate',
    ];

    public function __construct(private readonly MealService $meals)
    {
    }

    /**
     * @return array{meals: int, items: int}  nombre de repas touchés et d'éléments copiés
     *
     * @throws ValidationException quand la journée d'origine n'a aucun élément à copier
     */
    public function copy(User $user, string $fromDate, string $toDate, ?string $type = null): array
    {
        $sources = Meal::query()
            ->where('user_id', $user->id)
            ->where('date', $fromDate)
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->with(['items' => fn ($q) => $q->orderBy('id')])
            ->get()
            ->filter(fn (Meal $meal) => $meal->getRelation('items')->isNotEmpty());

        if ($sources->isEmpty()) {
            throw ValidationException::withMessages(['from_date' => [self::MSG_RIEN_A_COPIER]]);
        }

        return DB::transaction(function () use ($user, $toDate, $sources) {
            $this->meals->ensureDailyTargets($user, $toDate);

            $copiedItems = 0;

            foreach ($sources as $source) {
                $typeValue = $source->type instanceof MealType ? $source->type->value : (string) $source->type;
                $target = $this->meals->findOrCreate($user, $toDate, $typeValue, $source->name);

                foreach ($source->getRelation('items') as $item) {
                    /** @var MealItem $item */
                    $attributes = $item->only(self::COPIED_COLUMNS);
                    $attributes['meal_id'] = $target->id;
                    $attributes['stock_item_id'] = null;

                    MealItem::query()->forceCreate($attributes);
                    $copiedItems++;
                }
            }

            return ['meals' => $sources->count(), 'items' => $copiedItems];
        });
    }
}
