<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Magasins, assortiments et promotions.
     *
     * Trois choix méritent d'être expliqués ici.
     *
     * `libelle_normalise` est stocké plutôt que recalculé : c'est la clé de rattachement entre un
     * aliment de la liste de courses et un produit d'enseigne, et elle doit être indexable. La
     * recalculer à chaque requête interdirait l'index et obligerait à charger tout l'assortiment.
     *
     * `food_id` est FACULTATIF et le restera. Un produit d'enseigne existe sans fiche nutritionnelle
     * — c'est un article de rayon, pas un aliment. Le lien est un bonus de précision, jamais un
     * prérequis : sans lui, la ligne reste dans la liste, simplement sans prix.
     *
     * `prix_maj_le` accompagne tout prix. Un prix sans date n'est pas un prix indicatif, c'est un
     * prix faux : l'API et l'écran doivent pouvoir dire de quand il date.
     */
    public function up(): void
    {
        Schema::create('magasins', function (Blueprint $table) {
            $table->id();
            $table->string('enseigne', 32);
            $table->string('nom');
            $table->string('pays', 2)->default('BE');
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['enseigne', 'nom']);
            $table->index('actif');
        });

        Schema::create('magasin_produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->string('libelle');
            $table->string('libelle_normalise', 191);
            $table->string('marque')->nullable();
            $table->string('rayon', 32)->default('autre');
            $table->string('code_barres', 32)->nullable();
            $table->decimal('prix_indicatif', 8, 2)->nullable();
            $table->string('unite', 16)->default('piece');
            $table->decimal('quantite_reference', 8, 3)->default(1);
            $table->foreignId('food_id')->nullable()->constrained('food')->nullOnDelete();
            $table->date('prix_maj_le')->nullable();
            $table->timestamps();

            $table->unique(['magasin_id', 'libelle_normalise']);
            $table->index(['magasin_id', 'rayon']);
            $table->index('code_barres');
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('magasin_produit_id')->nullable()->constrained('magasin_produits')->nullOnDelete();
            $table->string('libelle');
            $table->string('libelle_normalise', 191);
            $table->decimal('prix_promotionnel', 8, 2)->nullable();
            $table->decimal('prix_avant', 8, 2)->nullable();
            $table->date('debut');
            $table->date('fin');
            // URL de la page d'où vient l'information, ou « saisie manuelle ». Jamais vide : une
            // promotion sans provenance ne peut pas être vérifiée, donc pas être affichée.
            $table->string('source');
            $table->boolean('verifiee')->default(false);
            $table->timestamps();

            $table->index(['magasin_id', 'debut', 'fin']);
            $table->index('libelle_normalise');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('magasin_produits');
        Schema::dropIfExists('magasins');
    }
};
