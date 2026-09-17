<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Calendrier sportif : ce que l'utilisateur prévoit de pratiquer, jour par jour.
     */
    public function up(): void
    {
        Schema::create('sport_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('sport_id')->nullable()->constrained('sports')->nullOnDelete();
            $table->string('sport_name', 80);
            $table->unsignedSmallInteger('planned_duration_min');
            $table->time('planned_at')->nullable();
            $table->string('lieu', 16)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('prevu');
            $table->foreignId('session_id')->nullable()->constrained('workout_sessions')->nullOnDelete();
            $table->string('recurrence_id', 36)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date']);
            $table->index('recurrence_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sport_plans');
    }
};
