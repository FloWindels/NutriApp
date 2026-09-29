<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un accord donné pour perdre plus vite que le rythme conseillé.
 *
 * En ajout seul, comme le journal d'administration : on date la fin d'un accord, on ne l'efface
 * pas. Retirer son consentement coupe l'effet, pas la preuve.
 */
class ConsentementRythme extends Model
{
    use HasFactory;

    protected $table = 'consentements_rythme';

    protected $fillable = [
        'user_id', 'donne_le', 'version_texte', 'deficit_kcal', 'kg_semaine', 'retire_le',
    ];

    protected $casts = [
        'donne_le' => 'datetime',
        'retire_le' => 'datetime',
        'deficit_kcal' => 'integer',
        'kg_semaine' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
