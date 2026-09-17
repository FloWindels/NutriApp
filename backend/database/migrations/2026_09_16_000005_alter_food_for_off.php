<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prépare la table `food` pour Open Food Facts :
     * code-barres facultatif, image en texte, champs nutritionnels étendus,
     * et table pivot `food_favorites`.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE food ALTER COLUMN barcode DROP NOT NULL');
            DB::statement('ALTER TABLE food ALTER COLUMN image_url TYPE TEXT');
        } else {
            Schema::disableForeignKeyConstraints();
            Schema::table('food', function (Blueprint $table) {
                $table->string('barcode')->nullable()->change();
                $table->text('image_url')->nullable()->change();
            });
            Schema::enableForeignKeyConstraints();
        }

        Schema::table('food', function (Blueprint $table) {
            $table->decimal('serving_size_g', 8, 2)->nullable();
            $table->string('serving_label', 64)->nullable();
            $table->string('category', 100)->nullable();
            $table->json('allergens')->nullable();
            $table->decimal('fiber', 8, 2)->nullable();
            $table->decimal('sugar', 8, 2)->nullable();
            $table->decimal('salt', 8, 2)->nullable();
            $table->decimal('density_g_per_ml', 5, 3)->nullable();
            $table->string('per_unit', 8)->default('100g');
            $table->timestamp('source_fetched_at')->nullable();
            $table->timestamp('off_last_checked_at')->nullable();
            $table->boolean('is_verified')->default(false);
        });

        Schema::create('food_favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('food')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'food_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_favorites');

        Schema::table('food', function (Blueprint $table) {
            $table->dropColumn([
                'serving_size_g',
                'serving_label',
                'category',
                'allergens',
                'fiber',
                'sugar',
                'salt',
                'density_g_per_ml',
                'per_unit',
                'source_fetched_at',
                'off_last_checked_at',
                'is_verified',
            ]);
        });

        // barcode reste nullable et image_url reste en texte : retour arrière sans perte.
    }
};
