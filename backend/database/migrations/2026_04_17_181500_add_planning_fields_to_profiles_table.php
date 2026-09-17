<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ces colonnes ont aussi été ajoutées par 2026_04_17_180000 : la migration
     * est rendue idempotente pour qu'une installation fraîche fonctionne.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('profiles', 'poids_a_perdre_kg')) {
                $table->decimal('poids_a_perdre_kg', 6, 2)->nullable()->after('objectif');
            }
            if (!Schema::hasColumn('profiles', 'delai_objectif_semaines')) {
                $table->unsignedSmallInteger('delai_objectif_semaines')->nullable()->after('poids_a_perdre_kg');
            }
            if (!Schema::hasColumn('profiles', 'bien_etre')) {
                $table->string('bien_etre', 20)->nullable()->after('delai_objectif_semaines');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Colonnes gérées par 2026_04_17_180000 : rien à défaire ici.
    }
};
