<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le magasin préféré est un réglage PERSONNEL, pas un réglage de foyer.
     *
     * Deux membres d'un même ménage ne font pas leurs courses au même endroit, et la liste, elle,
     * reste partagée : c'est la même liste vue avec les prix de l'enseigne où l'on va. Le poser
     * sur le foyer aurait imposé le magasin de l'un à l'autre.
     */
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->foreignId('magasin_prefere_id')->nullable()->after('ia_seances')
                ->constrained('magasins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('magasin_prefere_id');
        });
    }
};
