<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des actions d'administration.
 *
 * Un administrateur détient un pouvoir asymétrique sur les données de tiers. Sans trace, il ne
 * peut ni prouver qu'il n'en a pas abusé, ni motiver un retrait auprès de l'auteur d'un contenu.
 * La table est en ajout seul : aucune route ne la modifie ni ne la supprime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            // L'auteur de l'action reste identifiable même si son compte disparaît.
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_email');
            $table->string('action', 40);
            $table->string('cible_type', 40);
            $table->unsignedBigInteger('cible_id')->nullable();
            $table->string('cible_libelle')->nullable();
            $table->text('motif');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cible_type', 'cible_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
    }
};
