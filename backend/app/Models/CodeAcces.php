<?php

namespace App\Models;

use App\Enums\Offre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Code d'accès : ouvre une offre à quelqu'un sans passer par un paiement.
 */
class CodeAcces extends Model
{
    protected $table = 'codes_acces';

    protected $fillable = [
        'code', 'offre', 'duree_jours', 'utilisations_max', 'utilisations',
        'expire_le', 'actif', 'note', 'cree_par_id',
    ];

    protected $casts = [
        'offre' => Offre::class,
        'duree_jours' => 'integer',
        'utilisations_max' => 'integer',
        'utilisations' => 'integer',
        'expire_le' => 'datetime',
        'actif' => 'boolean',
    ];

    public function utilisationsListe(): HasMany
    {
        return $this->hasMany(CodeAccesUtilisation::class, 'code_acces_id');
    }

    /** Un code épuisé, désactivé ou périmé ne donne plus rien. */
    public function estUtilisable(): bool
    {
        return $this->actif
            && $this->utilisations < $this->utilisations_max
            && ($this->expire_le === null || $this->expire_le->isFuture());
    }
}
