<?php

namespace App\Models;

use App\Enums\Equipment;
use App\Enums\Lieu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lieu d'entraînement d'un compte (Sport v2 §1) : un nom, un type (enum Lieu) et le matériel
 * qu'on y trouve. Un seul lieu par défaut par compte : c'est lui qui sert à la génération
 * quand la demande n'en désigne aucun.
 *
 * @property int $id
 * @property int $user_id
 * @property string $nom
 * @property string $type
 * @property list<string>|null $materiel
 * @property bool $par_defaut
 */
class SportLieu extends Model
{
    protected $table = 'sport_lieux';

    /** Plafond de lieux par compte : de quoi décrire sa vie, pas de quoi remplir la base. */
    public const MAX_PAR_COMPTE = 20;

    protected $fillable = [
        'user_id',
        'nom',
        'type',
        'materiel',
        'par_defaut',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'materiel' => 'array',
        'par_defaut' => 'boolean',
    ];

    protected $attributes = [
        'par_defaut' => false,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Lieux d'un compte, le lieu par défaut en premier puis par nom.
     *
     * @param  Builder<SportLieu>  $query
     * @return Builder<SportLieu>
     */
    public function scopeOrdonnes(Builder $query): Builder
    {
        return $query->orderByDesc('par_defaut')->orderBy('nom')->orderBy('id');
    }

    /**
     * Matériel normalisé : valeurs connues, « aucun » (poids du corps) toujours présent, trié.
     *
     * @return list<string>
     */
    public function equipement(): array
    {
        return self::normaliserMateriel((array) ($this->materiel ?? []));
    }

    /**
     * @param  array<int, mixed>  $materiel
     * @return list<string>
     */
    public static function normaliserMateriel(array $materiel): array
    {
        $list = array_values(array_unique(array_intersect($materiel, Equipment::values())));
        if (! in_array('aucun', $list, true)) {
            $list[] = 'aucun';
        }
        sort($list);

        return $list;
    }

    public function typeLibelle(): string
    {
        return Lieu::tryFrom((string) $this->type)?->label() ?? (string) $this->type;
    }
}
