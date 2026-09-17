<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Champs alimentaires, sportifs et de sécurité du profil (brief §2.1 + addendum §A.4).
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // Alimentation
            $table->json('allergenes')->nullable();
            $table->json('aliments_exclus')->nullable();
            $table->json('preferences')->nullable();

            // Sport
            $table->string('sport_niveau', 16)->nullable();
            $table->string('sport_objectif', 32)->nullable();
            $table->json('sport_materiel')->nullable();
            $table->unsignedSmallInteger('sport_temps_dispo_min')->nullable();
            $table->unsignedTinyInteger('sport_jours_semaine')->nullable();
            $table->string('sport_lieu', 16)->nullable();
            $table->json('sport_zones_a_eviter')->nullable();
            $table->json('sport_focus')->nullable();
            $table->text('sport_notes')->nullable();
            $table->unsignedTinyInteger('sport_coef_calories')->default(100);

            // Objectif & calcul des cibles
            $table->boolean('objectif_calcul_auto')->default(true);
            $table->date('objectif_date_debut')->nullable();
            $table->date('objectif_date_fin')->nullable();
            $table->decimal('poids_reference', 6, 2)->nullable();
            $table->date('cibles_calculees_le')->nullable();

            // Sécurité
            $table->string('situation_particuliere', 32)->default('aucune');
            $table->boolean('consentement_parental')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn([
                'allergenes',
                'aliments_exclus',
                'preferences',
                'sport_niveau',
                'sport_objectif',
                'sport_materiel',
                'sport_temps_dispo_min',
                'sport_jours_semaine',
                'sport_lieu',
                'sport_zones_a_eviter',
                'sport_focus',
                'sport_notes',
                'sport_coef_calories',
                'objectif_calcul_auto',
                'objectif_date_debut',
                'objectif_date_fin',
                'poids_reference',
                'cibles_calculees_le',
                'situation_particuliere',
                'consentement_parental',
            ]);
        });
    }
};
