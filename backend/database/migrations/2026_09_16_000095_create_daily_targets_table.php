<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cibles du jour figées lors du premier aliment enregistré (historique fiable).
     */
    public function up(): void
    {
        Schema::create('daily_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('calories');
            $table->unsignedSmallInteger('proteins');
            $table->unsignedSmallInteger('carbs');
            $table->unsignedSmallInteger('fat');
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_targets');
    }
};
