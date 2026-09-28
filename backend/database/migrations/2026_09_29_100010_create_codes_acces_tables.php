<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codes d'accès créés depuis l'espace d'administration.
 *
 * Ils servent à ouvrir l'application entière à quelqu'un — un proche invité à l'essayer, un
 * testeur — sans passer par un paiement. Le code est stocké en clair parce qu'il est fait pour
 * être dicté ou recopié : ce n'est pas un secret d'authentification. Ce qui le protège, c'est
 * le plafond d'utilisations, la date d'expiration et la limitation des tentatives.
 *
 * La table des utilisations existe pour deux raisons : empêcher qu'une même personne consomme
 * deux fois le même code, et garder la trace de qui a reçu quoi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codes_acces', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('offre', 16);
            $table->unsignedSmallInteger('duree_jours')->nullable();
            $table->unsignedSmallInteger('utilisations_max')->default(1);
            $table->unsignedSmallInteger('utilisations')->default(0);
            $table->timestamp('expire_le')->nullable();
            $table->boolean('actif')->default(true);
            $table->string('note', 255)->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('codes_acces_utilisations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('code_acces_id')->constrained('codes_acces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['code_acces_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codes_acces_utilisations');
        Schema::dropIfExists('codes_acces');
    }
};
