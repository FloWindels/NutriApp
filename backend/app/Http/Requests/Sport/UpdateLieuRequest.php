<?php

namespace App\Http\Requests\Sport;

use App\Enums\Equipment;
use App\Enums\Lieu;
use Illuminate\Validation\Rule;

/**
 * PUT /sport/lieux/{lieu} — mêmes champs qu'à la création, chacun facultatif.
 */
class UpdateLieuRequest extends StoreLieuRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'required', 'string', 'min:1', 'max:60'],
            'type' => ['sometimes', 'required', 'string', Rule::enum(Lieu::class)],
            'materiel' => ['sometimes', 'array', 'max:'.count(Equipment::values())],
            'materiel.*' => ['string', Rule::in(Equipment::values())],
            'par_defaut' => ['sometimes', 'boolean'],
        ];
    }
}
