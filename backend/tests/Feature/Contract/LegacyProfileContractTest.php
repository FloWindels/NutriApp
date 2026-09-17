<?php

namespace Tests\Feature\Contract;

use App\Http\Resources\ProfilePayload;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gel du contrat historique de GET/PUT /profile (brief §0.3) : les 15 clés françaises restent
 * au premier niveau, jamais enveloppées dans `data`, et les nouveautés sont des clés sœurs.
 */
class LegacyProfileContractTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_KEYS = [
        'nom', 'poids', 'poids_souhaite_kg', 'delai_objectif_jours', 'taille', 'age', 'sexe',
        'objectif', 'objectif_type', 'niveau_activite',
        'calories_cibles', 'proteines_cibles', 'glucides_cibles', 'lipides_cibles', 'regime_alimentaire',
    ];

    private const NEW_KEYS = [
        'allergenes', 'aliments_exclus', 'preferences',
        'sport_niveau', 'sport_objectif', 'sport_materiel', 'sport_temps_dispo_min', 'sport_jours_semaine',
        'sport_lieu', 'sport_zones_a_eviter', 'sport_focus', 'sport_notes', 'sport_coef_calories',
        'objectif_calcul_auto', 'objectif_date_debut', 'objectif_date_fin', 'poids_reference', 'cibles_calculees_le',
        'situation_particuliere', 'consentement_parental', 'consentement_sante',
        'besoins', 'cibles_effectives', 'has_profile', 'imc', 'imc_cible',
    ];

    public function test_payload_constants_match_the_frozen_contract(): void
    {
        $this->assertSame(self::LEGACY_KEYS, ProfilePayload::LEGACY_KEYS);
        $this->assertSame(self::NEW_KEYS, ProfilePayload::NEW_KEYS);
    }

    public function test_get_profile_keeps_legacy_keys_at_top_level(): void
    {
        $user = User::factory()->create(['name' => 'Alice']);
        Profile::factory()->for($user)->create([
            'sexe' => 'femme', 'age' => 35, 'taille' => 168, 'poids' => 64, 'objectif' => 'Rester en forme',
            'objectif_type' => 'maintenir', 'niveau_activite' => 'leger', 'regime_alimentaire' => 'vegetarien',
            'calories_cibles' => 1900, 'proteines_cibles' => 100, 'glucides_cibles' => 240, 'lipides_cibles' => 60,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/profile')->assertOk();
        $json = $response->json();

        $this->assertArrayNotHasKey('data', $json);
        foreach (self::LEGACY_KEYS as $key) {
            $this->assertArrayHasKey($key, $json, "Clé historique manquante : {$key}");
        }

        $expected = array_merge(self::LEGACY_KEYS, self::NEW_KEYS);
        sort($expected);
        $actual = array_keys($json);
        sort($actual);
        $this->assertSame($expected, $actual);

        $response->assertJson([
            'nom' => 'Alice',
            'poids' => 64,
            'taille' => 168,
            'age' => 35,
            'sexe' => 'femme',
            'objectif' => 'Rester en forme',
            'objectif_type' => 'maintenir',
            'niveau_activite' => 'leger',
            'calories_cibles' => 1900,
            'proteines_cibles' => 100,
            'glucides_cibles' => 240,
            'lipides_cibles' => 60,
            'regime_alimentaire' => 'vegetarien',
        ]);
    }

    public function test_put_profile_returns_message_plus_the_same_payload(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson('/api/profile', [
            'nom' => 'Bob', 'sexe' => 'homme', 'age' => 40, 'taille' => 180, 'poids' => 82,
            'poids_souhaite_kg' => 78, 'delai_objectif_jours' => 120,
            'niveau_activite' => 'sedentaire', 'objectif_type' => 'perdre', 'regime_alimentaire' => 'omnivore',
            'consentement_sante' => true,
        ])->assertOk();

        $json = $response->json();
        $this->assertArrayNotHasKey('data', $json);
        $this->assertSame('Profil mis à jour.', $json['message']);

        $expected = array_merge(['message'], self::LEGACY_KEYS, self::NEW_KEYS);
        sort($expected);
        $actual = array_keys($json);
        sort($actual);
        $this->assertSame($expected, $actual);

        $get = $this->getJson('/api/profile')->assertOk()->json();
        unset($json['message']);
        $this->assertSame($get, $json);
    }

    public function test_legacy_payload_without_profile_still_has_all_keys(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $json = $this->getJson('/api/profile')->assertOk()->json();

        foreach (self::LEGACY_KEYS as $key) {
            $this->assertArrayHasKey($key, $json);
        }
        $this->assertFalse($json['has_profile']);
    }

    public function test_both_api_prefixes_serve_the_profile(): void
    {
        Sanctum::actingAs(User::factory()->create(['name' => 'Chloé']), ['*']);

        $this->getJson('/api/profile')->assertOk()->assertJsonPath('nom', 'Chloé');
        $this->getJson('/api/v1/profile')->assertOk()->assertJsonPath('nom', 'Chloé');
        $this->getJson('/api/v1/weights')->assertOk()->assertExactJson(['data' => []]);
    }
}
