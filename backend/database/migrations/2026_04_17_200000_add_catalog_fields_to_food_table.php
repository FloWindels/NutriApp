<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('food', function (Blueprint $table) {
            $table->string('barcode')->unique()->after('id');
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('image_url')->nullable();
            $table->decimal('calories', 8, 2)->nullable();
            $table->decimal('fat', 8, 2)->nullable();
            $table->decimal('carbs', 8, 2)->nullable();
            $table->decimal('proteins', 8, 2)->nullable();
            $table->string('source_type')->default('manual');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('food', function (Blueprint $table) {
            $table->dropColumn([
                'barcode',
                'name',
                'brand',
                'image_url',
                'calories',
                'fat',
                'carbs',
                'proteins',
                'source_type',
            ]);
        });
    }
};
