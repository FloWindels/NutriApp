<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motif de mouvement, qui détermine l'illustration montrée à l'utilisateur.
 *
 * Deux colonnes, pas une : `exercises.movement` porte le motif du catalogue, corrigeable par un
 * simple reseed ; `workout_exercises.movement` en fige un instantané au moment de l'enregistrement,
 * comme le nom et le MET voisins, pour que la séance affiche après coup exactement la figure
 * qu'elle affichait avant — y compris pour les exercices sans identifiant de catalogue, que le
 * générateur à règles et le modèle de langage produisent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->string('movement', 32)->nullable()->index();
        });

        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->string('movement', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropIndex(['movement']);
            $table->dropColumn('movement');
        });

        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->dropColumn('movement');
        });
    }
};
