<?php

namespace Tests\Feature\Account;

use App\Models\Meal;
use App\Models\MealItem;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\WeightLog;
use App\Models\WorkoutSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_account_changes_name_and_normalised_email(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/account', ['name' => '  Léa Martin ', 'email' => ' LEA.M@Example.com ']);

        $response->assertOk()->assertExactJson([
            'message' => 'Compte mis à jour.',
            'data' => ['id' => $user->id, 'name' => 'Léa Martin', 'email' => 'lea.m@example.com'],
        ]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'lea.m@example.com', 'name' => 'Léa Martin']);
    }

    public function test_update_account_rejects_email_taken_by_someone_else_but_accepts_own(): void
    {
        User::factory()->create(['email' => 'pris@example.com']);
        $user = User::factory()->create(['email' => 'moi@example.com']);
        Sanctum::actingAs($user);

        $this->putJson('/api/account', ['name' => 'Moi', 'email' => 'PRIS@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->putJson('/api/account', ['name' => 'Moi', 'email' => 'moi@example.com'])->assertOk();
    }

    public function test_update_password_requires_current_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('ancien-mdp')]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/account/password', [
            'current_password' => 'mauvais',
            'password' => 'nouveau-mdp-1',
            'password_confirmation' => 'nouveau-mdp-1',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->assertSame('Le mot de passe actuel est incorrect.', $response->json('errors.current_password.0'));
        $this->assertTrue(Hash::check('ancien-mdp', $user->refresh()->password));
    }

    public function test_update_password_validation_min_8_and_confirmed(): void
    {
        $user = User::factory()->create(['password' => bcrypt('ancien-mdp')]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/account/password', [
            'current_password' => 'ancien-mdp',
            'password' => 'court',
            'password_confirmation' => 'autre',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertStringContainsString('8', implode(' ', $response->json('errors.password')));
    }

    public function test_update_password_revokes_other_tokens_but_keeps_current(): void
    {
        $user = User::factory()->create(['password' => bcrypt('ancien-mdp')]);
        $current = $user->createToken('web')->plainTextToken;
        $other = $user->createToken('mobile')->plainTextToken;

        $this->withToken($current)->putJson('/api/account/password', [
            'current_password' => 'ancien-mdp',
            'password' => 'nouveau-mdp-1',
            'password_confirmation' => 'nouveau-mdp-1',
        ])->assertOk()->assertJsonStructure(['message']);

        $this->assertTrue(Hash::check('nouveau-mdp-1', $user->refresh()->password));
        $this->assertSame(1, $user->tokens()->count());

        // Le garde met en cache l'utilisateur résolu entre deux requêtes de test : on le réinitialise.
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/me')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/me')->assertOk();
    }

    public function test_export_returns_all_sections_with_casts(): void
    {
        $user = User::factory()->create();
        Profile::factory()->for($user)->create(['poids' => 72.5]);
        UserSetting::factory()->for($user)->create();
        $meal = Meal::factory()->for($user)->create();
        MealItem::factory()->for($meal)->create(['quantity' => 150]);
        WeightLog::factory()->for($user)->create(['weight_kg' => 72.5, 'date' => '2026-09-10']);
        $stock = Stock::factory()->for($user)->create(['name' => 'Frigo']);
        StockItem::factory()->for($stock)->create(['quantity' => 2]);
        Recipe::factory()->create(['created_by_user_id' => $user->id]);
        Recipe::factory()->create(); // recette d'un autre : exclue
        WorkoutSession::factory()->for($user)->create();

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/account/export');

        $response->assertOk();
        $data = $response->json('data');
        foreach (['user', 'profile', 'settings', 'meals', 'weights', 'stocks', 'recipes', 'shopping', 'plans', 'sessions', 'recommendations', 'exported_at'] as $key) {
            $this->assertArrayHasKey($key, $data, "clé manquante : $key");
        }

        $this->assertSame($user->email, $data['user']['email']);
        $this->assertArrayNotHasKey('password', $data['user']);
        $this->assertSame(72.5, $data['profile']['poids']);
        $this->assertCount(1, $data['meals']);
        $this->assertCount(1, $data['meals'][0]['items']);
        $this->assertEquals(150.0, $data['meals'][0]['items'][0]['quantity']);
        $this->assertSame('2026-09-10', $data['weights'][0]['date']);
        $this->assertSame(72.5, $data['weights'][0]['weight_kg']);
        $this->assertCount(1, $data['stocks']);
        $this->assertEquals(2.0, $data['stocks'][0]['items'][0]['quantity']);
        $this->assertCount(1, $data['recipes']);
        $this->assertCount(1, $data['sessions']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $data['exported_at']);
    }

    public function test_account_routes_require_authentication(): void
    {
        $this->putJson('/api/account', ['name' => 'x', 'email' => 'x@example.com'])->assertStatus(401);
        $this->getJson('/api/account/export')->assertStatus(401);
        $this->deleteJson('/api/account', ['password' => 'x'])->assertStatus(401);
        $this->postJson('/api/logout')->assertStatus(401);
    }
}
