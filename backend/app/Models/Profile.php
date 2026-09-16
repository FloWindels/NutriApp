<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'poids',
        'poids_souhaite_kg',
        'delai_objectif_jours',
        'taille',
        'age',
        'sexe',
        'objectif',
        'objectif_type',
        'niveau_activite',
        'poids_a_perdre_kg',
        'delai_objectif_semaines',
        'bien_etre',
        'calories_cibles',
        'proteines_cibles',
        'glucides_cibles',
        'lipides_cibles',
        'regime_alimentaire',
    ];

    protected $casts = [
        'poids' => 'float',
        'poids_souhaite_kg' => 'float',
        'delai_objectif_jours' => 'integer',
        'taille' => 'float',
        'age' => 'integer',
        'poids_a_perdre_kg' => 'float',
        'delai_objectif_semaines' => 'integer',
        'calories_cibles' => 'integer',
        'proteines_cibles' => 'integer',
        'glucides_cibles' => 'integer',
        'lipides_cibles' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
