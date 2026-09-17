<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Liste de courses : portée personnelle (user_id) ou foyer (household_id), sans FK
     * sur ces deux colonnes (transfert explicite par HouseholdService).
     */
    public function up(): void
    {
        Schema::create('shopping_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('household_id')->nullable();
            $table->foreignId('food_id')->nullable()->constrained('food')->nullOnDelete();
            $table->string('label');
            $table->decimal('quantity', 8, 2)->nullable();
            $table->string('unit', 16)->nullable();
            $table->boolean('checked')->default(false);
            $table->string('source', 16)->default('manuel');
            $table->timestamps();

            $table->index('user_id');
            $table->index('household_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_items');
    }
};
