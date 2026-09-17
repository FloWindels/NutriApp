<?php

namespace Tests\Feature\Profile;

use App\Models\Profile;
use App\Services\NutritionCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePreviewTest extends TestCase
{
    use ProfileTestHelpers;
    use RefreshDatabase;

    public function test_preview_returns_besoins_without_persisting_or_requiring_consent(): void
    {
        $user = $this->actingAsUser();

        $body = $this->vecteurHomme70();
        unset($body['consentement_sante'], $body['nom']);

        $response = $this->postJson('/api/profile/preview', $body)->assertOk();

        $this->assertSame(['besoins', 'cibles_effectives', 'imc', 'imc_cible'], array_keys($response->json('data')));
        $response->assertJsonPath('data.besoins.calories_recommandees', 2130)
            ->assertJsonPath('data.besoins.proteines_g', 126)
            ->assertJsonPath('data.besoins.lipides_g', 56)
            ->assertJsonPath('data.besoins.glucides_g', 280)
            ->assertJsonPath('data.cibles_effectives', ['calories' => 2130, 'proteines' => 126, 'glucides' => 280, 'lipides' => 56, 'source' => 'calcul'])
            ->assertJsonPath('data.imc', 22.9)
            ->assertJsonPath('data.imc_cible', 21.2);

        $this->assertDatabaseMissing('profiles', ['user_id' => $user->id]);
        $this->assertNull($user->fresh()->consentement_sante_at);
    }

    public function test_preview_applies_coherence_rules(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/profile/preview', $this->vecteurHomme70(['poids_souhaite_kg' => 80]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['poids_souhaite_kg' => NutritionCalculator::MSG_PERTE_INCOHERENTE]);

        $this->postJson('/api/profile/preview', $this->vecteurHomme70(['age' => 16]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['objectif_type' => NutritionCalculator::MSG_MINEUR_OBJECTIF]);
    }

    public function test_preview_validates_override_envelope_and_reports_manual_source(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/profile/preview', $this->vecteurHomme70(['calories_cibles' => 1000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['calories_cibles']);

        $this->postJson('/api/profile/preview', $this->vecteurHomme70([
            'calories_cibles' => 2000, 'proteines_cibles' => 130, 'glucides_cibles' => 230, 'lipides_cibles' => 60,
        ]))
            ->assertOk()
            ->assertJsonPath('data.cibles_effectives', ['calories' => 2000, 'proteines' => 130, 'glucides' => 230, 'lipides' => 60, 'source' => 'utilisateur'])
            ->assertJsonPath('data.besoins.calories_recommandees', 2130);
    }

    public function test_preview_requires_the_legacy_fields(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/profile/preview', ['poids' => 70])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sexe', 'age', 'taille', 'niveau_activite', 'objectif_type', 'regime_alimentaire'])
            ->assertJsonMissingValidationErrors(['nom']);
    }

    public function test_preview_does_not_modify_an_existing_profile(): void
    {
        $user = $this->actingAsUser();
        Profile::factory()->for($user)->create(['poids' => 80, 'calories_cibles' => 2500]);

        $this->postJson('/api/profile/preview', $this->vecteurHomme70())->assertOk();

        $this->assertDatabaseHas('profiles', ['user_id' => $user->id, 'poids' => 80, 'calories_cibles' => 2500]);
    }

    public function test_preview_warns_when_delay_is_too_short(): void
    {
        $this->actingAsUser();

        $json = $this->postJson('/api/profile/preview', $this->vecteurHomme70([
            'poids' => 130, 'taille' => 165, 'poids_souhaite_kg' => 100, 'delai_objectif_jours' => 60,
        ]))->assertOk()->json('data');

        $this->assertNotEmpty(array_filter($json['besoins']['avertissements'], fn ($a) => str_starts_with($a, 'Délai trop court')));
        // poids_ref = min(130, 30 × 1,65²) = 81,7 → P = 1,8 × 81,7 ≈ 147 g
        $this->assertEqualsWithDelta(147, $json['besoins']['proteines_g'], 3);
    }
}
