<?php

namespace App\Services;

use App\Exceptions\OffUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client Open Food Facts (brief §3.2). Toute défaillance de transport (réseau, délai, 5xx)
 * lève OffUnavailableException (→ 502). Un produit inconnu retourne null.
 *
 * `normalize()` retourne les colonnes de `food` + une clé hors table `calories_from_kj`
 * (vrai quand les kcal ont été dérivées des kJ → FoodResource.is_estimate). À retirer
 * (`Arr::except`) avant un `Food::upsert`.
 */
class OpenFoodFactsClient
{
    public const PRODUCT_FIELDS = 'code,product_name,product_name_fr,product_name_en,generic_name,generic_name_fr,abbreviated_product_name,brands,image_front_url,image_url,nutriments,serving_size,categories_tags,allergens_tags,nutrition_data_per';

    public const SEARCH_FIELDS = 'code,product_name,product_name_fr,brands,image_front_url,nutriments,serving_size,categories_tags,allergens_tags,nutrition_data_per';

    public const KJ_PAR_KCAL = 4.184;

    public const NOM_PAR_DEFAUT = 'Produit sans nom';

    /**
     * Fiche produit normalisée, ou null si OFF ne connaît pas ce code.
     *
     * @return array<string, mixed>|null
     */
    public function product(string $ean): ?array
    {
        $ean = trim($ean);
        $response = $this->get($this->baseUrl().'/api/v2/product/'.rawurlencode($ean).'.json', [
            'fields' => self::PRODUCT_FIELDS,
        ]);

        if ($response->status() === 404) {
            return null;
        }

        $this->guard($response);

        $json = $response->json();
        if (! is_array($json) || (int) ($json['status'] ?? 0) !== 1 || ! is_array($json['product'] ?? null)) {
            return null;
        }

        return $this->normalize($json['product']);
    }

    /**
     * Recherche textuelle (10 résultats max), lignes normalisées.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $q): array
    {
        $response = $this->get($this->baseUrl().'/cgi/search.pl', [
            'search_terms' => trim($q),
            'search_simple' => 1,
            'action' => 'process',
            'json' => 1,
            'page_size' => 10,
            'fields' => self::SEARCH_FIELDS,
        ]);

        $this->guard($response);

        $products = $response->json('products');
        if (! is_array($products)) {
            return [];
        }

        $rows = [];
        foreach ($products as $product) {
            if (is_array($product)) {
                $rows[] = $this->normalize($product);
            }
        }

        return $rows;
    }

    /**
     * Normalise un produit OFF vers les colonnes de `food`.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    public function normalize(array $product): array
    {
        $nutriments = is_array($product['nutriments'] ?? null) ? $product['nutriments'] : [];

        // Nom : priorité fr > en > générique > abrégé > défaut
        $name = self::NOM_PAR_DEFAUT;
        foreach (['product_name_fr', 'product_name_en', 'product_name', 'generic_name_fr', 'generic_name', 'abbreviated_product_name'] as $key) {
            $candidate = trim((string) ($product[$key] ?? ''));
            if ($candidate !== '') {
                $name = mb_substr($candidate, 0, 255);
                break;
            }
        }

        // Marque : première de la liste séparée par des virgules
        $brand = null;
        $brands = trim((string) ($product['brands'] ?? ''));
        if ($brands !== '') {
            $first = trim((string) explode(',', $brands)[0]);
            $brand = $first !== '' ? mb_substr($first, 0, 255) : null;
        }

        // Image
        $image = trim((string) ($product['image_front_url'] ?? ''));
        if ($image === '') {
            $image = trim((string) ($product['image_url'] ?? ''));
        }
        $image = $image !== '' ? $image : null;

        // Énergie : kcal directes, sinon kJ / 4,184
        $calories = null;
        $fromKj = false;
        if ($this->numeric($nutriments['energy-kcal_100g'] ?? null)) {
            $calories = (float) $nutriments['energy-kcal_100g'];
        } elseif ($this->numeric($nutriments['energy-kcal'] ?? null)) {
            $calories = (float) $nutriments['energy-kcal'];
        } else {
            foreach (['energy-kj_100g', 'energy_100g', 'energy-kj', 'energy'] as $key) {
                if ($this->numeric($nutriments[$key] ?? null)) {
                    $calories = (float) $nutriments[$key] / self::KJ_PAR_KCAL;
                    $fromKj = true;
                    break;
                }
            }
        }
        $calories = $calories === null ? null : round($calories, 1);

        // Base nutritionnelle
        $perUnit = strtolower(trim((string) ($product['nutrition_data_per'] ?? '100g'))) === '100ml' ? '100ml' : '100g';

        // Portion : « 30 g », « 250ml », « 2 biscuits (25 g) »…
        $servingRaw = trim((string) ($product['serving_size'] ?? ''));
        $servingSizeG = null;
        $servingLabel = null;
        if ($servingRaw !== '') {
            $servingLabel = mb_substr($servingRaw, 0, 64);
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(g|ml)/i', $servingRaw, $m)) {
                $value = (float) str_replace(',', '.', $m[1]);
                $servingSizeG = strtolower($m[2]) === 'ml' ? round($value * 1.0, 2) : round($value, 2);
                if ($servingSizeG <= 0) {
                    $servingSizeG = null;
                }
            }
        }

        // Catégorie : premier tag sans préfixe de langue
        $category = null;
        $tags = $product['categories_tags'] ?? null;
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                $tag = trim((string) $tag);
                if ($tag !== '') {
                    $category = mb_substr(preg_replace('/^[a-z]{2}:/', '', $tag) ?? $tag, 0, 100);
                    break;
                }
            }
        }

        // Allergènes
        $allergens = null;
        $allergenTags = $product['allergens_tags'] ?? null;
        if (is_array($allergenTags)) {
            $allergens = array_values(array_filter(array_map(
                fn ($t) => trim((string) $t),
                $allergenTags
            ), fn ($t) => $t !== ''));
            if ($allergens === []) {
                $allergens = null;
            }
        }

        // Code-barres : uniquement s'il est numérique
        $code = trim((string) ($product['code'] ?? ''));
        $barcode = ($code !== '' && ctype_digit($code)) ? $code : null;

        return [
            'barcode' => $barcode,
            'name' => $name,
            'brand' => $brand,
            'image_url' => $image,
            'calories' => $calories,
            'fat' => $this->value($nutriments, 'fat_100g'),
            'carbs' => $this->value($nutriments, 'carbohydrates_100g'),
            'proteins' => $this->value($nutriments, 'proteins_100g'),
            'fiber' => $this->value($nutriments, 'fiber_100g'),
            'sugar' => $this->value($nutriments, 'sugars_100g'),
            'salt' => $this->value($nutriments, 'salt_100g'),
            'per_unit' => $perUnit,
            'serving_size_g' => $servingSizeG,
            'serving_label' => $servingLabel,
            'category' => $category,
            'allergens' => $allergens,
            'source_type' => 'open_food_facts',
            'calories_from_kj' => $fromKj,
        ];
    }

    // ------------------------------------------------------------------------------------

    public function baseUrl(): string
    {
        return rtrim((string) config('services.off.base_url', 'https://world.openfoodfacts.org'), '/');
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => (string) config('services.off.user_agent', 'Mavioh/1.0 (contact@mavioh.app)'),
        ])
            ->acceptJson()
            ->timeout((int) config('services.off.timeout', 6))
            ->retry(1, 250, throw: false);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $url, array $query): Response
    {
        try {
            return $this->request()->get($url, $query);
        } catch (ConnectionException $e) {
            throw new OffUnavailableException(previous: $e);
        } catch (OffUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new OffUnavailableException(previous: $e);
        }
    }

    private function guard(Response $response): void
    {
        if ($response->serverError() || ($response->failed() && $response->status() !== 404)) {
            throw new OffUnavailableException(sprintf('Open Food Facts a répondu %d.', $response->status()));
        }
    }

    /**
     * @param  array<string, mixed>  $nutriments
     */
    private function value(array $nutriments, string $key): ?float
    {
        $raw = $nutriments[$key] ?? null;

        return $this->numeric($raw) ? round((float) $raw, 2) : null;
    }

    private function numeric(mixed $value): bool
    {
        return $value !== null && $value !== '' && is_numeric($value);
    }
}
