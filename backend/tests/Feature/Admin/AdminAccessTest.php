<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Food;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * L'espace d'administration doit être invisible, et son pouvoir borné.
 *
 * Ces tests valent garde-fou : ils échouent si quelqu'un ouvre par mégarde une porte, ou si un
 * contrôleur d'administration se met à lire des données de santé.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Administrateur])->save();
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    /** @return list<array{0: string, 1: string}> */
    public static function routesAdmin(): array
    {
        return [
            ['get', '/api/admin/stats'],
            ['get', '/api/admin/users'],
            ['get', '/api/admin/moderation/foods'],
            ['get', '/api/admin/moderation/recipes'],
            ['get', '/api/admin/journal'],
        ];
    }

    /**
     * @dataProvider routesAdmin
     */
    public function test_un_visiteur_anonyme_recoit_404_et_non_401(string $verbe, string $route): void
    {
        // 401 confirmerait l'existence du couloir ; 404 le rend indistinguable d'une URL inconnue.
        $this->{$verbe.'Json'}($route)
            ->assertNotFound()
            ->assertJsonPath('message', 'Introuvable.');
    }

    /**
     * @dataProvider routesAdmin
     */
    public function test_un_utilisateur_ordinaire_recoit_404(string $verbe, string $route): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->{$verbe.'Json'}($route)->assertNotFound();
    }

    public function test_un_administrateur_accede_aux_statistiques(): void
    {
        $this->admin();

        $data = $this->getJson('/api/admin/stats')->assertOk()->json('data');

        $this->assertSame(
            ['comptes', 'activite', 'usage', 'contenus', 'ia'],
            array_keys($data),
        );
        $this->assertGreaterThanOrEqual(1, $data['comptes']['total']);
    }

    public function test_les_statistiques_ne_contiennent_aucune_donnee_nominative(): void
    {
        $this->admin();
        $curieux = User::factory()->create(['name' => 'Camille Secret', 'email' => 'camille@example.com']);

        $reponse = $this->getJson('/api/admin/stats')->assertOk()->getContent();

        $this->assertStringNotContainsString('Camille Secret', $reponse);
        $this->assertStringNotContainsString($curieux->email, $reponse);
    }

    public function test_la_liste_des_comptes_n_expose_pas_les_donnees_de_sante(): void
    {
        $this->admin();
        $cible = User::factory()->create(['name' => 'Alex']);
        $cible->profile()->create([
            'nom' => 'Alex', 'sexe' => 'homme', 'age' => 30, 'taille' => 180, 'poids' => 82,
            'niveau_activite' => 'modere', 'objectif_type' => 'maintenir', 'regime_alimentaire' => 'omnivore',
        ]);

        $ligne = collect($this->getJson('/api/admin/users')->assertOk()->json('data'))
            ->firstWhere('id', $cible->id);

        // L'enveloppe, oui : nom, adresse, dates, état. Le contenu, non.
        $this->assertSame('Alex', $ligne['name']);
        $this->assertArrayNotHasKey('poids', $ligne);
        $this->assertArrayNotHasKey('profile', $ligne);
        $this->assertArrayNotHasKey('meals', $ligne);
        $this->assertStringNotContainsString('"poids"', $this->getJson('/api/admin/users')->getContent());
    }

    public function test_le_controleur_des_comptes_ne_touche_a_aucun_contenu(): void
    {
        // Garde-fou structurel : la règle est dans le code, pas dans une consigne.
        $source = file_get_contents(base_path('app/Http/Controllers/Api/Admin/AdminUserController.php'));

        foreach (['Meal', 'MealItem', 'Profile', 'WeightLog', 'WorkoutSession', 'Stock', 'ShoppingItem'] as $modele) {
            $this->assertStringNotContainsString(
                'App\\Models\\'.$modele,
                $source,
                "AdminUserController ne doit jamais importer le modèle {$modele}.",
            );
        }
    }

    public function test_suspendre_un_compte_revoque_ses_jetons_et_bloque_la_connexion(): void
    {
        $admin = $this->admin();
        $cible = User::factory()->create(['email' => 'gene@example.com', 'password' => bcrypt('secret123')]);
        $cible->createToken('auth_token');

        $this->postJson("/api/admin/users/{$cible->id}/suspend", ['motif' => 'Contenus répétés hors sujet'])
            ->assertOk();

        $this->assertNotNull($cible->fresh()->suspendu_le);
        $this->assertSame(0, $cible->tokens()->count(), 'La suspension doit produire son effet tout de suite.');

        $this->postJson('/api/login', ['email' => 'gene@example.com', 'password' => 'secret123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('admin_actions', [
            'admin_email' => $admin->email,
            'action' => 'suspendre_compte',
            'cible_id' => $cible->id,
            'motif' => 'Contenus répétés hors sujet',
        ]);
    }

    public function test_une_suspension_exige_un_motif(): void
    {
        $this->admin();
        $cible = User::factory()->create();

        $this->postJson("/api/admin/users/{$cible->id}/suspend", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motif');
    }

    public function test_un_administrateur_ne_peut_pas_se_suspendre_lui_meme(): void
    {
        $admin = $this->admin();

        $this->postJson("/api/admin/users/{$admin->id}/suspend", ['motif' => 'essai'])
            ->assertStatus(422);

        $this->assertNull($admin->fresh()->suspendu_le);
    }

    public function test_un_aliment_masque_disparait_de_tous_les_chemins_de_lecture(): void
    {
        $this->admin();
        $food = Food::factory()->create([
            'name' => 'Aliment fantaisiste',
            'barcode' => '3017620422003',
            'created_by_user_id' => User::factory()->create()->id,
        ]);

        $this->postJson("/api/admin/moderation/foods/{$food->id}/hide", ['motif' => 'Nom injurieux'])
            ->assertOk();

        // Open Food Facts est simulé « produit inconnu » : sans cela la route code-barres
        // partirait sur le réseau, ce que les tests interdisent, et rendrait 502 au lieu de 404.
        Http::fake([config('services.off.base_url').'/*' => Http::response(['status' => 0], 200)]);

        // Les deux routes publiques, interrogées sans aucun jeton.
        $this->app['auth']->forgetGuards();
        $recherche = $this->getJson('/api/foods/search?q=fantaisiste')->assertOk()->json('data');
        $this->assertSame([], $recherche, 'Un aliment masqué ne doit plus ressortir de la recherche.');

        $this->getJson('/api/foods/barcode/3017620422003')->assertNotFound();

        $this->assertDatabaseHas('admin_actions', ['action' => 'masquer_aliment', 'cible_id' => $food->id]);
    }

    public function test_une_recette_masquee_disparait_puis_revient(): void
    {
        $this->admin();
        $auteur = User::factory()->create();
        $recette = Recipe::factory()->create(['created_by_user_id' => $auteur->id] + ['title' => 'Recette douteuse', 'is_public' => true]);

        $this->postJson("/api/admin/moderation/recipes/{$recette->id}/hide", ['motif' => 'Hors sujet'])
            ->assertOk();

        Sanctum::actingAs($auteur, ['*']);
        $this->getJson("/api/recipes/{$recette->id}")->assertNotFound();

        $this->admin();
        $this->postJson("/api/admin/moderation/recipes/{$recette->id}/show", ['motif' => 'Signalement infondé'])
            ->assertOk();

        Sanctum::actingAs($auteur, ['*']);
        $this->getJson("/api/recipes/{$recette->id}")->assertOk();
    }
}
