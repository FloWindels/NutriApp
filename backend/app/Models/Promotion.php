<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Promotion extends Model
{
    use HasFactory;

    /** Provenance d'une promotion que quelqu'un a tapée lui-même. */
    public const SOURCE_MANUELLE = 'saisie manuelle';

    protected $table = 'promotions';

    protected $fillable = [
        'magasin_id',
        'magasin_produit_id',
        'libelle',
        'libelle_normalise',
        'prix_promotionnel',
        'prix_avant',
        'debut',
        'fin',
        'source',
        'verifiee',
    ];

    protected $casts = [
        'magasin_id' => 'integer',
        'magasin_produit_id' => 'integer',
        'prix_promotionnel' => 'float',
        'prix_avant' => 'float',
        'debut' => 'date',
        'fin' => 'date',
        'verifiee' => 'boolean',
    ];

    protected $attributes = [
        'verifiee' => false,
    ];

    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(MagasinProduit::class, 'magasin_produit_id');
    }

    /**
     * Promotions couvrant ce jour-là. Bornes incluses : une promotion qui se termine aujourd'hui
     * court encore aujourd'hui.
     *
     * @param  Builder<Promotion>  $query
     * @return Builder<Promotion>
     */
    public function scopeActives(Builder $query, string $jour): Builder
    {
        return $query->whereDate('debut', '<=', $jour)->whereDate('fin', '>=', $jour);
    }

    /** Remise en pourcentage, uniquement quand les deux prix sont connus. */
    public function remisePourcent(): ?int
    {
        if ($this->prix_promotionnel === null || $this->prix_avant === null || $this->prix_avant <= 0) {
            return null;
        }

        $remise = (1 - ((float) $this->prix_promotionnel / (float) $this->prix_avant)) * 100;

        return $remise > 0 ? (int) round($remise) : null;
    }
}
