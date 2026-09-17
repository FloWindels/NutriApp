<?php

namespace App\Http\Requests\Profile;

/**
 * POST /profile/preview : mêmes règles que PUT /profile, sans persistance ni consentement ;
 * le nom n'est pas nécessaire pour prévisualiser des besoins.
 */
class PreviewProfileRequest extends UpdateProfileRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['nom'] = ['sometimes', 'nullable', 'string', 'max:255'];
        unset($rules['consentement_sante']);

        return $rules;
    }
}
