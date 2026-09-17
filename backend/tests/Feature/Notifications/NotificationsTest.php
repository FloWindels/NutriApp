<?php

namespace Tests\Feature\Notifications;

use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\Recommendation;
use App\Models\SportPlan;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\WorkoutSession;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        UserSetting::factory()->for($this->user)->create();
        $this->today = Clock::today($this->user);
        Sanctum::actingAs($this->user);
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::parse($this->today)->addDays($offset)->toDateString();
    }

    private function stockItem(array $attributes): StockItem
    {
        $stock = Stock::query()->firstOrCreate(['user_id' => $this->user->id, 'household_id' => null, 'name' => 'Frigo']);

        return StockItem::factory()->for($stock)->create($attributes);
    }

    public function test_empty_when_nothing_to_report(): void
    {
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertExactJson(['data' => [], 'unread_count' => 0]);
    }

    public function test_expiring_items_follow_jours_alerte_peremption(): void
    {
        $this->user->settings()->update(['jours_alerte_peremption' => 3]);
        $this->stockItem(['food_name' => 'Yaourt', 'expires_at' => $this->day(0)]);
        $this->stockItem(['food_name' => 'Lait', 'expires_at' => $this->day(1)]);
        $this->stockItem(['food_name' => 'Jambon', 'expires_at' => $this->day(3)]);
        $this->stockItem(['food_name' => 'Riz', 'expires_at' => $this->day(4)]); // hors fenêtre
        $this->stockItem(['food_name' => 'Pâtes', 'expires_at' => null]);        // jamais
        $this->stockItem(['food_name' => 'Épuisé', 'expires_at' => $this->day(1), 'quantity' => 0]); // épuisé

        $response = $this->getJson('/api/notifications')->assertOk();

        $data = $response->json('data');
        $this->assertCount(3, $data);
        $this->assertSame(3, $response->json('unread_count'));
        $this->assertSame(['stock_peremption', 'stock_peremption', 'stock_peremption'], array_column($data, 'type'));
        $this->assertStringContainsString('expire aujourd’hui', $data[0]['message']);
        $this->assertStringContainsString('expire demain', $data[1]['message']);
        $this->assertStringContainsString('expire dans 3 jours', $data[2]['message']);
        $this->assertSame(['key', 'type', 'title', 'message', 'date', 'read', 'action'], array_keys($data[0]));
        $this->assertSame($this->day(0), $data[0]['date']);
        $this->assertFalse($data[0]['read']);
        $this->assertSame('ouvrir_stock', $data[0]['action']['kind']);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,64}$/', $data[0]['key']);
    }

    public function test_notif_peremption_off_hides_expiring_but_not_expired(): void
    {
        $this->user->settings()->update(['notif_peremption' => false]);
        $this->stockItem(['food_name' => 'Lait', 'expires_at' => $this->day(1)]);
        $expired = $this->stockItem(['food_name' => 'Poulet', 'expires_at' => $this->day(-2)]);

        $response = $this->getJson('/api/notifications')->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('stock_perime', $data[0]['type']);
        $this->assertStringContainsString('Poulet', $data[0]['message']);
        $this->assertStringContainsString('périmé depuis le', $data[0]['message']);
        $this->assertSame([$expired->id], $data[0]['action']['stock_item_ids']);
    }

    public function test_ddm_expired_has_softer_wording_and_expires_after_30_days(): void
    {
        $this->stockItem(['food_name' => 'Lentilles', 'expires_at' => $this->day(-5), 'expiry_kind' => 'ddm']);
        $this->stockItem(['food_name' => 'Vieux riz', 'expires_at' => $this->day(-45), 'expiry_kind' => 'ddm']);

        $data = $this->getJson('/api/notifications')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('DDM dépassée', $data[0]['title']);
        $this->assertStringContainsString('vérifie l’aspect et l’odeur', $data[0]['message']);
    }

    public function test_meal_reminder_only_when_enabled_and_not_logged(): void
    {
        MealPlan::factory()->for($this->user)->create(['date' => $this->today, 'meal_type' => 'dejeuner', 'title' => 'Poulet rôti', 'status' => 'prevu']);
        MealPlan::factory()->for($this->user)->create(['date' => $this->today, 'meal_type' => 'diner', 'title' => 'Soupe', 'status' => 'prevu']);
        MealPlan::factory()->for($this->user)->create(['date' => $this->day(1), 'meal_type' => 'diner', 'title' => 'Demain', 'status' => 'prevu']);
        // Le dîner est déjà enregistré aujourd'hui.
        $diner = Meal::factory()->for($this->user)->create(['date' => $this->today, 'type' => 'diner']);
        MealItem::factory()->for($diner)->create();

        // Désactivé par défaut → rien.
        $this->assertSame([], $this->getJson('/api/notifications')->json('data'));

        $this->user->settings()->update(['notif_rappel_repas' => true]);
        $data = $this->getJson('/api/notifications')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('rappel_repas', $data[0]['type']);
        $this->assertStringContainsString('Déjeuner', $data[0]['message']);
        $this->assertStringContainsString('Poulet rôti', $data[0]['message']);
        $this->assertSame('ouvrir_planificateur', $data[0]['action']['kind']);
        $this->assertSame($this->today, $data[0]['action']['date']);
    }

    public function test_sport_reminder_only_when_enabled(): void
    {
        WorkoutSession::factory()->for($this->user)->create(['date' => $this->today, 'status' => 'prevue', 'title' => 'Séance jambes', 'planned_at' => '18:30:00', 'duration_min' => 30]);
        WorkoutSession::factory()->for($this->user)->completed()->create(['date' => $this->today]); // terminée → rien
        SportPlan::factory()->for($this->user)->create(['date' => $this->today, 'sport_id' => null, 'sport_name' => 'Course à pied', 'planned_duration_min' => 45, 'planned_at' => null, 'status' => 'prevu']);
        SportPlan::factory()->for($this->user)->create(['date' => $this->day(2), 'sport_id' => null, 'sport_name' => 'Vélo', 'status' => 'prevu']);

        $this->assertSame([], $this->getJson('/api/notifications')->json('data'));

        $this->user->settings()->update(['notif_rappel_sport' => true]);
        $data = $this->getJson('/api/notifications')->assertOk()->json('data');

        $this->assertCount(2, $data);
        $this->assertSame(['rappel_sport', 'rappel_sport'], array_column($data, 'type'));
        $this->assertStringContainsString('Séance jambes (30 min) à 18:30', $data[0]['message']);
        $this->assertSame('ouvrir_seance', $data[0]['action']['kind']);
        $this->assertStringContainsString('Course à pied · 45 min aujourd’hui', $data[1]['message']);
        $this->assertSame('ouvrir_calendrier', $data[1]['action']['kind']);
    }

    public function test_only_priority_one_recommendations_not_ignored_are_included(): void
    {
        Recommendation::factory()->for($this->user)->create(['date' => $this->today, 'priority' => 1, 'title' => 'Produit périmé', 'message' => 'Vérifie le poulet.', 'actions' => [['kind' => 'supprimer_stock', 'stock_item_id' => 12]]]);
        Recommendation::factory()->for($this->user)->create(['date' => $this->today, 'priority' => 2, 'title' => 'Budget restant']);
        Recommendation::factory()->for($this->user)->create(['date' => $this->today, 'priority' => 1, 'title' => 'Ignorée', 'status' => 'ignoree']);
        Recommendation::factory()->for($this->user)->create(['date' => $this->day(-1), 'priority' => 1, 'title' => 'Hier']);

        $data = $this->getJson('/api/notifications')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('recommandation', $data[0]['type']);
        $this->assertSame('Produit périmé', $data[0]['title']);
        $this->assertSame('Vérifie le poulet.', $data[0]['message']);
        $this->assertSame(['kind' => 'supprimer_stock', 'stock_item_id' => 12], $data[0]['action']);
    }

    public function test_keys_are_stable_and_read_state_persists(): void
    {
        $this->stockItem(['food_name' => 'Lait', 'expires_at' => $this->day(1)]);
        $this->stockItem(['food_name' => 'Poulet', 'expires_at' => $this->day(-1)]);

        $first = $this->getJson('/api/notifications')->json('data');
        $second = $this->getJson('/api/notifications')->json('data');
        $this->assertSame(array_column($first, 'key'), array_column($second, 'key'));

        $key = $first[0]['key'];
        $this->putJson("/api/notifications/{$key}/read")
            ->assertOk()
            ->assertExactJson(['message' => 'Notification marquée comme lue.', 'data' => ['key' => $key, 'read' => true]]);

        // Idempotent.
        $this->putJson("/api/notifications/{$key}/read")->assertOk();
        $this->assertDatabaseCount('notification_reads', 1);

        $response = $this->getJson('/api/notifications')->assertOk();
        $this->assertTrue($response->json('data.0.read'));
        $this->assertFalse($response->json('data.1.read'));
        $this->assertSame(1, $response->json('unread_count'));
    }

    public function test_read_all_marks_every_current_notification(): void
    {
        $this->stockItem(['food_name' => 'Lait', 'expires_at' => $this->day(1)]);
        $this->stockItem(['food_name' => 'Poulet', 'expires_at' => $this->day(-1)]);
        Recommendation::factory()->for($this->user)->create(['date' => $this->today, 'priority' => 1]);

        $this->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('message', 'Toutes les notifications sont marquées comme lues.')
            ->assertJsonPath('data.marked_count', 3)
            ->assertJsonPath('data.unread_count', 0);

        $response = $this->getJson('/api/notifications')->assertOk();
        $this->assertSame(0, $response->json('unread_count'));
        $this->assertSame([true, true, true], array_column($response->json('data'), 'read'));
    }

    public function test_read_key_is_constrained(): void
    {
        $this->putJson('/api/notifications/Clé%20Invalide/read')->assertStatus(404);
        $this->putJson('/api/notifications/'.str_repeat('a', 65).'/read')->assertStatus(404);
    }

    public function test_notifications_and_reads_are_isolated_per_user(): void
    {
        $other = User::factory()->create();
        UserSetting::factory()->for($other)->create();
        $otherStock = Stock::factory()->for($other)->create(['name' => 'Frigo']);
        StockItem::factory()->for($otherStock)->create(['food_name' => 'Secret', 'expires_at' => $this->day(-1)]);

        $mine = $this->stockItem(['food_name' => 'Lait', 'expires_at' => $this->day(1)]);

        $data = $this->getJson('/api/notifications')->assertOk()->json('data');
        $this->assertCount(1, $data);
        $this->assertStringContainsString('Lait', $data[0]['message']);
        $this->assertStringNotContainsString('Secret', json_encode($data));

        // Une lecture posée par l'autre utilisateur n'affecte pas la mienne.
        $key = $data[0]['key'];
        Sanctum::actingAs($other);
        $this->putJson("/api/notifications/{$key}/read")->assertOk();

        Sanctum::actingAs($this->user);
        $response = $this->getJson('/api/notifications')->assertOk();
        $this->assertFalse($response->json('data.0.read'));
        $this->assertSame(1, $response->json('unread_count'));
        $this->assertSame([$mine->id], $response->json('data.0.action.stock_item_ids'));
    }

    public function test_household_member_sees_household_stock_alerts(): void
    {
        $household = \App\Models\Household::factory()->create(['owner_id' => $this->user->id]);
        $this->user->forceFill(['household_id' => $household->id])->save();
        $shared = Stock::factory()->forHousehold($household)->create(['name' => 'Frigo']);
        StockItem::factory()->for($shared)->create(['food_name' => 'Beurre', 'expires_at' => $this->day(2)]);
        // Un lieu personnel n'est plus visible une fois dans un foyer.
        $personal = Stock::factory()->for($this->user)->create(['name' => 'Placard']);
        StockItem::factory()->for($personal)->create(['food_name' => 'Perso', 'expires_at' => $this->day(1)]);

        $data = $this->getJson('/api/notifications')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertStringContainsString('Beurre (Frigo)', $data[0]['message']);
    }
}
