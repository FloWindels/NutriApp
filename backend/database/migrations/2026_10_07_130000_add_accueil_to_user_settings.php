<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * L'accueil d'un nouveau compte : le questionnaire de première connexion et la visite guidée.
     *
     * Posés sur les paramètres et non dans le navigateur : quelqu'un qui a fait la visite sur son
     * ordinateur ne doit pas se la voir reproposer sur son téléphone. Des dates plutôt que des
     * booléens : on sait quand, et « jamais » reste lisible (null).
     */
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->timestamp('onboarding_termine_le')->nullable()->after('magasin_prefere_id');
            $table->timestamp('visite_vue_le')->nullable()->after('onboarding_termine_le');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn(['onboarding_termine_le', 'visite_vue_le']);
        });
    }
};
