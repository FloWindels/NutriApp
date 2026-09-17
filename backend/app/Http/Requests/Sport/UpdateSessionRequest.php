<?php

namespace App\Http\Requests\Sport;

use App\Enums\Intensity;
use App\Enums\Lieu;
use App\Enums\SessionStatus;
use Illuminate\Validation\Rule;

/**
 * PUT /sport/sessions/{session} — modification d'une séance (brief §13.2 + addendum §B) :
 * `calories_burned` avec une valeur ⇒ calories_source = manuel ; `calories_burned: null`
 * ⇒ recalcul automatique (calories_source = auto). Les exercices envoyés remplacent la liste.
 */
class UpdateSessionRequest extends StoreSessionRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        // Tout devient optionnel en modification ; seules les clés présentes sont appliquées.
        $rules['date'] = ['sometimes', 'required', 'date_format:Y-m-d'];
        $rules['title'] = ['sometimes', 'required', 'string', 'min:2', 'max:120'];
        $rules['duration_min'] = ['sometimes', 'required', 'integer', 'between:'.self::DUREE_MIN.','.self::DUREE_MAX];
        $rules['status'] = ['sometimes', 'required', 'string', Rule::enum(SessionStatus::class)];
        $rules['lieu'] = ['sometimes', 'nullable', 'string', Rule::enum(Lieu::class)];
        $rules['intensity'] = ['sometimes', 'nullable', 'string', Rule::enum(Intensity::class)];
        $rules['calories_burned'] = ['sometimes', 'nullable', 'numeric', 'between:0,10000'];

        return $rules;
    }
}
