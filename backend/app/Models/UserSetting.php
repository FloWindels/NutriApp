<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    use HasFactory;

    protected $table = 'user_settings';

    protected $fillable = [
        'user_id',
        'notif_peremption',
        'notif_rappel_repas',
        'notif_rappel_sport',
        'heure_rappel',
        'jours_alerte_peremption',
        'unites',
        'theme',
        'langue',
        'timezone',
        'ia_seances',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'notif_peremption' => 'boolean',
        'notif_rappel_repas' => 'boolean',
        'notif_rappel_sport' => 'boolean',
        'jours_alerte_peremption' => 'integer',
        'ia_seances' => 'boolean',
    ];

    protected $attributes = [
        'notif_peremption' => true,
        'notif_rappel_repas' => false,
        'notif_rappel_sport' => false,
        'jours_alerte_peremption' => 3,
        'unites' => 'metrique',
        'theme' => 'systeme',
        'langue' => 'fr',
        'timezone' => 'Europe/Paris',
        'ia_seances' => true,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
