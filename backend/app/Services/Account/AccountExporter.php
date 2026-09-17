<?php

namespace App\Services\Account;

use App\Models\Meal;
use App\Models\MealPlan;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\Recommendation;
use App\Models\ShoppingItem;
use App\Models\SportPlan;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\WeightLog;
use App\Models\WorkoutSession;
use App\Support\OwnerScope;
use App\Support\StockScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Export des données du compte (brief §1, GET /account/export). Les valeurs passent par les
 * casts des modèles (décimaux → float, dates → Y-m-d, json → tableaux).
 */
class AccountExporter
{
    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $userId = (int) $user->id;

        $profile = Profile::query()->where('user_id', $userId)->first();
        $settings = UserSetting::query()->where('user_id', $userId)->first();

        return [
            'user' => [
                'id' => $userId,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'consentement_sante_at' => $user->consentement_sante_at?->toISOString(),
                'household_id' => $user->household_id !== null ? (int) $user->household_id : null,
                'created_at' => $user->created_at?->toISOString(),
                'updated_at' => $user->updated_at?->toISOString(),
            ],
            'profile' => $profile?->toArray(),
            'settings' => $settings?->toArray(),
            'meals' => $this->rows(
                Meal::query()->where('user_id', $userId)->with('items')->orderBy('date')->orderBy('id')->get()
            ),
            'weights' => $this->rows(
                WeightLog::query()->where('user_id', $userId)->orderBy('date')->get()
            ),
            'stocks' => $this->rows(
                StockScope::query($user)->with('items')->orderBy('id')->get()
            ),
            'recipes' => $this->rows(
                Recipe::query()->where('created_by_user_id', $userId)->orderBy('id')->get()
            ),
            'shopping' => $this->rows(
                OwnerScope::apply(ShoppingItem::query(), $user)->orderBy('id')->get()
            ),
            'plans' => $this->rows(
                OwnerScope::apply(MealPlan::query(), $user)->orderBy('date')->orderBy('id')->get()
            ),
            'sessions' => $this->rows(
                WorkoutSession::query()->where('user_id', $userId)->with('exercises')->orderBy('date')->orderBy('id')->get()
            ),
            'sport_plans' => $this->rows(
                SportPlan::query()->where('user_id', $userId)->orderBy('date')->orderBy('id')->get()
            ),
            'recommendations' => $this->rows(
                Recommendation::query()->where('user_id', $userId)->orderBy('date')->orderBy('priority')->get()
            ),
            'exported_at' => now()->toISOString(),
        ];
    }

    /**
     * @param  Collection<int, Model>  $models
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $models): array
    {
        return $models->map(fn (Model $m): array => $m->toArray())->values()->all();
    }
}
