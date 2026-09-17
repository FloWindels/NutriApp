<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    public function definition(): array
    {
        $sexe = fake()->randomElement(['homme', 'femme']);
        $poids = fake()->randomFloat(1, 55, 95);

        return [
            'user_id' => User::factory(),
            'poids' => $poids,
            'poids_souhaite_kg' => $poids,
            'delai_objectif_jours' => null,
            'taille' => fake()->numberBetween(158, 188),
            'age' => fake()->numberBetween(20, 55),
            'sexe' => $sexe,
            'objectif' => 'Rester en forme',
            'objectif_type' => 'maintenir',
            'niveau_activite' => fake()->randomElement(['sedentaire', 'leger', 'modere']),
            'objectif_calcul_auto' => true,
            'objectif_date_debut' => null,
            'objectif_date_fin' => null,
            'poids_reference' => $poids,
            'cibles_calculees_le' => now()->toDateString(),
            'calories_cibles' => fake()->numberBetween(1700, 2600),
            'proteines_cibles' => fake()->numberBetween(90, 160),
            'glucides_cibles' => fake()->numberBetween(180, 320),
            'lipides_cibles' => fake()->numberBetween(50, 90),
            'regime_alimentaire' => 'omnivore',
            'allergenes' => [],
            'aliments_exclus' => [],
            'preferences' => ['aime' => [], 'evite' => []],
            'sport_niveau' => fake()->randomElement(['debutant', 'intermediaire']),
            'sport_objectif' => 'forme',
            'sport_materiel' => ['aucun'],
            'sport_temps_dispo_min' => 30,
            'sport_jours_semaine' => 3,
            'sport_lieu' => 'maison',
            'sport_zones_a_eviter' => [],
            'sport_focus' => [],
            'sport_notes' => null,
            'sport_coef_calories' => 100,
            'situation_particuliere' => 'aucune',
            'consentement_parental' => false,
        ];
    }

    public function perdre(float $kg = 5.0, int $jours = 90): static
    {
        return $this->state(fn (array $attributes) => [
            'objectif_type' => 'perdre',
            'poids_souhaite_kg' => round(($attributes['poids'] ?? 80) - $kg, 1),
            'delai_objectif_jours' => $jours,
            'objectif_date_debut' => now()->toDateString(),
            'objectif_date_fin' => now()->addDays($jours)->toDateString(),
        ]);
    }

    public function mineur(int $age = 15): static
    {
        return $this->state(fn () => [
            'age' => $age,
            'objectif_type' => 'maintenir',
            'consentement_parental' => $age < 15,
        ]);
    }
}
