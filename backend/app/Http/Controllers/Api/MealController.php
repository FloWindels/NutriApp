<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meals\CopyMealsRequest;
use App\Http\Requests\Meals\MealHistoryRequest;
use App\Http\Requests\Meals\StoreMealItemRequest;
use App\Http\Requests\Meals\StoreMealRequest;
use App\Http\Requests\Meals\UpdateMealItemRequest;
use App\Http\Requests\Meals\UpdateMealRequest;
use App\Http\Resources\MealItemResource;
use App\Http\Resources\MealResource;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\User;
use App\Services\MealCalculator;
use App\Services\Meals\MealCopyService;
use App\Services\Meals\MealDayService;
use App\Services\Meals\MealHistoryService;
use App\Services\MealService;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Repas & suivi quotidien (brief §4, module M4). Routes dans routes/api/meals.php.
 * Les repas sont toujours chargés via l'utilisateur : un id étranger donne 404 « Introuvable. ».
 */
class MealController extends Controller
{
    public function __construct(
        private readonly MealService $meals,
        private readonly MealCalculator $calculator,
        private readonly MealDayService $days,
        private readonly MealHistoryService $history,
        private readonly MealCopyService $copier,
    ) {
    }

    /**
     * GET /meals?date= → {data: {date, meals, totals, targets, remaining, sport, next_meal_type, plancher_kcal}}
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $user = $request->user();
        $date = Clock::date($user, $request->query('date'));

        return $this->json(['data' => $this->days->summary($user, $date)]);
    }

    /**
     * POST /meals {date, type, name?, items?} → 201 si le repas est créé, 200 sinon.
     */
    public function store(StoreMealRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $date = Clock::date($user, $data['date']);
        $items = $data['items'] ?? [];

        [$mealId, $created, $decrements] = DB::transaction(function () use ($user, $date, $data, $items) {
            $meal = $this->meals->findOrCreate($user, $date, $data['type'], $data['name'] ?? null);
            $created = $meal->wasRecentlyCreated;
            $decrements = [];

            if ($items !== []) {
                $meal->setRelation('user', $user);
                [, $decrements] = $this->meals->addItems($meal, $items);
            }

            return [$meal->id, $created, $decrements];
        });

        return $this->json([
            'message' => $created ? 'Repas enregistré.' : 'Repas mis à jour.',
            'data' => new MealResource($this->days->meal($user, $mealId)),
            'day' => $this->days->summary($user, $date),
            'stock_decrements' => array_values($decrements),
        ], $created ? 201 : 200);
    }

    /**
     * POST /meals/{meal}/items → 201 {message, data: MealItemResource, day, stock_decrement}
     */
    public function storeItem(StoreMealItemRequest $request, int $meal): JsonResponse
    {
        $user = $request->user();
        $mealModel = $this->findMeal($user, $meal);
        $mealModel->setRelation('user', $user);

        [$items, $decrements] = $this->meals->addItems($mealModel, [$request->validated()]);

        /** @var MealItem $item */
        $item = $items[0];
        $item->load('food');

        return $this->json([
            'message' => 'Élément ajouté au repas.',
            'data' => new MealItemResource($item),
            'day' => $this->days->summary($user, $this->dateOf($mealModel)),
            'stock_decrement' => $decrements[0] ?? null,
        ], 201);
    }

    /**
     * PUT /meals/{meal}/items/{item} {quantity, unit} → recalcul depuis les ref_* de l'élément uniquement.
     */
    public function updateItem(UpdateMealItemRequest $request, int $meal, int $item): JsonResponse
    {
        $user = $request->user();
        $mealModel = $this->findMeal($user, $meal);
        $itemModel = $this->findItem($mealModel, $item);
        $data = $request->validated();

        // Une recette se compte toujours en portions ; le recalcul n'utilise jamais l'aliment actuel.
        $unit = $itemModel->ref_basis === 'per_serving' ? 'portion' : (string) $data['unit'];

        $itemModel->forceFill($this->calculator->recompute($itemModel, (float) $data['quantity'], $unit))->save();
        $itemModel->load('food');

        return $this->json([
            'message' => 'Quantité mise à jour.',
            'data' => new MealItemResource($itemModel),
            'day' => $this->days->summary($user, $this->dateOf($mealModel)),
        ]);
    }

    /**
     * DELETE /meals/{meal}/items/{item} → {message, day}
     */
    public function destroyItem(Request $request, int $meal, int $item): JsonResponse
    {
        $user = $request->user();
        $mealModel = $this->findMeal($user, $meal);
        $this->findItem($mealModel, $item)->delete();

        return $this->json([
            'message' => 'Élément supprimé.',
            'day' => $this->days->summary($user, $this->dateOf($mealModel)),
        ]);
    }

    /**
     * PUT /meals/{meal} {name?, notes?, consumed_at?} → {message, data: MealResource}
     */
    public function update(UpdateMealRequest $request, int $meal): JsonResponse
    {
        $user = $request->user();
        $mealModel = $this->findMeal($user, $meal);
        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $name = $data['name'] === null ? null : trim((string) $data['name']);
            $data['name'] = $name === '' ? null : $name;
        }

        // Stockage en UTC : une heure sans décalage est interprétée dans le fuseau de l'utilisateur.
        if (array_key_exists('consumed_at', $data) && $data['consumed_at'] !== null) {
            $data['consumed_at'] = CarbonImmutable::parse((string) $data['consumed_at'], Clock::timezone($user))->utc();
        }

        $mealModel->fill($data)->save();

        return $this->json([
            'message' => 'Repas mis à jour.',
            'data' => new MealResource($this->days->meal($user, $mealModel->id)),
        ]);
    }

    /**
     * DELETE /meals/{meal} → {message} (suppression explicite des éléments, sans compter sur la cascade).
     */
    public function destroy(Request $request, int $meal): JsonResponse
    {
        $mealModel = $this->findMeal($request->user(), $meal);

        DB::transaction(function () use ($mealModel) {
            MealItem::query()->where('meal_id', $mealModel->id)->delete();
            $mealModel->delete();
        });

        return $this->json(['message' => 'Repas supprimé.']);
    }

    /**
     * POST /meals/copy {from_date, to_date, type?} → {message, day, copied_count}
     */
    public function copy(CopyMealsRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $from = Clock::date($user, $data['from_date']);
        $to = Clock::date($user, $data['to_date']);

        $result = $this->copier->copy($user, $from, $to, $data['type'] ?? null);

        return $this->json([
            'message' => 'Repas copiés.',
            'day' => $this->days->summary($user, $to),
            'copied_count' => $result['items'],
        ]);
    }

    /**
     * GET /meals/history?from=&to= → {data: [rows], from, to}
     */
    public function history(MealHistoryRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        [$from, $to] = $this->history->resolveRange($user, $data['from'] ?? null, $data['to'] ?? null);

        return $this->json([
            'data' => $this->history->rows($user, $from, $to),
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * GET /meals/frequent → {data: [{kind, id, label, brand, last_quantity, last_unit, calories_per_100g, calories_per_serving, count}]}
     */
    public function frequent(Request $request): JsonResponse
    {
        return $this->json(['data' => $this->history->frequent($request->user())]);
    }

    // ------------------------------------------------------------------------------------

    /**
     * Réponse JSON conservant les fractions nulles (`250.0` reste un nombre à virgule côté client).
     *
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    private function findMeal(User $user, int $id): Meal
    {
        return $user->meals()->findOrFail($id);
    }

    private function findItem(Meal $meal, int $id): MealItem
    {
        return MealItem::query()->where('meal_id', $meal->id)->findOrFail($id);
    }

    private function dateOf(Meal $meal): string
    {
        return $meal->date instanceof \DateTimeInterface ? $meal->date->format('Y-m-d') : (string) $meal->date;
    }
}
