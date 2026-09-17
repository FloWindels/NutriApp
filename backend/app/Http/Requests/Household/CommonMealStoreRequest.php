<?php

namespace App\Http\Requests\Household;

use App\Enums\MealType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommonMealStoreRequest extends FormRequest
{
    public const PORTIONS_MIN = 0.25;
    public const PORTIONS_MAX = 5;

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
            'recipe_id' => ['required', 'integer', 'exists:recipes,id'],
            'meal_type' => ['required', 'string', Rule::enum(MealType::class)],
            'date' => ['required', 'date_format:Y-m-d'],
            'portions' => ['required', 'array', 'min:1'],
            'portions.*' => ['required', 'numeric', 'min:'.self::PORTIONS_MIN, 'max:'.self::PORTIONS_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'recipe_id' => 'recette',
            'meal_type' => 'type de repas',
            'date' => 'date',
            'portions' => 'portions',
            'portions.*' => 'nombre de portions',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'portions.min' => 'Indique au moins un membre.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $portions = $this->input('portions');
            if (! is_array($portions)) {
                return;
            }

            foreach (array_keys($portions) as $key) {
                if (! is_int($key) && ! ctype_digit((string) $key)) {
                    $v->errors()->add('portions', 'Les clés de « portions » doivent être des identifiants de membres.');

                    return;
                }
            }
        });
    }
}
