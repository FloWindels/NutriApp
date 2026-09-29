<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace du consentement à un rythme de perte plus rapide que celui conseillé.
 *
 * On garde de quoi répondre plus tard à « qu'est-ce qui a été accepté, quand, et sur quel
 * texte » : la date, la version de l'avertissement présenté, et le déficit accepté. Sans ces
 * trois-là, un consentement ne prouve rien.
 *
 * Colonnes additives : un profil existant reste au rythme conseillé, valeur par défaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->boolean('rythme_intense')->default(false);
            $table->timestamp('rythme_intense_consenti_le')->nullable();
            $table->string('rythme_intense_avertissement_version', 32)->nullable();
            $table->unsignedSmallInteger('rythme_intense_deficit_kcal')->nullable();
            $table->decimal('rythme_intense_kg_semaine', 4, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn([
                'rythme_intense',
                'rythme_intense_consenti_le',
                'rythme_intense_avertissement_version',
                'rythme_intense_deficit_kcal',
                'rythme_intense_kg_semaine',
            ]);
        });
    }
};
