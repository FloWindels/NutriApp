<?php

namespace Tests\Feature\Profile;

use App\Services\NutritionCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Consentement à un rythme de perte plus rapide, de bout en bout : ce que la prévisualisation
 * propose, ce que la sauvegarde conserve, et ce qui invalide un accord déjà donné.
 *
 * Vecteur : homme 70 kg / 175 cm / 30 ans / modéré. Conseillé 770 kcal/j, plancher 1 649 kcal.
 */
class ProfileRythmeIntenseTest extends TestCase
{
    use ProfileTestHelpers;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function presse(array $overrides = []): array
    {
        return $this->vecteurHomme70(array_replace([
            'poids_souhaite_kg' => 60,
            'delai_objectif_jours' => 60,
        ], $overrides));
    }

    /**
     * Un accord tel qu'un client honnete l'envoie : la case, ET les deux valeurs affichees a
     * cote. Sans elles, l'accord n'est pas recevable — c'est tout l'objet du garde-fou.
     *
     * @return array<string, mixed>
     */
    private function accord(int $deficitVu): array
    {
        return [
            'rythme_intense' => true,
            'rythme_intense_deficit_vu' => $deficitVu,
            'rythme_intense_version_vue' => NutritionCalculator::AVERTISSEMENT_RYTHME_VERSION,
        ];
    }

    public function test_la_previsualisation_propose_le_rythme_intense_sans_l_appliquer(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/profile/preview', $this->presse())
            ->assertOk()
            ->assertJsonPath('data.besoins.rythme_intense_possible', true)
            ->assertJsonPath('data.besoins.rythme_intense_accepte', false)
            ->assertJsonPath('data.besoins.rythme_conseille_kcal', 770)
            ->assertJsonPath('data.besoins.rythme_demande_kcal', 1283)
            ->assertJsonPath('data.besoins.rythme_intense_kcal', 907)
            ->assertJsonPath('data.besoins.calories_recommandees', 1790);

        $this->postJson('/api/profile/preview', $this->presse($this->accord(907)))
            ->assertOk()
            ->assertJsonPath('data.besoins.rythme_intense_accepte', true)
            ->assertJsonPath('data.besoins.calories_recommandees', 1650);
    }

    public function test_le_consentement_est_trace_a_l_enregistrement(): void
    {
        $user = $this->actingAsUser();

        $this->putJson('/api/profile', $this->presse($this->accord(907)))
            ->assertOk()
            ->assertJsonPath('calories_cibles', 1650)
            ->assertJsonPath('besoins.rythme_intense_accepte', true);

        $profile = $user->fresh()->profile;

        $this->assertTrue((bool) $profile->rythme_intense);
        $this->assertSame(907, $profile->rythme_intense_deficit_kcal);
        $this->assertSame(0.82, (float) $profile->rythme_intense_kg_semaine);
        $this->assertSame(
            NutritionCalculator::AVERTISSEMENT_RYTHME_VERSION,
            $profile->rythme_intense_avertissement_version
        );
        $this->assertNotNull($profile->rythme_intense_consenti_le);
    }

    public function test_retirer_le_consentement_redonne_le_rythme_conseille(): void
    {
        $user = $this->actingAsUser();

        $this->putJson('/api/profile', $this->presse($this->accord(907)))->assertOk();
        $this->putJson('/api/profile', $this->presse(['rythme_intense' => false]))
            ->assertOk()
            ->assertJsonPath('calories_cibles', 1790)
            ->assertJsonPath('besoins.rythme_intense_accepte', false);

        $profile = $user->fresh()->profile;

        $this->assertFalse((bool) $profile->rythme_intense);
        $this->assertNull($profile->rythme_intense_consenti_le);
        $this->assertNull($profile->rythme_intense_avertissement_version);
        $this->assertNull($profile->rythme_intense_deficit_kcal);
        $this->assertNull($profile->rythme_intense_kg_semaine);

        // L'etat s'efface, la preuve non : l'accord donne reste consigne, date de fin a l'appui.
        $trace = \App\Models\ConsentementRythme::query()->where('user_id', $user->id)->sole();
        $this->assertSame(907, $trace->deficit_kcal);
        $this->assertNotNull($trace->donne_le);
        $this->assertNotNull($trace->retire_le, 'Retirer son accord doit dater la fin, pas effacer la ligne.');
    }

    /**
     * Le defaut que trois relecteurs ont demontre : le formulaire web renvoie la case a chaque
     * enregistrement. Si la seule presence de la case valait accord, changer son poids puis
     * enregistrer appliquerait un deficit jamais affiche, et la trace l'attesterait comme accepte.
     */
    public function test_une_case_cochee_sans_le_chiffre_presente_ne_vaut_pas_accord(): void
    {
        $user = $this->actingAsUser();

        $this->putJson('/api/profile', $this->presse(['rythme_intense' => true]))
            ->assertOk()
            ->assertJsonPath('calories_cibles', 1790)
            ->assertJsonPath('besoins.rythme_intense_accepte', false);

        $this->assertFalse((bool) $user->fresh()->profile->rythme_intense);
        $this->assertSame(0, \App\Models\ConsentementRythme::query()->count());
    }

    /** Un accord donne pour 802 kcal/j ne s'etend pas a 907, meme renvoye tel quel. */
    public function test_un_accord_ne_couvre_pas_un_deficit_plus_grand_que_celui_presente(): void
    {
        $user = $this->actingAsUser();

        $this->putJson('/api/profile', $this->presse([
            'poids_souhaite_kg' => 65,
            'delai_objectif_jours' => 48,
        ] + $this->accord(802)))->assertOk()->assertJsonPath('calories_cibles', 1750);

        // Le poids change, l'ecran n'a pas ete recalcule : le client renvoie l'ancien chiffre.
        $this->putJson('/api/profile', $this->presse([
            'poids_souhaite_kg' => 60,
            'delai_objectif_jours' => 60,
        ] + $this->accord(802)))
            ->assertOk()
            ->assertJsonPath('calories_cibles', 1790)
            ->assertJsonPath('besoins.rythme_intense_accepte', false);

        $this->assertFalse((bool) $user->fresh()->profile->rythme_intense);
    }

    /** Le texte des risques a ete reecrit : il faut le relire avant que l'accord reprenne effet. */
    public function test_une_version_d_avertissement_perimee_est_refusee(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/profile', $this->presse([
            'rythme_intense' => true,
            'rythme_intense_deficit_vu' => 907,
            'rythme_intense_version_vue' => 'v0-perimee',
        ]))
            ->assertOk()
            ->assertJsonPath('calories_cibles', 1790)
            ->assertJsonPath('besoins.rythme_intense_accepte', false);
    }

    public function test_un_objectif_plus_rapide_annule_le_consentement_precedent(): void
    {
        $user = $this->actingAsUser();

        // 5 kg en 48 jours : 802 kcal/j, au-delà du conseillé mais sous le plancher.
        $this->putJson('/api/profile', $this->presse([
            'poids_souhaite_kg' => 65,
            'delai_objectif_jours' => 48,
        ] + $this->accord(802)))->assertOk()->assertJsonPath('calories_cibles', 1750);

        $this->assertSame(802, $user->fresh()->profile->rythme_intense_deficit_kcal);

        // Un client qui ignore la case (l'application mobile) raccourcit le délai : l'accord
        // donné pour 802 kcal/j ne couvre pas 907, on retombe au rythme conseillé.
        $this->putJson('/api/profile', ['delai_objectif_jours' => 40])
            ->assertOk()
            ->assertJsonPath('calories_cibles', 1790)
            ->assertJsonPath('besoins.rythme_intense_possible', true)
            ->assertJsonPath('besoins.rythme_intense_accepte', false);

        $this->assertFalse((bool) $user->fresh()->profile->rythme_intense);

        // Redemandé explicitement, il s'applique de nouveau.
        $this->putJson('/api/profile', $this->accord(907))
            ->assertOk()
            ->assertJsonPath('calories_cibles', 1650);

        $this->assertSame(907, $user->fresh()->profile->rythme_intense_deficit_kcal);
    }

    public function test_un_consentement_encore_valable_se_reconduit_sans_etre_renvoye(): void
    {
        $user = $this->actingAsUser();

        $this->putJson('/api/profile', $this->presse($this->accord(907)))->assertOk();
        $consentiLe = $user->fresh()->profile->rythme_intense_consenti_le;

        $this->putJson('/api/profile', ['niveau_activite' => 'modere'])
            ->assertOk()
            ->assertJsonPath('besoins.rythme_intense_accepte', true)
            ->assertJsonPath('calories_cibles', 1650);

        $this->assertEquals($consentiLe, $user->fresh()->profile->rythme_intense_consenti_le);
    }

    public function test_une_pesee_ne_creuse_pas_le_deficit_au_dela_du_consentement(): void
    {
        $user = $this->actingAsUser();

        $this->putJson('/api/profile', $this->presse([
            'poids_souhaite_kg' => 65,
            'delai_objectif_jours' => 48,
            'rythme_intense' => true,
        ]))->assertOk();

        // Reprendre du poids élargirait les plafonds (1,5 % d'un poids plus lourd) : l'accord
        // donné pour 802 kcal/j ne suit pas, on revient au rythme conseillé.
        $this->postJson('/api/weights', ['weight_kg' => 76])->assertSuccessful();

        $profile = $user->fresh()->profile;

        $this->assertFalse((bool) $profile->rythme_intense);
        $this->assertNull($profile->rythme_intense_deficit_kcal);
        $this->assertSame(1810, $profile->calories_cibles);
    }

    public function test_une_situation_particuliere_ne_peut_pas_consentir(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/profile', $this->presse([
            'situation_particuliere' => 'grossesse',
            'rythme_intense' => true,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['objectif_type' => NutritionCalculator::MSG_SITUATION_OBJECTIF]);
    }

    public function test_un_mineur_ne_peut_pas_consentir(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/profile', $this->presse(['age' => 16, 'rythme_intense' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['objectif_type' => NutritionCalculator::MSG_MINEUR_OBJECTIF]);
    }
}
