<?php

namespace Tests\Feature\Profile;

use App\Http\Resources\ProfilePayload;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileShowTest extends TestCase
{
    use ProfileTestHelpers;
    use RefreshDatabase;

    public function test_get_profile_requires_authentication(): void
    {
        $this->getJson('/api/profile')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Non authentifié.']);
    }

    public function test_get_profile_without_profile_returns_full_shape_with_nulls(): void
    {
        $user = $this->actingAsUser(User::factory()->create(['name' => 'Sans Profil']));

        $response = $this->getJson('/api/profile')->assertOk();

        $response->assertJson([
            'nom' => 'Sans Profil',
            'poids' => null,
            'taille' => null,
            'calories_cibles' => null,
            'regime_alimentaire' => null,
            'has_profile' => false,
            'besoins' => null,
            'imc' => null,
            'imc_cible' => null,
            'allergenes' => [],
            'preferences' => ['aime' => [], 'evite' => []],
            'sport_coef_calories' => 100,
            'objectif_calcul_auto' => true,
            'situation_particuliere' => 'aucune',
            'consentement_parental' => false,
            'consentement_sante' => false,
            'cibles_effectives' => ['calories' => null, 'proteines' => null, 'glucides' => null, 'lipides' => null, 'source' => 'calcul'],
        ]);

        $expected = array_merge(ProfilePayload::LEGACY_KEYS, ProfilePayload::NEW_KEYS);
        sort($expected);
        $actual = array_keys($response->json());
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_get_profile_with_complete_profile_returns_besoins_and_effective_targets(): void
    {
        $user = $this->actingAsUser();
        Profile::factory()->for($user)->create([
            'sexe' => 'homme', 'age' => 30, 'taille' => 175, 'poids' => 70,
            'objectif_type' => 'maintenir', 'poids_souhaite_kg' => null, 'delai_objectif_jours' => null,
            'niveau_activite' => 'modere', 'regime_alimentaire' => 'omnivore',
            'calories_cibles' => 2560, 'proteines_cibles' => 112, 'glucides_cibles' => 335, 'lipides_cibles' => 80,
            'objectif_calcul_auto' => true,
            'allergenes' => ['arachide'], 'sport_materiel' => ['halteres'], 'sport_coef_calories' => 75,
        ]);

        $json = $this->getJson('/api/profile')->assertOk()->json();

        $this->assertTrue($json['has_profile']);
        // json_encode omet la fraction nulle (70.0 → 70) : on vérifie la valeur, le type flottant est testé ailleurs.
        $this->assertEquals(70.0, $json['poids']);
        $this->assertSame(22.9, $json['imc']);
        $this->assertNull($json['imc_cible']);
        $this->assertSame(1648.75, $json['besoins']['bmr']);
        $this->assertSame(2555.6, $json['besoins']['tdee']);
        $this->assertTrue($json['besoins']['is_estimate']);
        $this->assertFalse($json['besoins']['profil_mineur']);
        $this->assertSame(['arachide'], $json['allergenes']);
        $this->assertSame(['halteres'], $json['sport_materiel']);
        $this->assertSame(75, $json['sport_coef_calories']);
        $this->assertSame(
            ['calories' => 2560, 'proteines' => 112, 'glucides' => 335, 'lipides' => 80, 'source' => 'calcul'],
            $json['cibles_effectives']
        );
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $json['cibles_calculees_le']);
    }

    public function test_get_profile_reports_user_overrides_as_source_utilisateur(): void
    {
        $user = $this->actingAsUser();
        Profile::factory()->for($user)->create([
            'objectif_calcul_auto' => false,
            'calories_cibles' => 2000, 'proteines_cibles' => 120, 'glucides_cibles' => 220, 'lipides_cibles' => 70,
        ]);

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('objectif_calcul_auto', false)
            ->assertJsonPath('cibles_effectives.source', 'utilisateur')
            ->assertJsonPath('cibles_effectives.calories', 2000);
    }

    public function test_get_profile_never_leaks_another_users_profile(): void
    {
        $other = User::factory()->create();
        Profile::factory()->for($other)->create(['poids' => 99]);

        $this->actingAsUser();

        $this->getJson('/api/profile')->assertOk()->assertJsonPath('poids', null)->assertJsonPath('has_profile', false);
    }
}
