<?php

namespace App\Services\Recipes;

/**
 * Normalisation des ingrédients d'une recette : `[{name, ean, amount, unit}]`.
 * Les lignes sans nom sont ignorées, `ean` vide devient null, `amount` est un float.
 */
final class RecipeIngredients
{
    /**
     * @return list<array{name: string, ean: string|null, amount: float|null, unit: string|null}>
     */
    public static function normalize(mixed $ingredients): array
    {
        if (! is_array($ingredients)) {
            return [];
        }

        $out = [];

        foreach ($ingredients as $ingredient) {
            if (! is_array($ingredient)) {
                continue;
            }

            $name = trim((string) ($ingredient['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $ean = isset($ingredient['ean']) ? trim((string) $ingredient['ean']) : null;
            if ($ean === '') {
                $ean = null;
            }

            $amount = $ingredient['amount'] ?? null;
            $unit = isset($ingredient['unit']) ? trim((string) $ingredient['unit']) : '';

            $out[] = [
                'name' => $name,
                'ean' => $ean,
                'amount' => $amount === null || $amount === '' ? null : (float) $amount,
                'unit' => $unit !== '' ? $unit : null,
            ];
        }

        return $out;
    }
}
