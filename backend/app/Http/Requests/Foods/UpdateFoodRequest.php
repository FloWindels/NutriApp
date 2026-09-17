<?php

namespace App\Http\Requests\Foods;

use App\Models\Food;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * PUT /foods/{food} — autorisé au créateur, ou à tout utilisateur pour une fiche
 * Open Food Facts jamais reprise (created_by_user_id null). Sinon 403 (message legacy).
 */
class UpdateFoodRequest extends FormRequest
{
    public const FORBIDDEN_MESSAGE = 'Seul le createur peut modifier cet aliment.';

    public function authorize(): bool
    {
        $food = $this->route('food');
        $user = $this->user();

        if (! $food instanceof Food || $user === null) {
            return false;
        }

        return self::canEdit($food, (int) $user->id);
    }

    public static function canEdit(Food $food, int $userId): bool
    {
        if ($food->created_by_user_id === $userId) {
            return true;
        }

        return $food->isFromOpenFoodFacts() && $food->created_by_user_id === null;
    }

    protected function failedAuthorization(): void
    {
        throw new HttpException(403, self::FORBIDDEN_MESSAGE);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $food = $this->route('food');
        $ignore = $food instanceof Food ? $food->id : null;

        return [
            'barcode' => [
                'nullable', 'string', 'max:32', 'regex:'.StoreFoodRequest::BARCODE_REGEX,
                Rule::unique('food', 'barcode')->ignore($ignore),
            ],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'calories' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'fat' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'carbs' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'proteins' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'fiber' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'sugar' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'salt' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'serving_size_g' => ['nullable', 'numeric', 'min:0.1', 'max:5000'],
            'serving_label' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', 'string', 'max:100'],
            'per_unit' => ['nullable', Rule::in(['100g', '100ml'])],
            'density_g_per_ml' => ['nullable', 'numeric', 'min:0.1', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'barcode.regex' => 'Le code-barres doit contenir entre 8 et 14 chiffres.',
            'barcode.unique' => 'Ce code-barres est déjà utilisé par un autre aliment.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return StoreFoodRequest::frenchAttributes();
    }
}
