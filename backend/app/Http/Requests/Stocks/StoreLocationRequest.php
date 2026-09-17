<?php

namespace App\Http\Requests\Stocks;

use App\Support\StockScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreLocationRequest extends FormRequest
{
    public const MSG_EXISTS = 'Ce lieu existe déjà.';

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
            'name' => ['required', 'string', 'min:1', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nom du lieu',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('name')) {
                return;
            }

            $name = trim((string) $this->input('name'));
            if ($name === '') {
                $validator->errors()->add('name', 'Le champ nom du lieu est obligatoire.');

                return;
            }

            $query = StockScope::query($this->user())->whereRaw('LOWER(name) = LOWER(?)', [$name]);

            $ignoreId = $this->ignoredStockId();
            if ($ignoreId !== null) {
                $query->whereKeyNot($ignoreId);
            }

            if ($query->exists()) {
                $validator->errors()->add('name', self::MSG_EXISTS);
            }
        });
    }

    /**
     * Nom nettoyé.
     */
    public function locationName(): string
    {
        return trim((string) $this->validated()['name']);
    }

    /**
     * Identifiant du lieu à ignorer lors du contrôle d'unicité (renommage).
     */
    protected function ignoredStockId(): ?int
    {
        return null;
    }
}
