<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Household>
 */
class HouseholdFactory extends Factory
{
    protected $model = Household::class;

    public function definition(): array
    {
        return [
            'name' => 'Foyer '.fake()->lastName(),
            'owner_id' => User::factory(),
            'invite_code' => Household::generateInviteCode(),
        ];
    }
}
