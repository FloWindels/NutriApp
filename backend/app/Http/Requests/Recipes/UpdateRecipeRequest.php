<?php

namespace App\Http\Requests\Recipes;

use App\Models\Recipe;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * PUT /recipes/{recipe} — mêmes règles que la création ; seul le créateur peut modifier
 * (403 avec le message legacy, évalué avant la validation comme auparavant).
 */
class UpdateRecipeRequest extends StoreRecipeRequest
{
    public const FORBIDDEN_MESSAGE = 'Seul le createur peut modifier cette recette.';

    public function authorize(): bool
    {
        $recipe = $this->route('recipe');
        $user = $this->user();

        return $recipe instanceof Recipe
            && $user !== null
            && $recipe->created_by_user_id === (int) $user->id;
    }

    protected function failedAuthorization(): void
    {
        throw new HttpException(403, self::FORBIDDEN_MESSAGE);
    }
}
