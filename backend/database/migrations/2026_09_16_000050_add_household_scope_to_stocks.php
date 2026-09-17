<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un lieu de stock appartient soit à une personne (user_id, household_id NULL),
     * soit à un foyer (user_id NULL, household_id). Unicité insensible à la casse
     * via deux index partiels (identiques sur PostgreSQL et SQLite).
     */
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique('stocks_user_id_name_unique');
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->unsignedBigInteger('household_id')->nullable()->after('user_id');
            $table->index('household_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stocks ALTER COLUMN user_id DROP NOT NULL');
        } else {
            Schema::disableForeignKeyConstraints();
            Schema::table('stocks', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
            Schema::enableForeignKeyConstraints();
        }

        DB::statement('CREATE UNIQUE INDEX stocks_user_name_unique ON stocks (user_id, LOWER(name)) WHERE household_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX stocks_household_name_unique ON stocks (household_id, LOWER(name)) WHERE household_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stocks_user_name_unique');
        DB::statement('DROP INDEX IF EXISTS stocks_household_name_unique');

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropIndex(['household_id']);
            $table->dropColumn('household_id');
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->unique(['user_id', 'name']);
        });
    }
};
