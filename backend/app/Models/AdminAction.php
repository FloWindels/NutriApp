<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une ligne du journal d'administration. En ajout seul : aucune route ne la modifie.
 */
class AdminAction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_id', 'admin_email', 'action', 'cible_type', 'cible_id', 'cible_libelle', 'motif',
    ];
}
