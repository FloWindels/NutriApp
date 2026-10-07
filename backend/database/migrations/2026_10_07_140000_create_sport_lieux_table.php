<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sport v2 : lieux d'entraînement et prescription structurée.
 *
 * - `sport_lieux` : chaque compte décrit ses endroits (maison, salle, extérieur…) et le matériel
 *   qu'il y trouve ; un seul lieu par défaut par compte (garanti par l'application, pas par un
 *   index partiel que SQLite et PostgreSQL n'écrivent pas pareil).
 * - `lieu_id` sur le calendrier et les séances : nullable, remis à null si le lieu disparaît —
 *   la séance garde son type de lieu (`lieu`) et son matériel figé (`equipment`).
 * - `workout_exercises.prescription` : cibles chiffrées (cadence, résistance, zone…), seule
 *   source dont le serveur tire la phrase lisible et les étapes minutées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sport_lieux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('nom', 60);
            $table->string('type', 16);
            $table->json('materiel')->nullable();
            $table->boolean('par_defaut')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'par_defaut']);
        });

        Schema::table('sport_plans', function (Blueprint $table) {
            $table->foreignId('lieu_id')->nullable()->after('lieu')->constrained('sport_lieux')->nullOnDelete();
        });

        Schema::table('workout_sessions', function (Blueprint $table) {
            $table->foreignId('lieu_id')->nullable()->after('lieu')->constrained('sport_lieux')->nullOnDelete();
        });

        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->json('prescription')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->dropColumn('prescription');
        });

        Schema::table('workout_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lieu_id');
        });

        Schema::table('sport_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lieu_id');
        });

        Schema::dropIfExists('sport_lieux');
    }
};
