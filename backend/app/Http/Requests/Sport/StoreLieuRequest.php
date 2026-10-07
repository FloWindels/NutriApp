<?php

namespace App\Http\Requests\Sport;

use App\Enums\Equipment;
use App\Enums\Lieu;
use Illuminate\Validation\Rule;

/**
 * POST /sport/lieux — nouveau lieu d'entraînement (Sport v2 §1) : nom, type, matériel.
 */
class StoreLieuRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'min:1', 'max:60'],
            'type' => ['required', 'string', Rule::enum(Lieu::class)],
            'materiel' => ['sometimes', 'array', 'max:'.count(Equipment::values())],
            'materiel.*' => ['string', Rule::in(Equipment::values())],
            'par_defaut' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'nom' => 'nom du lieu',
            'type' => 'type de lieu',
            'materiel' => 'matériel',
            'materiel.*' => 'matériel',
            'par_defaut' => 'lieu par défaut',
        ]);
    }
}
