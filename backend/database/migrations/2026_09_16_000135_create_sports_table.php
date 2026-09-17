<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalogue des sports (publics + créés par les utilisateurs) avec MET par intensité.
     */
    public function up(): void
    {
        Schema::create('sports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->string('category', 32);
            $table->decimal('met_faible', 4, 1);
            $table->decimal('met_moderee', 4, 1);
            $table->decimal('met_elevee', 4, 1);
            $table->string('icon', 32)->nullable();
            $table->boolean('is_public')->default(true);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports');
    }
};
