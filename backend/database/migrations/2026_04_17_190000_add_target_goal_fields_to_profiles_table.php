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
            $table->decimal('poids_souhaite_kg', 6, 2)->nullable()->after('poids');
            $table->unsignedSmallInteger('delai_objectif_jours')->nullable()->after('poids_souhaite_kg');
            $table->string('objectif_type', 20)->nullable()->after('objectif');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn([
                'poids_souhaite_kg',
                'delai_objectif_jours',
                'objectif_type',
            ]);
        });
    }
};
