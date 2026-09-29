<?php

namespace App\Models;

use App\Enums\Enseigne;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Magasin extends Model
{
    use HasFactory;

    protected $table = 'magasins';

    protected $fillable = [
        'enseigne',
        'nom',
        'pays',
        'actif',
    ];

    protected $casts = [
        'enseigne' => Enseigne::class,
        'actif' => 'boolean',
    ];

    protected $attributes = [
        'pays' => 'BE',
        'actif' => true,
    ];

    public function produits(): HasMany
    {
        return $this->hasMany(MagasinProduit::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }
}
