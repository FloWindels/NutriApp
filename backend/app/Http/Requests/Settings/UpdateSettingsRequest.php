<?php

namespace App\Http\Requests\Settings;

use App\Enums\Theme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /settings — mise à jour partielle (`sometimes`) des paramètres (brief §14).
 */
class UpdateSettingsRequest extends FormRequest
{
    public const UNITES = ['metrique', 'imperial'];

    public const LANGUES = ['fr', 'en'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'notif_peremption' => ['sometimes', 'boolean'],
            'notif_rappel_repas' => ['sometimes', 'boolean'],
            'notif_rappel_sport' => ['sometimes', 'boolean'],
            'heure_rappel' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'jours_alerte_peremption' => ['sometimes', 'integer', 'min:1', 'max:14'],
            'unites' => ['sometimes', 'string', Rule::in(self::UNITES)],
            'theme' => ['sometimes', 'string', Rule::enum(Theme::class)],
            'langue' => ['sometimes', 'string', Rule::in(self::LANGUES)],
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
            'ia_seances' => ['sometimes', 'boolean'],
            'partage_profil_foyer' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'notif_peremption' => 'alerte de péremption',
            'notif_rappel_repas' => 'rappel des repas',
            'notif_rappel_sport' => 'rappel des séances',
            'heure_rappel' => 'heure du rappel',
            'jours_alerte_peremption' => 'jours d’alerte avant péremption',
            'unites' => 'unités',
            'theme' => 'thème',
            'langue' => 'langue',
            'timezone' => 'fuseau horaire',
            'ia_seances' => 'séances proposées par l’IA',
            'partage_profil_foyer' => 'partage du profil avec le foyer',
        ];
    }
}
