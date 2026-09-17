<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        // Identité & mesures
        'poids',
        'poids_souhaite_kg',
        'delai_objectif_jours',
        'taille',
        'age',
        'sexe',
        // Objectif
        'objectif',
        'objectif_type',
        'niveau_activite',
        'poids_a_perdre_kg',
        'delai_objectif_semaines',
        'bien_etre',
        'objectif_calcul_auto',
        'objectif_date_debut',
        'objectif_date_fin',
        'poids_reference',
        'cibles_calculees_le',
        // Cibles
        'calories_cibles',
        'proteines_cibles',
        'glucides_cibles',
        'lipides_cibles',
        // Alimentation
        'regime_alimentaire',
        'allergenes',
        'aliments_exclus',
        'preferences',
        // Sport
        'sport_niveau',
        'sport_objectif',
        'sport_materiel',
        'sport_temps_dispo_min',
        'sport_jours_semaine',
        'sport_lieu',
        'sport_zones_a_eviter',
        'sport_focus',
        'sport_notes',
        'sport_coef_calories',
        // Sécurité
        'situation_particuliere',
        'consentement_parental',
    ];

    protected $casts = [
        'poids' => 'float',
        'poids_souhaite_kg' => 'float',
        'delai_objectif_jours' => 'integer',
        'taille' => 'float',
        'age' => 'integer',
        'poids_a_perdre_kg' => 'float',
        'delai_objectif_semaines' => 'integer',
        'objectif_calcul_auto' => 'boolean',
        'objectif_date_debut' => 'date:Y-m-d',
        'objectif_date_fin' => 'date:Y-m-d',
        'poids_reference' => 'float',
        'cibles_calculees_le' => 'date:Y-m-d',
        'calories_cibles' => 'integer',
        'proteines_cibles' => 'integer',
        'glucides_cibles' => 'integer',
        'lipides_cibles' => 'integer',
        'allergenes' => 'array',
        'aliments_exclus' => 'array',
        'preferences' => 'array',
        'sport_materiel' => 'array',
        'sport_temps_dispo_min' => 'integer',
        'sport_jours_semaine' => 'integer',
        'sport_zones_a_eviter' => 'array',
        'sport_focus' => 'array',
        'sport_coef_calories' => 'integer',
        'consentement_parental' => 'boolean',
    ];

    protected $attributes = [
        'objectif_calcul_auto' => true,
        'situation_particuliere' => 'aucune',
        'consentement_parental' => false,
        'sport_coef_calories' => 100,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isMinor(): bool
    {
        return $this->age !== null && $this->age < 18;
    }
}
