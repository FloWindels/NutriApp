<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rythme de perte sans plafond de vitesse : prolonge le consentement au rythme intense, seul le
 * plancher de sécurité borne alors le déficit. La preuve de l'accord reste dans
 * `consentements_rythme`, avec le déficit présenté.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->boolean('rythme_libre')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('rythme_libre');
        });
    }
};
