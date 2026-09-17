<?php

namespace App\Http\Requests\Household;

use App\Models\Household;
use App\Services\Household\HouseholdService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Code d'invitation : 8 caractères, comparés en majuscules (alphabet sans 0/O/1/I).
 * Utilisé par `POST /household/join` (corps) et `GET /household/preview` (query).
 */
class JoinHouseholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'invite_code' => ['required', 'string', 'size:'.Household::INVITE_LENGTH, 'regex:/^[A-Z0-9]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'invite_code' => 'code d’invitation',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'invite_code.size' => 'Le code d’invitation doit comporter 8 caractères.',
            'invite_code.regex' => 'Le code d’invitation ne contient que des lettres et des chiffres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('invite_code');

        if (is_string($code)) {
            $this->merge(['invite_code' => HouseholdService::normalizeCode($code)]);
        }
    }

    public function inviteCode(): string
    {
        return (string) $this->validated('invite_code');
    }
}
