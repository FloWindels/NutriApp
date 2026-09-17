<?php

namespace Tests\Feature\Foods;

use Illuminate\Support\Facades\Http;

/**
 * Fiches Open Food Facts simulées (OFF_BASE_URL = https://off.test sous phpunit).
 */
trait OffFixtures
{
    /**
     * Fiche produit OFF complète (kcal directes).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function offNutella(array $overrides = []): array
    {
        return array_replace([
            'code' => '3017620422003',
            'product_name' => 'Nutella',
            'product_name_fr' => 'Nutella pâte à tartiner',
            'brands' => 'Ferrero, Nutella',
            'image_front_url' => 'https://images.off.test/nutella.jpg',
            'nutrition_data_per' => '100g',
            'serving_size' => '15 g',
            'categories_tags' => ['en:spreads', 'en:sweet-spreads'],
            'allergens_tags' => ['en:milk', 'en:nuts'],
            'nutriments' => [
                'energy-kcal_100g' => 539,
                'fat_100g' => 30.9,
                'carbohydrates_100g' => 57.5,
                'proteins_100g' => 6.3,
                'sugars_100g' => 56.3,
                'salt_100g' => 0.107,
            ],
        ], $overrides);
    }

    /**
     * Fiche produit OFF dont l'énergie n'est connue qu'en kJ (→ kcal = kJ / 4,184).
     *
     * @return array<string, mixed>
     */
    protected function offKjOnly(string $code = '3560070000000'): array
    {
        return [
            'code' => $code,
            'product_name' => 'Plain biscuits',
            'product_name_fr' => 'Biscuits nature',
            'brands' => 'Marque Repère',
            'nutrition_data_per' => '100g',
            'nutriments' => [
                'energy_100g' => 1900,
                'fat_100g' => 12,
                'carbohydrates_100g' => 70,
                'proteins_100g' => 7,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $product  null → status 0 (introuvable)
     */
    protected function fakeOffProduct(string $code, ?array $product, int $status = 200): void
    {
        $body = $product === null
            ? ['status' => 0, 'status_verbose' => 'product not found', 'code' => $code]
            : ['status' => 1, 'code' => $code, 'product' => $product];

        Http::fake([
            'https://off.test/api/v2/product/'.$code.'.json*' => Http::response($status >= 500 ? null : $body, $status),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     */
    protected function fakeOffSearch(array $products, int $status = 200): void
    {
        Http::fake([
            'https://off.test/cgi/search.pl*' => Http::response(
                $status >= 500 ? null : ['count' => count($products), 'products' => $products],
                $status
            ),
        ]);
    }
}
