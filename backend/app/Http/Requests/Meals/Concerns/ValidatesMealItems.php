<?php

namespace App\Http\Requests\Meals\Concerns;

use App\Support\Portions;
use Illuminate\Validation\Validator;

/**
 * Règles partagées d'un `ItemInput` (brief §4.2) :
 * `{food_id? | recipe_id? | custom?:{label, per_100g?, calories, proteins, carbs, fat}, quantity, unit, stock_item_id?, decrement_stock?}`
 * — exactement une source parmi food_id / recipe_id / custom, quantité > 0, unité connue de Portions.
 */
trait ValidatesMealItems
{
    public const MSG_SOURCE_UNIQUE = 'Indique un aliment, une recette ou un aliment personnalisé (une seule source).';

    public const MSG_UNITE_INCONNUE = 'L’unité « :unit » n’est pas reconnue.';

    public const MSG_UNITE_PERSONNALISE = 'L’unité d’un aliment personnalisé doit être g, ml, pièce ou portion.';

    /** Unités canoniques acceptées pour un aliment personnalisé (brief §4.2). */
    public const CUSTOM_UNITS = ['g', 'ml', 'piece', 'portion'];

    /**
     * Règles d'un ItemInput, préfixées (ex. « items.*. ») pour la validation imbriquée.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function itemRules(string $prefix = ''): array
    {
        $withCustom = 'required_with:'.$prefix.'custom';

        return [
            $prefix.'food_id' => ['nullable', 'integer', 'exists:food,id'],
            $prefix.'recipe_id' => ['nullable', 'integer', 'exists:recipes,id'],
            $prefix.'custom' => ['nullable', 'array'],
            $prefix.'custom.label' => [$withCustom, 'string', 'max:255'],
            $prefix.'custom.per_100g' => ['sometimes', 'boolean'],
            $prefix.'custom.calories' => [$withCustom, 'numeric', 'min:0', 'max:100000'],
            $prefix.'custom.proteins' => [$withCustom, 'numeric', 'min:0', 'max:100000'],
            $prefix.'custom.carbs' => [$withCustom, 'numeric', 'min:0', 'max:100000'],
            $prefix.'custom.fat' => [$withCustom, 'numeric', 'min:0', 'max:100000'],
            $prefix.'custom.fiber' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            $prefix.'custom.sugar' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            $prefix.'custom.salt' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            $prefix.'quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            $prefix.'unit' => $this->unitRules(),
            $prefix.'stock_item_id' => ['nullable', 'integer'],
            $prefix.'decrement_stock' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Règles de l'unité : obligatoire et connue de Portions (alias compris).
     *
     * @return array<int, mixed>
     */
    protected function unitRules(): array
    {
        return [
            'required',
            'string',
            'max:32',
            function (string $attribute, mixed $value, callable $fail): void {
                if (! Portions::isKnown(is_string($value) ? $value : null)) {
                    $fail(str_replace(':unit', (string) $value, self::MSG_UNITE_INCONNUE));
                }
            },
        ];
    }

    /**
     * Noms français des champs d'un ItemInput.
     *
     * @return array<string, string>
     */
    protected function itemAttributes(string $prefix = ''): array
    {
        return [
            $prefix.'food_id' => 'aliment',
            $prefix.'recipe_id' => 'recette',
            $prefix.'custom' => 'aliment personnalisé',
            $prefix.'custom.label' => 'libellé',
            $prefix.'custom.per_100g' => 'valeurs pour 100 g',
            $prefix.'custom.calories' => 'calories',
            $prefix.'custom.proteins' => 'protéines',
            $prefix.'custom.carbs' => 'glucides',
            $prefix.'custom.fat' => 'lipides',
            $prefix.'custom.fiber' => 'fibres',
            $prefix.'custom.sugar' => 'sucres',
            $prefix.'custom.salt' => 'sel',
            $prefix.'quantity' => 'quantité',
            $prefix.'unit' => 'unité',
            $prefix.'stock_item_id' => 'article du stock',
            $prefix.'decrement_stock' => 'retrait du stock',
        ];
    }

    /**
     * Vérifie « exactement une source » et l'unité d'un aliment personnalisé.
     *
     * @param  array<string, mixed>  $item
     * @param  string  $errorKey  clé d'erreur pour la règle de source (ex. « items.0 » ou « food_id »)
     * @param  string  $prefix  préfixe des champs de l'élément (ex. « items.0. »)
     */
    protected function validateItemSource(Validator $validator, array $item, string $errorKey, string $prefix = ''): void
    {
        $hasFood = ! empty($item['food_id']);
        $hasRecipe = ! empty($item['recipe_id']);
        $hasCustom = isset($item['custom']) && is_array($item['custom']);

        $sources = (int) $hasFood + (int) $hasRecipe + (int) $hasCustom;
        if ($sources !== 1) {
            $validator->errors()->add($errorKey, self::MSG_SOURCE_UNIQUE);

            return;
        }

        if ($hasCustom && isset($item['unit']) && is_string($item['unit'])) {
            $canonical = Portions::canonical($item['unit']);
            if ($canonical !== null && ! in_array($canonical, self::CUSTOM_UNITS, true)) {
                $validator->errors()->add($prefix.'unit', self::MSG_UNITE_PERSONNALISE);
            }
        }
    }
}
