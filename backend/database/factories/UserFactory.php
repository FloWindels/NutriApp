<?php

namespace Database\Factories;

use App\Enums\Offre;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offre' => Offre::Foyer->value,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    /**
     * Compte sur l'offre gratuite.
     *
     * La fabrique donne l'offre la plus haute par défaut : un « utilisateur » de test représente
     * quelqu'un qui a accès au produit, et les tests de verrouillage demandent explicitement
     * l'offre gratuite. L'inverse aurait obligé à retoucher des centaines d'appels existants.
     */
    public function gratuit(): static
    {
        return $this->state(fn () => ['offre' => Offre::Gratuit->value]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
