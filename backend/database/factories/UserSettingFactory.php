<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserSetting>
 */
class UserSettingFactory extends Factory
{
    protected $model = UserSetting::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'notif_peremption' => true,
            'notif_rappel_repas' => false,
            'notif_rappel_sport' => false,
            'heure_rappel' => null,
            'jours_alerte_peremption' => 3,
            'unites' => 'metrique',
            'theme' => 'systeme',
            'langue' => 'fr',
            'timezone' => 'Europe/Paris',
            'ia_seances' => true,
        ];
    }
}
