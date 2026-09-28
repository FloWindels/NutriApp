<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offre d'un compte, et sa date de fin éventuelle.
 *
 * Comme `role`, ces colonnes ne sont jamais assignables en masse : une offre se pose par une
 * commande, par un code d'accès, ou plus tard par un paiement — jamais par un corps de requête.
 *
 * Une offre qui expire ne détruit rien : le compte retombe sur l'offre gratuite et retrouve
 * l'accès à ses données le jour où il reprend une offre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('offre', 16)->default('gratuit')->index();
            $table->timestamp('offre_expire_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['offre']);
            $table->dropColumn(['offre', 'offre_expire_le']);
        });
    }
};
