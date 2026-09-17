<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('household_id')->nullable();
            $table->date('date');
            $table->string('meal_type', 32);
            $table->foreignId('recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->foreignId('food_id')->nullable()->constrained('food')->nullOnDelete();
            $table->string('title');
            $table->decimal('servings', 4, 1)->default(1);
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('prevu');
            $table->foreignId('meal_id')->nullable()->constrained('meals')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'date']);
            $table->index(['household_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plans');
    }
};
