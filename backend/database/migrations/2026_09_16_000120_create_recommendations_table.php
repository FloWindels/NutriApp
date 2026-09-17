<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->string('type', 32);
            $table->string('dedupe_key', 64);
            $table->string('title');
            $table->text('message');
            $table->json('factors')->nullable();
            $table->json('actions')->nullable();
            $table->unsignedTinyInteger('priority')->default(3);
            $table->string('status', 16)->default('new');
            $table->boolean('is_estimate')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'date', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
