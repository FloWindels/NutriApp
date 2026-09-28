<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rôle et suspension d'un compte.
 *
 * `role` n'est jamais assignable en masse et n'est exposé par aucune route du produit : la seule
 * façon de promouvoir quelqu'un est la commande `mavioh:promouvoir`, c'est-à-dire un accès au
 * serveur. Aucune interface ne doit permettre de s'auto-promouvoir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('utilisateur')->index();
            $table->timestamp('suspendu_le')->nullable();
            $table->string('suspension_motif', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'suspendu_le', 'suspension_motif']);
        });
    }
};
