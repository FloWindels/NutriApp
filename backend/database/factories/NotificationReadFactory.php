<?php

namespace Database\Factories;

use App\Models\NotificationRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationRead>
 */
class NotificationReadFactory extends Factory
{
    protected $model = NotificationRead::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => 'notif:'.fake()->unique()->sha1(),
            'read_at' => now(),
        ];
    }
}
