<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Catalogues (toujours) + données de démonstration (hors production).
     */
    public function run(): void
    {
        $this->call([
            ExerciseSeeder::class,
            SportSeeder::class,
        ]);

        if (app()->environment() !== 'production') {
            $this->call(DemoSeeder::class);
        }
    }
}
