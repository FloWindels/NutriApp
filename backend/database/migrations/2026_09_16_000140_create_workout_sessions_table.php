<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Séances structurées (kind=seance) et activités libres (kind=activite).
     * `sport_plan_id` est volontairement sans FK : les plans référencent déjà les séances.
     */
    public function up(): void
    {
        Schema::create('workout_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->time('planned_at')->nullable();
            $table->string('title');
            $table->string('kind', 16)->default('seance');
            $table->string('goal', 32)->nullable();
            $table->string('level', 16)->nullable();
            $table->json('equipment')->nullable();
            $table->json('focus')->nullable();
            $table->unsignedSmallInteger('duration_min');
            $table->decimal('calories_burned', 7, 1)->nullable();
            $table->string('status', 16)->default('prevue');
            $table->unsignedTinyInteger('rpe')->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 16)->default('manuelle');
            $table->string('intensity', 16)->nullable();
            $table->decimal('distance_km', 6, 2)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Sport pratiqué & contexte (addendum §A.2)
            $table->foreignId('sport_id')->nullable()->constrained('sports')->nullOnDelete();
            $table->string('sport_name', 80)->nullable();
            $table->string('lieu', 16)->nullable();
            $table->string('calories_source', 8)->default('auto');
            $table->string('generated_by', 8)->nullable();
            $table->string('llm_model', 64)->nullable();
            $table->unsignedBigInteger('sport_plan_id')->nullable();
            $table->json('zones_a_eviter')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'date']);
            $table->index('sport_plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_sessions');
    }
};
