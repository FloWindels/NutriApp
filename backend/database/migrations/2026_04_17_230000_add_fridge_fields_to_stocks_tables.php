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
        Schema::table('stocks', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete()->after('id');
            $table->unique('user_id');
        });

        Schema::table('stock_items', function (Blueprint $table) {
            $table->foreignId('stock_id')->nullable()->constrained('stocks')->cascadeOnDelete()->after('id');
            $table->foreignId('food_id')->nullable()->constrained('food')->nullOnDelete()->after('stock_id');
            $table->string('food_name')->nullable()->after('food_id');
            $table->string('food_barcode', 32)->nullable()->after('food_name');
            $table->string('food_brand')->nullable()->after('food_barcode');
            $table->decimal('quantity', 8, 2)->default(1)->after('food_brand');
            $table->string('unit', 32)->default('unite')->after('quantity');
            $table->date('expires_at')->nullable()->after('unit');

            $table->index('stock_id');
            $table->index('food_id');
            $table->index('food_barcode');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropIndex(['stock_id']);
            $table->dropIndex(['food_id']);
            $table->dropIndex(['food_barcode']);
            $table->dropIndex(['expires_at']);

            $table->dropConstrainedForeignId('stock_id');
            $table->dropConstrainedForeignId('food_id');
            $table->dropColumn([
                'food_name',
                'food_barcode',
                'food_brand',
                'quantity',
                'unit',
                'expires_at',
            ]);
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
