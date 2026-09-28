<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masquage par la modération, sur les deux seuls contenus réellement visibles par autrui :
 * les aliments créés par les utilisateurs — qui entrent directement dans le catalogue commun,
 * consultable sans compte — et les recettes publiées.
 *
 * Deux colonnes distinctes plutôt qu'un détournement de `is_public` : dépublier une recette en
 * écrivant `is_public = false` serait indistinguable d'un choix de son auteur, qui pourrait la
 * republier d'un clic. Les sports personnels sont hors périmètre : le catalogue force
 * `is_public = false` à la création, aucun n'est donc visible par un tiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['food', 'recipes'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->timestamp('masque_le')->nullable()->index();
                $blueprint->unsignedBigInteger('masque_par_id')->nullable();
                $blueprint->string('masque_motif', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['food', 'recipes'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['masque_le']);
                $blueprint->dropColumn(['masque_le', 'masque_par_id', 'masque_motif']);
            });
        }
    }
};
