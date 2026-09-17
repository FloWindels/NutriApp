<?php

namespace Tests\Feature\Planner;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\User;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/**
 * Aides partagées par les tests du module M7 (courses + planificateur).
 */
trait ModuleM7Helpers
{
    protected function userWithProfile(array $profile = []): User
    {
        $user = User::factory()->create();

        Profile::factory()->create(array_merge([
            'user_id' => $user->id,
            'poids' => 75,
            'taille' => 178,
            'age' => 32,
            'sexe' => 'homme',
            'objectif_type' => 'maintenir',
            'niveau_activite' => 'modere',
            'calories_cibles' => 2000,
            'proteines_cibles' => 120,
            'glucides_cibles' => 250,
            'lipides_cibles' => 65,
            'regime_alimentaire' => 'omnivore',
            'allergenes' => [],
            'aliments_exclus' => [],
        ], $profile));

        return $user->fresh();
    }

    protected function login(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Crée un foyer dont $owner est propriétaire et $members membres ; renseigne users.household_id.
     */
    protected function household(User $owner, User ...$members): Household
    {
        $household = Household::factory()->create(['owner_id' => $owner->id]);

        HouseholdMember::factory()->owner()->create(['household_id' => $household->id, 'user_id' => $owner->id]);
        $owner->forceFill(['household_id' => $household->id])->save();

        foreach ($members as $member) {
            HouseholdMember::factory()->create(['household_id' => $household->id, 'user_id' => $member->id]);
            $member->forceFill(['household_id' => $household->id])->save();
        }

        return $household;
    }

    protected function weekStart(User $user): string
    {
        return Clock::weekStart($user);
    }

    protected function weekDay(User $user, int $offset): string
    {
        return CarbonImmutable::parse($this->weekStart($user))->addDays($offset)->toDateString();
    }

    protected function publicRecipe(array $attributes = []): Recipe
    {
        return Recipe::factory()->create(array_merge([
            'servings' => 1,
            'is_public' => true,
            'tags' => [],
            'meal_types' => ['dejeuner', 'diner'],
        ], $attributes));
    }

    protected function personalStock(User $user, string $name = 'Frigo'): Stock
    {
        return Stock::factory()->create(['user_id' => $user->id, 'household_id' => null, 'name' => $name]);
    }
}
