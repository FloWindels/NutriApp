<?php

use App\Enums\Equipment;
use App\Enums\Lieu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reprise de l'existant (Sport v2 §1) : chaque profil qui avait renseigné `sport_lieu` ou
 * `sport_materiel` reçoit un lieu par défaut, nommé d'après le type, avec son matériel (ou le
 * préréglage du type s'il n'en avait pas). Les colonnes du profil restent en place et lues.
 *
 * `tapis` désignait jusqu'ici le tapis de COURSE : il devient le tapis de sol. Pour ne rien
 * retirer à personne, un ancien `tapis` donne les deux. Les exercices du catalogue « sur tapis »
 * passent au tapis de course (le seeder fait de même au prochain passage).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('exercises')
            ->whereIn('slug', ['course-tapis', 'marche-rapide-tapis', 'marche-inclinee-tapis'])
            ->update(['equipment' => 'tapis_course']);

        $now = now();
        $labels = Lieu::labels();
        $valides = Equipment::values();

        DB::table('profiles')
            ->where(function ($q) {
                $q->whereNotNull('sport_lieu')->orWhereNotNull('sport_materiel');
            })
            ->orderBy('id')
            ->select(['user_id', 'sport_lieu', 'sport_materiel'])
            ->chunk(200, function ($profils) use ($now, $labels, $valides) {
                $lignes = [];
                foreach ($profils as $profil) {
                    if (DB::table('sport_lieux')->where('user_id', $profil->user_id)->exists()) {
                        continue;
                    }

                    $type = isset($labels[$profil->sport_lieu]) ? $profil->sport_lieu : 'maison';
                    $materiel = json_decode((string) $profil->sport_materiel, true);
                    $materiel = is_array($materiel) ? array_values(array_intersect($materiel, $valides)) : [];

                    if (in_array('tapis', $materiel, true)) {
                        $materiel[] = 'tapis_course';
                    }
                    if ($materiel === []) {
                        $materiel = Equipment::preset($type);
                    }
                    if (! in_array('aucun', $materiel, true)) {
                        array_unshift($materiel, 'aucun');
                    }

                    $lignes[] = [
                        'user_id' => $profil->user_id,
                        'nom' => $labels[$type],
                        'type' => $type,
                        'materiel' => json_encode(array_values(array_unique($materiel))),
                        'par_defaut' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($lignes !== []) {
                    DB::table('sport_lieux')->insert($lignes);
                }
            });
    }

    public function down(): void
    {
        DB::table('exercises')
            ->whereIn('slug', ['course-tapis', 'marche-rapide-tapis', 'marche-inclinee-tapis'])
            ->update(['equipment' => 'tapis']);
    }
};
