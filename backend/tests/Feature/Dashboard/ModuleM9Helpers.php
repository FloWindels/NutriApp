<?php

namespace Tests\Feature\Dashboard;

use App\Models\Food;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\Profile;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use App\Models\UserSetting;
use App\Support\Clock;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Aides partagées par les tests du module M9 (tableau de bord, historique, coach, régimes).
 *
 * L'horloge est figée à une heure UTC choisie : le fuseau des utilisateurs de test est
 * « Europe/Paris » (UTC+2 en septembre), donc 18:00 UTC = 20:00 locales.
 */
trait ModuleM9Helpers
{
    /** Journée de référence (identique en UTC et à Paris aux heures utilisées). */
    public const TODAY = '2026-09-16';

    /** Cibles du profil de test : 2 000 kcal / 120 P / 250 G / 70 L. */
    public const TARGET_CALORIES = 2000;
    public const TARGET_PROTEINS = 120;
    public const TARGET_CARBS = 250;
    public const TARGET_FAT = 70;

    /**
     * Fige l'horloge : $localHour est l'heure voulue à Paris le jour self::TODAY.
     */
    protected function freezeAtLocalHour(int $localHour, int $minutes = 0, string $date = self::TODAY): void
    {
        $paris = Carbon::parse(sprintf('%s %02d:%02d:00', $date, $localHour, $minutes), 'Europe/Paris');
        Carbon::setTestNow($paris->copy()->utc());
        Clock::forget();
    }

    /**
     * Utilisateur avec réglages (fuseau Paris) et profil complet.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $settings
     */
    protected function userWithProfile(array $profile = [], array $settings = []): User
    {
        $user = $this->userWithoutProfile($settings);

        Profile::factory()->create(array_merge([
            'user_id' => $user->id,
            'sexe' => 'homme',
            'age' => 32,
            'taille' => 178,
            'poids' => 80,
            'poids_reference' => 80,
            'poids_souhaite_kg' => 80,
            'delai_objectif_jours' => null,
            'niveau_activite' => 'modere',
            'objectif_type' => 'maintenir',
            'regime_alimentaire' => 'omnivore',
            'calories_cibles' => self::TARGET_CALORIES,
            'proteines_cibles' => self::TARGET_PROTEINS,
            'glucides_cibles' => self::TARGET_CARBS,
            'lipides_cibles' => self::TARGET_FAT,
            'allergenes' => [],
            'aliments_exclus' => [],
            'sport_coef_calories' => 100,
            'situation_particuliere' => 'aucune',
        ], $profile));

        return $user->fresh();
    }

    /**
     * Utilisateur sans profil (chemin `has_profile: false`).
     *
     * @param  array<string, mixed>  $settings
     */
    protected function userWithoutProfile(array $settings = []): User
    {
        $user = User::factory()->create(['name' => 'Camille Durand']);

        UserSetting::factory()->create(array_merge([
            'user_id' => $user->id,
            'timezone' => 'Europe/Paris',
            'jours_alerte_peremption' => 3,
        ], $settings));

        return $user->fresh();
    }

    protected function login(User $user): User
    {
        Sanctum::actingAs($user);
        Clock::forget();

        return $user;
    }

    protected function personalStock(User $user, string $name = 'Frigo'): Stock
    {
        return Stock::factory()->create(['user_id' => $user->id, 'household_id' => null, 'name' => $name]);
    }

    /**
     * Crée un repas avec un item « custom » aux macros données.
     *
     * @param  array<string, mixed>  $macros
     * @param  array<string, mixed>  $mealAttributes
     */
    protected function logMeal(User $user, string $type, array $macros = [], string $date = self::TODAY, array $mealAttributes = []): Meal
    {
        $meal = Meal::factory()->create(array_merge([
            'user_id' => $user->id,
            'date' => $date,
            'type' => $type,
        ], $mealAttributes));

        MealItem::factory()->create(array_merge([
            'meal_id' => $meal->id,
            'label' => 'Plat du jour',
            'calories' => 500,
            'proteins' => 30,
            'carbs' => 50,
            'fat' => 15,
            'fiber' => null,
            'sugar' => null,
            'salt' => null,
        ], $macros));

        return $meal;
    }

    /**
     * Article de stock rattaché (ou non) à un aliment.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function stockItem(Stock $stock, array $attributes = [], ?Food $food = null): StockItem
    {
        return StockItem::factory()->create(array_merge([
            'stock_id' => $stock->id,
            'food_id' => $food?->id,
            'food_name' => $food?->name ?? 'Yaourt nature',
            'food_barcode' => $food?->barcode,
            'quantity' => 2,
            'unit' => 'piece',
            'expires_at' => null,
            'min_quantity' => null,
        ], $attributes));
    }

    protected function daysFromToday(int $days, string $date = self::TODAY): string
    {
        return Carbon::parse($date)->addDays($days)->toDateString();
    }
}
