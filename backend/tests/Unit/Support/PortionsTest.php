<?php

namespace Tests\Unit\Support;

use App\Enums\Unit;
use App\Models\Food;
use App\Support\Conversion;
use App\Support\Portions;
use Tests\TestCase;

class PortionsTest extends TestCase
{
    public function test_normalisation_des_alias(): void
    {
        $this->assertSame(['piece', 1.0], Portions::normalize('unité'));
        $this->assertSame(['piece', 1.0], Portions::normalize('Pièce'));
        $this->assertSame(['piece', 1.0], Portions::normalize('pc'));
        $this->assertSame(['piece', 1.0], Portions::normalize('unite'));
        $this->assertSame(['ml', 10.0], Portions::normalize('cl'));
        $this->assertSame(['ml', 1000.0], Portions::normalize('L'));
        $this->assertSame(['g', 1000.0], Portions::normalize('kg'));
        $this->assertSame(['cas', 1.0], Portions::normalize('c. à s.'));
        $this->assertSame(['cas', 1.0], Portions::normalize('cs'));
        $this->assertSame(['cas', 1.0], Portions::normalize('cuillere_soupe'));
        $this->assertSame(['cas', 1.0], Portions::normalize('cuillère à soupe'));
        $this->assertSame(['cac', 1.0], Portions::normalize('c. à c.'));
        $this->assertSame(['cac', 1.0], Portions::normalize('cc'));
        $this->assertSame(['cac', 1.0], Portions::normalize('cuillere_cafe'));
        $this->assertSame(['poignee', 1.0], Portions::normalize('poignée'));
        $this->assertSame([null, 1.0], Portions::normalize('boîte'));
        $this->assertSame([null, 1.0], Portions::normalize(null));

        $this->assertSame(Unit::Piece, Portions::unit('pièce'));
        $this->assertNull(Portions::unit('boîte'));
        $this->assertTrue(Portions::isKnown('KG'));
        $this->assertFalse(Portions::isKnown('sachet'));
    }

    public function test_grammes_exacts(): void
    {
        $c = Portions::toGrams(150, 'g');

        $this->assertSame(150.0, $c->grams);
        $this->assertFalse($c->is_estimate);
        $this->assertSame(Conversion::CONFIDENCE_EXACTE, $c->confidence);
        $this->assertSame(2000.0, Portions::toGrams(2, 'kg')->grams);
    }

    public function test_millilitres_avec_et_sans_densite(): void
    {
        $sans = Portions::toGrams(20, 'cl');
        $this->assertSame(200.0, $sans->grams);
        $this->assertTrue($sans->is_estimate);
        $this->assertSame('ml', $sans->unit);
        $this->assertSame(200.0, $sans->quantity);

        $huile = (new Food)->forceFill(['density_g_per_ml' => 0.92]);
        $avec = Portions::toGrams(100, 'ml', $huile);
        $this->assertSame(92.0, $avec->grams);
        $this->assertFalse($avec->is_estimate);
        $this->assertSame(Conversion::CONFIDENCE_BONNE, $avec->confidence);
    }

    public function test_mesures_menageres(): void
    {
        $this->assertSame(30.0, Portions::toGrams(2, 'cas')->grams);
        $this->assertSame(5.0, Portions::toGrams(1, 'c. à c.')->grams);
        $this->assertSame(200.0, Portions::toGrams(1, 'verre')->grams);
        $this->assertSame(300.0, Portions::toGrams(1, 'bol')->grams);
        $this->assertSame(350.0, Portions::toGrams(1, 'assiette')->grams);
        $this->assertSame(60.0, Portions::toGrams(2, 'poignee')->grams);
        $this->assertSame(90.0, Portions::toGrams(3, 'tranches')->grams);
        $this->assertTrue(Portions::toGrams(1, 'verre')->is_estimate);
    }

    public function test_piece_serving_size_puis_categorie_puis_100_g(): void
    {
        $oeuf = (new Food)->forceFill(['name' => 'Œuf de poule', 'serving_size_g' => 55]);
        $c = Portions::toGrams(2, 'pièce', $oeuf);
        $this->assertSame(110.0, $c->grams);
        $this->assertTrue($c->is_estimate);
        $this->assertSame(Conversion::CONFIDENCE_BONNE, $c->confidence);

        $yaourt = (new Food)->forceFill(['name' => 'Yaourt nature', 'category' => null]);
        $c = Portions::toGrams(1, 'portion', $yaourt);
        $this->assertSame(125.0, $c->grams);
        $this->assertSame(Conversion::CONFIDENCE_MOYENNE, $c->confidence);

        $categorie = (new Food)->forceFill(['name' => 'Camembert', 'category' => 'fromages']);
        $this->assertSame(30.0, Portions::toGrams(1, 'piece', $categorie)->grams);

        $inconnu = (new Food)->forceFill(['name' => 'Truc']);
        $c = Portions::toGrams(1, 'piece', $inconnu);
        $this->assertSame(100.0, $c->grams);
        $this->assertSame(Conversion::CONFIDENCE_FAIBLE, $c->confidence);

        $sansAliment = Portions::toGrams(1, 'piece');
        $this->assertSame(100.0, $sansAliment->grams);
        $this->assertSame(Conversion::CONFIDENCE_FAIBLE, $sansAliment->confidence);
    }

    public function test_unite_inconnue(): void
    {
        $c = Portions::toGrams(2, 'sachet');

        $this->assertNull($c->grams);
        $this->assertFalse($c->isConvertible());
        $this->assertSame(Conversion::CONFIDENCE_INCONNUE, $c->confidence);
        $this->assertSame(['grams' => null, 'is_estimate' => true, 'confidence' => 'inconnue', 'note' => 'Unité inconnue : conversion impossible.'], $c->toArray());
    }

    public function test_catalogue_pour_get_portions(): void
    {
        $catalog = Portions::catalog();

        $this->assertCount(count(Unit::cases()), $catalog);
        $byUnit = collect($catalog)->keyBy('unit');

        $this->assertSame(['unit', 'label', 'label_short', 'grams', 'step', 'is_estimate'], array_keys($catalog[0]));
        $this->assertSame('gramme', $byUnit['g']['label']);
        $this->assertSame('g', $byUnit['g']['label_short']);
        $this->assertSame(10, $byUnit['g']['step']);
        $this->assertFalse($byUnit['g']['is_estimate']);
        $this->assertSame(0.5, $byUnit['piece']['step']);
        $this->assertNull($byUnit['piece']['grams']);
        $this->assertSame('pièce', $byUnit['piece']['label']);
        $this->assertSame('cuillère à soupe', $byUnit['cas']['label']);
        $this->assertSame('c. à s.', $byUnit['cas']['label_short']);
        $this->assertSame(15.0, $byUnit['cas']['grams']);
        $this->assertSame(1, $byUnit['cas']['step']);
        $this->assertTrue($byUnit['cas']['is_estimate']);
        $this->assertSame('poignée', $byUnit['poignee']['label']);

        $aliases = Portions::aliases();
        $this->assertSame('piece', $aliases['unité']);
        $this->assertSame('cas', $aliases['c. à s.']);
        $this->assertSame('ml×10', $aliases['cl']);
    }
}
