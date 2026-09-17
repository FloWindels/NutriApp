<?php

namespace Database\Factories;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HouseholdMember>
 */
class HouseholdMemberFactory extends Factory
{
    protected $model = HouseholdMember::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'user_id' => User::factory(),
            'role' => HouseholdRole::Membre->value,
            'share_profile' => true,
            'joined_at' => now(),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role' => HouseholdRole::Proprietaire->value]);
    }
}
