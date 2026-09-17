<?php

namespace Tests\Unit\Services;

use App\Exceptions\OffUnavailableException;
use App\Services\OpenFoodFactsClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenFoodFactsClientTest extends TestCase
{
    private OpenFoodFactsClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new OpenFoodFactsClient;
    }

    /** @return array<string, mixed> */
    private function nutella(): array
    {
        return [
            'code' => '3017620422003',
            'product_name' => 'Nutella',
            'product_name_fr' => 'Nutella pâte à tartiner',
            'brands' => 'Ferrero, Nutella',
            'image_front_url' => 'https://images.off.test/nutella.jpg',
            'nutrition_data_per' => '100g',
            'serving_size' => '15 g',
            'categories_tags' => ['en:spreads', 'en:sweet-spreads'],
            'allergens_tags' => ['en:milk', 'en:nuts', 'en:soybeans'],
            'nutriments' => [
                'energy-kcal_100g' => 539,
                'energy_100g' => 2255,
                'fat_100g' => 30.9,
                'carbohydrates_100g' => 57.5,
                'proteins_100g' => 6.3,
                'fiber_100g' => '',
                'sugars_100g' => 56.3,
                'salt_100g' => 0.107,
            ],
        ];
    }

    public function test_base_url_vient_de_la_config_de_test(): void
    {
        $this->assertSame('https://off.test', $this->client->baseUrl());
    }

    public function test_product_normalise_une_fiche(): void
    {
        Http::fake([
            'https://off.test/api/v2/product/3017620422003.json*' => Http::response(['status' => 1, 'product' => $this->nutella()]),
        ]);

        $row = $this->client->product('3017620422003');

        $this->assertNotNull($row);
        $this->assertSame('3017620422003', $row['barcode']);
        $this->assertSame('Nutella pâte à tartiner', $row['name']); // fr prioritaire
        $this->assertSame('Ferrero', $row['brand']);
        $this->assertSame('https://images.off.test/nutella.jpg', $row['image_url']);
        $this->assertSame(539.0, $row['calories']);
        $this->assertFalse($row['calories_from_kj']);
        $this->assertSame(30.9, $row['fat']);
        $this->assertSame(57.5, $row['carbs']);
        $this->assertSame(6.3, $row['proteins']);
        $this->assertNull($row['fiber']);
        $this->assertSame(56.3, $row['sugar']);
        $this->assertSame(0.11, $row['salt']);
        $this->assertSame('100g', $row['per_unit']);
        $this->assertSame(15.0, $row['serving_size_g']);
        $this->assertSame('15 g', $row['serving_label']);
        $this->assertSame('spreads', $row['category']);
        $this->assertSame(['en:milk', 'en:nuts', 'en:soybeans'], $row['allergens']);
        $this->assertSame('open_food_facts', $row['source_type']);

        Http::assertSent(function (Request $request) {
            return str_starts_with($request->url(), 'https://off.test/api/v2/product/3017620422003.json')
                && str_contains($request->url(), 'fields=')
                && $request->hasHeader('User-Agent');
        });
    }

    public function test_kj_vers_kcal_quand_les_kcal_manquent(): void
    {
        $row = $this->client->normalize([
            'code' => '1234567890123',
            'product_name' => 'Boisson',
            'nutriments' => ['energy_100g' => 2255],
        ]);

        $this->assertSame(539.0, $row['calories']);
        $this->assertTrue($row['calories_from_kj']);

        $kj = $this->client->normalize(['nutriments' => ['energy-kj_100g' => 418.4]]);
        $this->assertSame(100.0, $kj['calories']);
        $this->assertTrue($kj['calories_from_kj']);

        $rien = $this->client->normalize(['nutriments' => []]);
        $this->assertNull($rien['calories']);
        $this->assertFalse($rien['calories_from_kj']);
    }

    public function test_priorite_des_noms_et_valeurs_par_defaut(): void
    {
        $this->assertSame('Nom EN', $this->client->normalize(['product_name_en' => 'Nom EN', 'product_name' => 'Nom'])['name']);
        $this->assertSame('Nom', $this->client->normalize(['product_name' => 'Nom', 'generic_name_fr' => 'Générique'])['name']);
        $this->assertSame('Générique', $this->client->normalize(['product_name' => '  ', 'generic_name_fr' => 'Générique'])['name']);
        $this->assertSame('Abrégé', $this->client->normalize(['abbreviated_product_name' => 'Abrégé'])['name']);
        $this->assertSame('Produit sans nom', $this->client->normalize([])['name']);

        $vide = $this->client->normalize(['brands' => '', 'image_url' => 'https://images.off.test/x.jpg']);
        $this->assertNull($vide['brand']);
        $this->assertSame('https://images.off.test/x.jpg', $vide['image_url']);
        $this->assertNull($vide['barcode']);
        $this->assertNull($vide['category']);
        $this->assertNull($vide['allergens']);
        $this->assertSame('100g', $vide['per_unit']);
    }

    public function test_parsing_de_la_portion(): void
    {
        $this->assertSame(25.0, $this->client->normalize(['serving_size' => '2 biscuits (25g)'])['serving_size_g']);
        $this->assertSame(250.0, $this->client->normalize(['serving_size' => '250ml'])['serving_size_g']);
        $this->assertSame(12.5, $this->client->normalize(['serving_size' => '12,5 g'])['serving_size_g']);
        $this->assertNull($this->client->normalize(['serving_size' => 'une poignée'])['serving_size_g']);
        $this->assertSame('une poignée', $this->client->normalize(['serving_size' => 'une poignée'])['serving_label']);
        $this->assertNull($this->client->normalize([])['serving_label']);
    }

    public function test_per_unit_100ml_et_code_non_numerique(): void
    {
        $row = $this->client->normalize(['code' => 'abc', 'nutrition_data_per' => '100ml']);

        $this->assertSame('100ml', $row['per_unit']);
        $this->assertNull($row['barcode']);
    }

    public function test_product_inconnu_retourne_null(): void
    {
        Http::fake([
            'https://off.test/api/v2/product/0000000000000.json*' => Http::response(['status' => 0, 'status_verbose' => 'product not found'], 404),
            'https://off.test/api/v2/product/1111111111111.json*' => Http::response(['status' => 0, 'status_verbose' => 'product not found'], 200),
        ]);

        $this->assertNull($this->client->product('0000000000000'));
        $this->assertNull($this->client->product('1111111111111'));
    }

    public function test_erreur_serveur_leve_off_unavailable(): void
    {
        Http::fake(['https://off.test/*' => Http::response('oops', 503)]);

        $this->expectException(OffUnavailableException::class);
        $this->client->product('3017620422003');
    }

    public function test_erreur_reseau_leve_off_unavailable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->expectException(OffUnavailableException::class);
        $this->client->search('nutella');
    }

    public function test_off_unavailable_se_rend_en_502(): void
    {
        $response = (new OffUnavailableException)->render();

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame(['message' => 'Open Food Facts indisponible.'], $response->getData(true));
    }

    public function test_search_retourne_des_lignes_normalisees(): void
    {
        Http::fake([
            'https://off.test/cgi/search.pl*' => Http::response([
                'count' => 2,
                'products' => [
                    $this->nutella(),
                    ['code' => '', 'product_name' => 'Sans code', 'nutriments' => ['energy_100g' => 418.4]],
                    'pas un produit',
                ],
            ]),
        ]);

        $rows = $this->client->search('nutella');

        $this->assertCount(2, $rows);
        $this->assertSame('3017620422003', $rows[0]['barcode']);
        $this->assertNull($rows[1]['barcode']);
        $this->assertSame(100.0, $rows[1]['calories']);

        Http::assertSent(function (Request $request) {
            return str_starts_with($request->url(), 'https://off.test/cgi/search.pl')
                && $request['search_terms'] === 'nutella'
                && (string) $request['page_size'] === '10'
                && (string) $request['json'] === '1';
        });
    }
}
