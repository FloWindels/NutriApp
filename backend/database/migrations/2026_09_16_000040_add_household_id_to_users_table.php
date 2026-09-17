<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache du foyer courant sur l'utilisateur. Volontairement sans clé étrangère
     * (symétrie PostgreSQL / SQLite) : la cohérence est garantie par HouseholdService.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('household_id')->nullable()->after('password');
            $table->index('household_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['household_id']);
            $table->dropColumn('household_id');
        });
    }
};
