<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paramètres utilisateur. `partage_profil_foyer` n'est pas stocké ici :
     * il est lu depuis `household_members.share_profile`.
     */
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('notif_peremption')->default(true);
            $table->boolean('notif_rappel_repas')->default(false);
            $table->boolean('notif_rappel_sport')->default(false);
            $table->time('heure_rappel')->nullable();
            $table->unsignedTinyInteger('jours_alerte_peremption')->default(3);
            $table->string('unites', 16)->default('metrique');
            $table->string('theme', 16)->default('systeme');
            $table->string('langue', 8)->default('fr');
            $table->string('timezone', 64)->default('Europe/Paris');
            $table->boolean('ia_seances')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
