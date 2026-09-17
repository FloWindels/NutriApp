<?php

namespace Database\Seeders;

use App\Models\Sport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Catalogue public des sports avec MET par intensité (faible / modérée / élevée).
 * Idempotent : upsert par slug.
 */
class SportSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $rows = collect(self::catalog())->map(function (array $s) use ($now) {
            return [
                'name' => $s[0],
                'slug' => Str::slug($s[0]),
                'category' => $s[1],
                'met_faible' => $s[2],
                'met_moderee' => $s[3],
                'met_elevee' => $s[4],
                'icon' => $s[5],
                'is_public' => true,
                'created_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });

        $rows->chunk(25)->each(function ($chunk) {
            Sport::upsert(
                $chunk->values()->all(),
                ['slug'],
                ['name', 'category', 'met_faible', 'met_moderee', 'met_elevee', 'icon', 'is_public', 'updated_at'],
            );
        });

        $this->command?->info('Sports : '.$rows->count().' entrées synchronisées.');
    }

    /**
     * [name, category, met_faible, met_moderee, met_elevee, icon]
     *
     * @return list<array{0: string, 1: string, 2: float, 3: float, 4: float, 5: string}>
     */
    public static function catalog(): array
    {
        return [
            // Endurance
            ['Course à pied', 'endurance', 8.0, 9.8, 11.5, 'directions_run'],
            ['Marche', 'endurance', 3.0, 3.5, 4.3, 'directions_walk'],
            ['Marche nordique', 'endurance', 4.5, 5.5, 6.5, 'hiking'],
            ['Randonnée', 'endurance', 5.0, 6.0, 7.5, 'hiking'],
            ['Trail', 'endurance', 8.0, 10.0, 12.0, 'terrain'],
            ['Vélo', 'endurance', 5.8, 7.5, 10.0, 'directions_bike'],
            ['VTT', 'endurance', 6.5, 8.5, 10.5, 'pedal_bike'],
            ['Rameur', 'endurance', 4.8, 7.0, 8.5, 'rowing'],
            ['Elliptique', 'endurance', 4.5, 5.5, 7.0, 'fitness_center'],
            ['Corde à sauter', 'endurance', 8.8, 11.0, 12.3, 'sports_gymnastics'],
            ['Trottinette', 'endurance', 4.0, 5.0, 6.0, 'electric_scooter'],
            ['Roller', 'glisse', 6.0, 7.5, 9.5, 'roller_skating'],

            // Force
            ['Musculation', 'force', 3.5, 5.0, 6.0, 'fitness_center'],
            ['CrossFit', 'force', 6.0, 8.0, 10.0, 'fitness_center'],
            ['HIIT', 'force', 7.0, 9.0, 11.0, 'bolt'],
            ['Escalade', 'force', 5.0, 7.5, 9.0, 'landscape'],

            // Aquatique
            ['Natation', 'aquatique', 6.0, 8.0, 10.0, 'pool'],
            ['Aquagym', 'aquatique', 4.0, 5.3, 6.5, 'pool'],
            ['Aviron / kayak', 'aquatique', 4.0, 6.0, 8.5, 'kayaking'],
            ['Surf', 'aquatique', 3.0, 5.0, 6.0, 'surfing'],

            // Bien-être
            ['Yoga', 'bien_etre', 2.5, 3.0, 4.0, 'self_improvement'],
            ['Pilates', 'bien_etre', 3.0, 3.8, 4.5, 'self_improvement'],
            ['Stretching', 'bien_etre', 2.3, 2.8, 3.3, 'accessibility_new'],

            // Collectif
            ['Football', 'collectif', 6.0, 8.0, 10.0, 'sports_soccer'],
            ['Basketball', 'collectif', 6.0, 8.0, 9.5, 'sports_basketball'],
            ['Handball', 'collectif', 7.0, 8.5, 10.0, 'sports_handball'],
            ['Volley-ball', 'collectif', 3.0, 4.0, 6.0, 'sports_volleyball'],
            ['Rugby', 'collectif', 7.0, 8.3, 10.0, 'sports_rugby'],

            // Raquette
            ['Tennis', 'raquette', 5.0, 7.3, 8.0, 'sports_tennis'],
            ['Padel', 'raquette', 5.0, 6.5, 8.0, 'sports_tennis'],
            ['Badminton', 'raquette', 4.5, 5.5, 7.0, 'sports_tennis'],
            ['Tennis de table', 'raquette', 3.0, 4.0, 5.0, 'sports_tennis'],
            ['Squash', 'raquette', 7.0, 9.0, 11.0, 'sports_tennis'],

            // Combat
            ['Boxe', 'combat', 6.0, 8.0, 12.0, 'sports_mma'],
            ['Arts martiaux', 'combat', 5.5, 8.0, 10.3, 'sports_martial_arts'],

            // Glisse
            ['Ski alpin', 'glisse', 4.5, 5.5, 7.0, 'downhill_skiing'],
            ['Ski de fond', 'glisse', 7.0, 9.0, 12.0, 'nordic_walking'],
            ['Skate', 'glisse', 5.0, 6.0, 7.0, 'skateboarding'],

            // Autre
            ['Danse', 'autre', 3.5, 5.5, 7.5, 'music_note'],
            ['Zumba', 'autre', 5.0, 6.5, 8.0, 'music_note'],
            ['Golf', 'autre', 3.5, 4.5, 5.5, 'sports_golf'],
            ['Équitation', 'autre', 3.5, 5.5, 7.0, 'bedroom_baby'],
            ['Autre', 'autre', 4.0, 6.0, 8.0, 'sports'],
        ];
    }
}
