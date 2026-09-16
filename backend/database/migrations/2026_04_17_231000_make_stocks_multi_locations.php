<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->string('name')->default('Frigo')->after('user_id');
        });

        DB::table('stocks')->whereNull('name')->update(['name' => 'Frigo']);

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->unique(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'name']);
            $table->unique('user_id');
            $table->dropColumn('name');
        });
    }
};
