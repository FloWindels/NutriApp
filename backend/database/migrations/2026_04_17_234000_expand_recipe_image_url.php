<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE recipes ALTER COLUMN image_url TYPE TEXT');
            return;
        }

        DB::statement('ALTER TABLE recipes MODIFY image_url LONGTEXT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE recipes ALTER COLUMN image_url TYPE VARCHAR(255)');
            return;
        }

        DB::statement('ALTER TABLE recipes MODIFY image_url VARCHAR(255) NULL');
    }
};