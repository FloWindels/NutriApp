<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('workout_sessions')->cascadeOnDelete();
            $table->foreignId('exercise_id')->nullable()->constrained('exercises')->nullOnDelete();
            $table->string('block', 32)->default('principal');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('name');
            $table->unsignedTinyInteger('sets')->nullable();
            $table->unsignedTinyInteger('reps')->nullable();
            $table->unsignedSmallInteger('duration_sec')->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->unsignedSmallInteger('rest_sec')->nullable();
            $table->decimal('met', 4, 1)->nullable();
            $table->boolean('completed')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_exercises');
    }
};
