<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->decimal('min_quantity', 8, 2)->nullable();
            $table->date('opened_at')->nullable();
            $table->timestamp('depleted_at')->nullable();
            $table->string('expiry_kind', 8)->default('dlc');
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn([
                'min_quantity',
                'opened_at',
                'depleted_at',
                'expiry_kind',
            ]);
        });
    }
};
