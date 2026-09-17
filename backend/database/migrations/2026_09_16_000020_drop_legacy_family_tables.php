<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supprime les coquilles `families` / `family_members` (remplacées par le foyer).
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('family_members');
        Schema::dropIfExists('families');
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::create('families', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        Schema::create('family_members', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
