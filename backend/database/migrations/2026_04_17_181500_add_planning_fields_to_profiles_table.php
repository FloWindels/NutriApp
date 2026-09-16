<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->decimal('poids_a_perdre_kg', 6, 2)->nullable()->after('objectif');
            $table->unsignedSmallInteger('delai_objectif_semaines')->nullable()->after('poids_a_perdre_kg');
            $table->string('bien_etre', 20)->nullable()->after('delai_objectif_semaines');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn([
                'poids_a_perdre_kg',
                'delai_objectif_semaines',
                'bien_etre',
            ]);
        });
    }
};
