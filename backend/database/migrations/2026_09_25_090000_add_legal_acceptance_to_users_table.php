<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preuve de l'acceptation des conditions générales et de la politique de confidentialité.
 *
 * L'article 7.1 du RGPD impose au responsable du traitement de pouvoir DÉMONTRER que la
 * personne a consenti. Une case cochée dans une interface ne prouve rien : il faut la date et
 * la version exacte du texte accepté, faute de quoi on ne sait pas à quoi la personne a
 * consenti. Ces trois colonnes sont cette preuve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('cgu_accepted_at')->nullable();
            $table->string('cgu_version', 20)->nullable();
            $table->string('confidentialite_version', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['cgu_accepted_at', 'cgu_version', 'confidentialite_version']);
        });
    }
};
