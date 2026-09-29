<?php

namespace App\Http\Requests\Magasins;

use App\Enums\Rayon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProduitsRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 50;

    public const PER_PAGE_MAX = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:80'],
            'rayon' => ['nullable', 'string', Rule::in(Rayon::values())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE_MAX],
        ];
    }
}
