<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category', 16);
            $table->string('muscle_group', 32);
            $table->string('equipment', 32);
            $table->string('level', 16);
            $table->decimal('met', 4, 1);
            $table->unsignedTinyInteger('default_sets')->nullable();
            $table->unsignedTinyInteger('default_reps')->nullable();
            $table->unsignedSmallInteger('default_duration_sec')->nullable();
            $table->text('instructions');
            $table->json('contraindications')->nullable();
            $table->boolean('is_public')->default(true);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['category', 'level']);
            $table->index('muscle_group');
            $table->index('equipment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
