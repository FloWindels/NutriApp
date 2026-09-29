<?php

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /shopping-list — le magasin et le tri sont des paramètres de LECTURE.
 *
 * Choisir un magasin ne modifie pas la liste : c'est la même liste, regardée avec les prix et les
 * rayons d'une enseigne. Le magasin préféré de la personne sert de défaut quand rien n'est passé.
 */
class IndexShoppingListRequest extends FormRequest
{
    public const TRIS = ['ajout', 'rayon'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'magasin_id' => ['nullable', 'integer', 'min:1'],
            'tri' => ['nullable', 'string', Rule::in(self::TRIS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'magasin_id' => 'magasin',
            'tri' => 'tri',
        ];
    }
}
