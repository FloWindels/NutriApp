<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->decimal('servings', 4, 1)->default(1);
            $table->decimal('proteins', 8, 2)->nullable();
            $table->decimal('carbs', 8, 2)->nullable();
            $table->decimal('fat', 8, 2)->nullable();
            $table->json('tags')->nullable();
            $table->json('meal_types')->nullable();
            $table->boolean('is_estimate')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn([
                'servings',
                'proteins',
                'carbs',
                'fat',
                'tags',
                'meal_types',
                'is_estimate',
            ]);
        });
    }
};
