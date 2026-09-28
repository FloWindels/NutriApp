<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Trace d'une consommation : qui a utilisé quel code, et quand. */
class CodeAccesUtilisation extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'codes_acces_utilisations';

    protected $fillable = ['code_acces_id', 'user_id'];
}
