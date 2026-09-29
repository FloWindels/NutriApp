<?php

namespace App\Models;

use App\Enums\Rayon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MagasinProduit extends Model
{
    use HasFactory;

    protected $table = 'magasin_produits';

    protected $fillable = [
        'magasin_id',
        'libelle',
        'libelle_normalise',
        'marque',
        'rayon',
        'code_barres',
        'prix_indicatif',
        'unite',
        'quantite_reference',
        'food_id',
        'prix_maj_le',
    ];

    protected $casts = [
        'magasin_id' => 'integer',
        'rayon' => Rayon::class,
        'prix_indicatif' => 'float',
        'quantite_reference' => 'float',
        'food_id' => 'integer',
        'prix_maj_le' => 'date',
    ];

    protected $attributes = [
        'rayon' => 'autre',
        'unite' => 'piece',
        'quantite_reference' => 1,
    ];

    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /**
     * Prix ramené au kilo ou au litre, pour comparer deux conditionnements.
     *
     * Null dès qu'une des trois données manque : mieux vaut ne rien afficher qu'un rapport
     * calculé sur une quantité de référence inventée.
     */
    public function prixParUniteBase(): ?float
    {
        if ($this->prix_indicatif === null || $this->quantite_reference <= 0) {
            return null;
        }

        if (! in_array($this->unite, ['kg', 'l'], true)) {
            return null;
        }

        return round((float) $this->prix_indicatif / (float) $this->quantite_reference, 2);
    }
}
