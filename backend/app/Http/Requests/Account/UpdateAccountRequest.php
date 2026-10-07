<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('email'))) {
            $merge['email'] = mb_strtolower(trim($this->input('email')));
        }

        if (is_string($this->input('name'))) {
            $merge['name'] = trim($this->input('name'));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->id),
            ],
            // Changer d'adresse, c'est changer la clé du compte (connexion, réinitialisation) :
            // un jeton volé ne doit pas suffire. Le nom seul se modifie sans mot de passe.
            'current_password' => [Rule::requiredIf(fn (): bool => $this->changeEmail()), 'nullable', 'string'],
        ];
    }

    /** L'adresse demandée diffère-t-elle de l'adresse actuelle ? */
    public function changeEmail(): bool
    {
        $email = $this->input('email');

        return is_string($email)
            && $this->user() !== null
            && $email !== mb_strtolower((string) $this->user()->email);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Confirme ton mot de passe actuel pour changer d’adresse e-mail.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'email' => 'adresse e-mail',
            'current_password' => 'mot de passe actuel',
        ];
    }
}
