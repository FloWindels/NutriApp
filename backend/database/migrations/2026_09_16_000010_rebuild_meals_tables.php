<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remplace les coquilles vides `meals` / `meal_items` par le vrai schéma.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('meal_items');
        Schema::dropIfExists('meals');
        Schema::enableForeignKeyConstraints();

        Schema::create('meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->string('type', 32);
            $table->string('name')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date', 'type']);
            $table->index(['user_id', 'date']);
        });

        Schema::create('meal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_id')->constrained('meals')->cascadeOnDelete();
            $table->foreignId('food_id')->nullable()->constrained('food')->nullOnDelete();
            $table->foreignId('recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            $table->string('source_type', 16);
            $table->string('label');
            $table->decimal('quantity', 8, 2);
            $table->string('unit', 16);
            $table->decimal('grams_equivalent', 8, 2)->nullable();

            // Totaux figés pour la quantité saisie
            $table->decimal('calories', 8, 2);
            $table->decimal('proteins', 8, 2);
            $table->decimal('carbs', 8, 2);
            $table->decimal('fat', 8, 2);
            $table->decimal('fiber', 8, 2)->nullable();
            $table->decimal('sugar', 8, 2)->nullable();
            $table->decimal('salt', 8, 2)->nullable();

            // Références figées pour recalcul (per_100g | per_serving | absolute)
            $table->string('ref_basis', 16);
            $table->decimal('ref_calories', 8, 2)->nullable();
            $table->decimal('ref_proteins', 8, 2)->nullable();
            $table->decimal('ref_carbs', 8, 2)->nullable();
            $table->decimal('ref_fat', 8, 2)->nullable();
            $table->decimal('ref_fiber', 8, 2)->nullable();
            $table->decimal('ref_sugar', 8, 2)->nullable();
            $table->decimal('ref_salt', 8, 2)->nullable();
            $table->decimal('ref_serving_size_g', 8, 2)->nullable();

            $table->boolean('is_estimate')->default(false);
            $table->timestamps();

            $table->index('meal_id');
            $table->index('food_id');
            $table->index('recipe_id');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('meal_items');
        Schema::dropIfExists('meals');
        Schema::enableForeignKeyConstraints();
    }
};
