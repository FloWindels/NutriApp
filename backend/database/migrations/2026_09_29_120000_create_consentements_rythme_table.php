<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des accords donnés pour perdre plus vite que le rythme conseillé.
 *
 * Les colonnes posées sur `profiles` disent l'ÉTAT : est-ce qu'un accord s'applique en ce moment,
 * et pour quel déficit. Elles repassent donc à null quand on retire son accord — c'est ce qu'on
 * veut d'un état. Mais retirer son accord ne doit pas effacer le fait qu'il a été donné : sans
 * cette table, il ne resterait rien à opposer six mois plus tard, alors que c'est précisément le
 * jour où la question se pose.
 *
 * La table est en ajout seul : aucune route ne la modifie ni ne la supprime, on y date seulement
 * la fin d'un accord.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consentements_rythme', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('donne_le');
            // La version du texte des risques réellement affiché, telle que le client l'a déclarée.
            $table->string('version_texte', 32);
            $table->unsignedSmallInteger('deficit_kcal');
            $table->decimal('kg_semaine', 4, 2);
            $table->timestamp('retire_le')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'donne_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consentements_rythme');
    }
};
