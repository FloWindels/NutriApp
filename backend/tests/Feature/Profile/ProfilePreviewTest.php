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

    /**
     * Contrat dont dépend la page profil du site : `besoins` porte toujours le calcul du
     * serveur, même quand le profil enregistré est passé en cibles manuelles, et
     * `objectif_calcul_auto: true` rend la main au calcul dans `cibles_effectives`.
     */
    public function test_preview_ignores_stored_manual_targets_when_auto_is_requested(): void
    {
        $user = $this->actingAsUser();
        Profile::factory()->for($user)->create([
            'sexe' => 'homme', 'age' => 30, 'taille' => 175, 'poids' => 70,
            'poids_souhaite_kg' => 65, 'delai_objectif_jours' => 90,
            'niveau_activite' => 'modere', 'objectif_type' => 'perdre',
            'regime_alimentaire' => 'omnivore',
            'objectif_calcul_auto' => false,
            'calories_cibles' => 1700, 'proteines_cibles' => 100,
            'glucides_cibles' => 200, 'lipides_cibles' => 50,
        ]);

        $body = $this->vecteurHomme70();
        unset($body['consentement_sante']);

        $manuel = $this->postJson('/api/profile/preview', $body)->assertOk()->json('data');
        $this->assertSame(2130, $manuel['besoins']['calories_recommandees'], 'Les besoins restent le calcul serveur.');
        $this->assertSame(1700, $manuel['cibles_effectives']['calories'], 'Sans demande explicite, les cibles manuelles priment.');
        $this->assertSame('utilisateur', $manuel['cibles_effectives']['source']);

        $auto = $this->postJson('/api/profile/preview', $body + ['objectif_calcul_auto' => true])->assertOk()->json('data');
        $this->assertSame(2130, $auto['cibles_effectives']['calories']);
        $this->assertSame('calcul', $auto['cibles_effectives']['source']);

        $this->assertFalse($user->profile()->first()->objectif_calcul_auto, 'La prévisualisation n’écrit rien.');
    }

    /** Le plancher de sécurité est exposé tel quel : 1 500 kcal pour un homme, 1 200 pour une femme. */
    public function test_preview_exposes_the_safety_floor(): void
    {
        $this->actingAsUser();

        $homme = $this->postJson('/api/profile/preview', $this->vecteurHomme70([
            'age' => 70, 'taille' => 158, 'poids' => 52, 'poids_souhaite_kg' => 48,
            'delai_objectif_jours' => 120, 'niveau_activite' => 'sedentaire',
        ]))->assertOk()->json('data');

        $this->assertSame(1500, $homme['besoins']['plancher_kcal']);
        $this->assertGreaterThanOrEqual(1500, $homme['besoins']['calories_recommandees']);

        $femme = $this->postJson('/api/profile/preview', $this->vecteurHomme70([
            'sexe' => 'femme', 'age' => 70, 'taille' => 150, 'poids' => 45, 'poids_souhaite_kg' => 42,
            'delai_objectif_jours' => 120, 'niveau_activite' => 'sedentaire',
        ]))->assertOk()->json('data');

        $this->assertSame(1200, $femme['besoins']['plancher_kcal']);
        $this->assertGreaterThanOrEqual(1200, $femme['besoins']['calories_recommandees']);
    }
}
