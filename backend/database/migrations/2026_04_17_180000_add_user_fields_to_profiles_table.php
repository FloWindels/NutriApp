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
            $table->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();

            $table->decimal('poids', 6, 2)->nullable();
            $table->decimal('taille', 6, 2)->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('sexe', 20)->nullable();
            $table->string('objectif', 100)->nullable();
            $table->string('niveau_activite', 100)->nullable();
            $table->decimal('poids_a_perdre_kg', 6, 2)->nullable();
            $table->unsignedSmallInteger('delai_objectif_semaines')->nullable();
            $table->string('bien_etre', 20)->nullable();

            $table->unsignedSmallInteger('calories_cibles')->nullable();
            $table->unsignedSmallInteger('proteines_cibles')->nullable();
            $table->unsignedSmallInteger('glucides_cibles')->nullable();
            $table->unsignedSmallInteger('lipides_cibles')->nullable();

            $table->string('regime_alimentaire', 100)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');

            $table->dropColumn([
                'poids',
                'taille',
                'age',
                'sexe',
                'objectif',
                'niveau_activite',
                'poids_a_perdre_kg',
                'delai_objectif_semaines',
                'bien_etre',
                'calories_cibles',
                'proteines_cibles',
                'glucides_cibles',
                'lipides_cibles',
                'regime_alimentaire',
            ]);
        });
    }
};
