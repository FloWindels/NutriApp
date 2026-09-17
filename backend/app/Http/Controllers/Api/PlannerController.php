<?php

namespace App\Http\Controllers\Api;

use App\Enums\MealType;
use App\Enums\PlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planner\GeneratePlannerRequest;
use App\Http\Requests\Planner\LogMealPlanRequest;
use App\Http\Requests\Planner\StoreMealPlanRequest;
use App\Http\Requests\Planner\UpdateMealPlanRequest;
use App\Http\Resources\MealPlanResource;
use App\Models\Food;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Planner\PlanCalories;
use App\Services\Planner\PlanLogger;
use App\Services\Planner\PlannerGenerator;
use App\Support\Clock;
use App\Support\OwnerScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Planificateur de la semaine (brief §12) — portée foyer ou personnelle via OwnerScope.
 */
class PlannerController extends Controller
{
    public const DEFAULT_GENERATE_TYPES = ['dejeuner', 'diner'];

    public function __construct(
        private readonly PlannerGenerator $generator,
        private readonly PlanLogger $logger,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate(['week_start' => ['nullable', 'date_format:Y-m-d']], [], ['week_start' => 'début de semaine']);

        $weekStart = Clock::weekStart($user, $validated['week_start'] ?? null);

        return response()->json(['data' => $this->weekPayload($user, $weekStart)]);
    }

    public function store(StoreMealPlanRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        [$recipe, $food, $title] = $this->resolveTarget($user, $data);

        $plan = MealPlan::query()->forceCreate(OwnerScope::ownerAttributes($user) + [
            'date' => $data['date'],
            'meal_type' => $data['meal_type'],
            'recipe_id' => $recipe?->id,
            'food_id' => $food?->id,
            'title' => $title,
            'servings' => isset($data['servings']) ? round((float) $data['servings'], 1) : 1,
            'notes' => $this->cleanText($data['notes'] ?? null, 1000),
            'status' => PlanStatus::Prevu->value,
        ]);

        $plan->setRelation('recipe', $recipe);
        $plan->setRelation('food', $food);

        return response()->json([
            'message' => 'Repas planifié.',
            'data' => (new MealPlanResource($plan))->resolve(),
        ], 201);
    }

    public function update(UpdateMealPlanRequest $request, int $plan): JsonResponse
    {
        $user = $request->user();
        $model = $this->scoped($user)->with(['recipe', 'food'])->findOrFail($plan);
        $data = $request->validated();

        $changes = [];

        foreach (['date', 'meal_type', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }
        if (array_key_exists('servings', $data)) {
            $changes['servings'] = $data['servings'] === null ? 1 : round((float) $data['servings'], 1);
        }
        if (array_key_exists('notes', $data)) {
            $changes['notes'] = $this->cleanText($data['notes'], 1000);
        }

        $targetChanged = array_key_exists('recipe_id', $data) || array_key_exists('food_id', $data);
        if ($targetChanged) {
            $merged = [
                'recipe_id' => array_key_exists('recipe_id', $data) ? $data['recipe_id'] : null,
                'food_id' => array_key_exists('food_id', $data) ? $data['food_id'] : null,
                'title' => array_key_exists('title', $data) ? $data['title'] : $model->title,
            ];
            [$recipe, $food, $title] = $this->resolveTarget($user, $merged);
            $changes['recipe_id'] = $recipe?->id;
            $changes['food_id'] = $food?->id;
            $changes['title'] = $title;
            $model->setRelation('recipe', $recipe);
            $model->setRelation('food', $food);
        } elseif (array_key_exists('title', $data)) {
            $title = $this->cleanText($data['title'], 255);
            if ($title !== null) {
                $changes['title'] = $title;
            }
        }

        if ($changes !== []) {
            $model->forceFill($changes)->save();
        }

        return response()->json([
            'message' => 'Plan mis à jour.',
            'data' => (new MealPlanResource($model))->resolve(),
        ]);
    }

    public function destroy(Request $request, int $plan): JsonResponse
    {
        $model = $this->scoped($request->user())->findOrFail($plan);
        $model->delete();

        return response()->json(['message' => 'Plan supprimé.']);
    }

    public function generate(GeneratePlannerRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $weekStart = Clock::weekStart($user, $data['week_start'] ?? null);
        $types = array_values(array_unique($data['meal_types'] ?? self::DEFAULT_GENERATE_TYPES));
        $replace = filter_var($data['replace'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $count = $this->generator->generate($user, $weekStart, $types, $replace);

        $message = match (true) {
            $count === 0 => 'Aucun créneau à compléter : ta semaine est déjà planifiée.',
            $count === 1 => '1 repas planifié.',
            default => $count.' repas planifiés.',
        };

        return response()->json([
            'message' => $message,
            'data' => $this->weekPayload($user, $weekStart),
            'generated_count' => $count,
        ]);
    }

    public function log(LogMealPlanRequest $request, int $plan): JsonResponse
    {
        $user = $request->user();
        $model = $this->scoped($user)->with(['recipe', 'food'])->findOrFail($plan);

        $decrement = filter_var($request->validated()['decrement_stock'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $result = $this->logger->log($user, $model, $decrement);

        return response()->json([
            'message' => 'Repas enregistré dans ta journée.',
            'data' => (new MealPlanResource($result['plan']))->resolve(),
            'day' => $this->logger->dayPayload($user, $result['plan']->date->format('Y-m-d')),
            'stock_decrements' => $result['stock_decrements'],
        ]);
    }

    // ------------------------------------------------------------------------------------

    /**
     * @return Builder<MealPlan>
     */
    private function scoped(User $user): Builder
    {
        return OwnerScope::apply(MealPlan::query(), $user);
    }

    /**
     * Payload GET /planner : week_start, 7 jours × 4 créneaux, totaux caloriques par jour.
     *
     * @return array{week_start: string, days: list<array<string, mixed>>, totals_per_day: list<array{date: string, calories: float}>}
     */
    private function weekPayload(User $user, string $weekStart): array
    {
        $start = CarbonImmutable::parse($weekStart);
        $end = $start->addDays(6)->toDateString();

        $plans = $this->scoped($user)
            ->with(['recipe', 'food'])
            ->whereBetween('date', [$start->toDateString(), $end])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $byDate = $plans->groupBy(fn (MealPlan $plan) => $plan->date->format('Y-m-d'));

        $days = [];
        $totals = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $start->addDays($i)->toDateString();
            $slots = [];
            foreach (MealType::values() as $type) {
                $slots[$type] = [];
            }

            $calories = 0.0;
            foreach ($byDate->get($date, collect()) as $plan) {
                /** @var MealPlan $plan */
                $type = (string) $plan->meal_type;
                if (! array_key_exists($type, $slots)) {
                    $slots[$type] = [];
                }
                $slots[$type][] = (new MealPlanResource($plan))->resolve();

                if ($plan->status !== PlanStatus::Annule->value) {
                    $calories += (float) (PlanCalories::forPlan($plan)['calories'] ?? 0.0);
                }
            }

            $days[] = ['date' => $date, 'slots' => $slots];
            $totals[] = ['date' => $date, 'calories' => round($calories, 1)];
        }

        return [
            'week_start' => $start->toDateString(),
            'days' => $days,
            'totals_per_day' => $totals,
        ];
    }

    /**
     * Recette visible (publique ou à moi → sinon 404), aliment, ou titre libre.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Recipe|null, 1: Food|null, 2: string}
     */
    private function resolveTarget(User $user, array $data): array
    {
        $recipe = null;
        $food = null;

        if (! empty($data['recipe_id'])) {
            $recipe = Recipe::query()
                ->where(function ($q) use ($user) {
                    $q->where('is_public', true)->orWhere('created_by_user_id', $user->id);
                })
                ->findOrFail((int) $data['recipe_id']);
        } elseif (! empty($data['food_id'])) {
            $food = Food::query()->findOrFail((int) $data['food_id']);
        }

        $title = $this->cleanText($data['title'] ?? null, 255)
            ?? $recipe?->title
            ?? $food?->name
            ?? 'Repas';

        return [$recipe, $food, mb_substr($title, 0, 255)];
    }

    private function cleanText(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
