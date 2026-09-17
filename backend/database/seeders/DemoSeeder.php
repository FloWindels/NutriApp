<?php

namespace Database\Seeders;

use App\Models\Exercise;
use App\Models\Food;
use App\Models\Meal;
use App\Models\MealPlan;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\ShoppingItem;
use App\Models\Sport;
use App\Models\SportPlan;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\WeightLog;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Services\MealService;
use App\Services\NutritionCalculator;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Données de démonstration (brief §15) — compte demo@mavioh.app / Demo1234!.
 *
 * Contenu : profil complet avec cibles calculées, ~25 aliments français, 8 recettes publiques,
 * 3 lieux de stock et ~13 articles (2 bientôt périmés, 1 périmé, 1 épuisé), les repas du jour,
 * 5 pesées sur 6 semaines, 2 séances et 2 entrées de calendrier sportif, 4 articles de courses
 * et 3 repas planifiés pour la semaine en cours.
 *
 * Idempotent : uniquement `firstOrCreate` / `updateOrCreate` / `upsert`, sûr à rejouer.
 */
class DemoSeeder extends Seeder
{
    public const EMAIL = 'demo@mavioh.app';
    public const PASSWORD = 'Demo1234!';
    public const NAME = 'Florent';

    public function run(): void
    {
        $user = $this->user();
        $this->settings($user);

        // Le fuseau de l'utilisateur pilote toutes les dates (jamais now()->toDateString()).
        $today = CarbonImmutable::parse(Clock::today($user));

        $profile = $this->profile($user, $today);
        $foods = $this->foods();
        $recipes = $this->recipes($user);
        $stocks = $this->stockLocations($user);
        $this->stockItems($stocks, $foods, $today);
        $this->meals($user, $profile, $foods, $today);
        $this->weights($user, $today);
        $this->sport($user, $today);
        $this->shopping($user, $foods);
        $this->mealPlans($user, $recipes, $today);
        $this->coach($user, $today);
    }

    // ------------------------------------------------------------------------------------
    // Compte
    // ------------------------------------------------------------------------------------

    private function user(): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => self::NAME,
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'consentement_sante_at' => now(),
            ],
        );

        // Rejouable : le consentement santé est requis pour calculer des cibles.
        if ($user->consentement_sante_at === null) {
            $user->forceFill(['consentement_sante_at' => now()])->save();
        }

        return $user;
    }

    private function settings(User $user): UserSetting
    {
        $settings = UserSetting::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'notif_peremption' => true,
                'notif_rappel_repas' => true,
                'notif_rappel_sport' => true,
                'heure_rappel' => '19:00',
                'jours_alerte_peremption' => 3,
                'unites' => 'metrique',
                'theme' => 'systeme',
                'langue' => 'fr',
                'timezone' => 'Europe/Paris',
                'ia_seances' => true,
            ],
        );

        $user->setRelation('settings', $settings);
        Clock::forget($user);

        return $settings;
    }

    // ------------------------------------------------------------------------------------
    // Profil (cibles calculées par NutritionCalculator)
    // ------------------------------------------------------------------------------------

    private function profile(User $user, CarbonImmutable $today): Profile
    {
        $attributes = [
            'poids' => 82.0,
            'poids_souhaite_kg' => 76.0,
            'delai_objectif_jours' => 120,
            'taille' => 178.0,
            'age' => 32,
            'sexe' => 'homme',
            'objectif' => 'Perdre 6 kg en gardant ma masse musculaire',
            'objectif_type' => 'perdre',
            'niveau_activite' => 'modere',
            'regime_alimentaire' => 'omnivore',
            'allergenes' => [],
            'aliments_exclus' => ['abats'],
            'preferences' => ['aime' => ['poulet', 'saumon', 'riz'], 'evite' => ['choux de Bruxelles']],
            'objectif_calcul_auto' => true,
            'objectif_date_debut' => $today->subDays(20)->toDateString(),
            'objectif_date_fin' => $today->subDays(20)->addDays(120)->toDateString(),
            'poids_reference' => 82.0,
            'cibles_calculees_le' => $today->toDateString(),
            'situation_particuliere' => 'aucune',
            'consentement_parental' => false,
            // Sport (addendum §A.4)
            'sport_niveau' => 'intermediaire',
            'sport_objectif' => 'perte_de_gras',
            'sport_materiel' => ['halteres', 'elastiques', 'tapis'],
            'sport_temps_dispo_min' => 45,
            'sport_jours_semaine' => 3,
            'sport_lieu' => 'maison',
            'sport_zones_a_eviter' => ['genoux'],
            'sport_focus' => ['perte_de_gras', 'gainage'],
            'sport_notes' => 'Gêne au genou droit après les sauts : privilégier les mouvements sans impact.',
            'sport_coef_calories' => 100,
        ];

        $profile = Profile::query()->firstOrCreate(['user_id' => $user->id], $attributes);

        // Cibles recalculées à chaque exécution : elles doivent rester cohérentes avec le profil.
        $calculator = app(NutritionCalculator::class);
        $besoins = $calculator->fromProfile($profile, $today->toDateString());

        if ($besoins !== null) {
            $profile->forceFill([
                'calories_cibles' => (int) $besoins['calories_recommandees'],
                'proteines_cibles' => (int) $besoins['proteines_g'],
                'glucides_cibles' => (int) $besoins['glucides_g'],
                'lipides_cibles' => (int) $besoins['lipides_g'],
                'cibles_calculees_le' => $today->toDateString(),
            ])->save();
        }

        $user->setRelation('profile', $profile);

        return $profile;
    }

    // ------------------------------------------------------------------------------------
    // Aliments (valeurs pour 100 g / 100 ml, sources ciqual & Open Food Facts)
    // ------------------------------------------------------------------------------------

    /**
     * @return array<string, Food>  clé = référence interne (poulet, riz…)
     */
    private function foods(): array
    {
        $rows = [
            // clé, nom, marque, kcal, lipides, glucides, protéines, fibres, sucres, sel, portion g, libellé, catégorie, code-barres
            ['poulet', 'Blanc de poulet', null, 165, 3.6, 0.0, 31.0, 0.0, 0.0, 0.15, 120, '1 escalope (120 g)', 'viande', null],
            ['riz', 'Riz blanc cuit', null, 130, 0.3, 28.0, 2.7, 0.4, 0.1, 0.01, 150, '1 portion (150 g)', 'feculent', null],
            ['pates', 'Pâtes complètes cuites', 'Panzani', 124, 1.1, 24.0, 5.0, 3.5, 1.0, 0.01, 150, '1 portion (150 g)', 'feculent', '3038350203014'],
            ['oeuf', 'Œuf entier', null, 143, 9.5, 0.7, 12.6, 0.0, 0.4, 0.35, 55, '1 œuf (55 g)', 'oeuf', null],
            ['lait', 'Lait demi-écrémé', 'Lactel', 46, 1.6, 4.8, 3.2, 0.0, 4.8, 0.1, 200, '1 verre (200 ml)', 'produit_laitier', '3033491301537'],
            ['yaourt', 'Yaourt nature', 'Danone', 61, 3.3, 4.7, 3.5, 0.0, 4.7, 0.13, 125, '1 pot (125 g)', 'yaourt', '3033490004743'],
            ['pomme', 'Pomme', null, 52, 0.2, 13.8, 0.3, 2.4, 10.4, 0.0, 150, '1 pomme (150 g)', 'fruit', null],
            ['banane', 'Banane', null, 89, 0.3, 22.8, 1.1, 2.6, 12.2, 0.0, 120, '1 banane (120 g)', 'fruit', null],
            ['pain', 'Pain complet', 'Jacquet', 247, 3.4, 41.0, 9.7, 6.5, 3.0, 1.2, 30, '1 tranche (30 g)', 'pain', '3560070976478'],
            ['avoine', 'Flocons d’avoine', 'Quaker', 379, 6.5, 67.7, 13.2, 10.1, 1.0, 0.02, 40, '1 bol (40 g)', 'cereales', '3175680011480'],
            ['saumon', 'Saumon frais', null, 208, 13.4, 0.0, 20.4, 0.0, 0.0, 0.1, 130, '1 pavé (130 g)', 'poisson', null],
            ['thon', 'Thon au naturel', 'Petit Navire', 116, 1.0, 0.0, 26.0, 0.0, 0.0, 0.9, 80, '1 boîte égouttée (80 g)', 'poisson', '3165950571233'],
            ['lentilles', 'Lentilles cuites', null, 116, 0.4, 20.1, 9.0, 7.9, 1.8, 0.01, 150, '1 portion (150 g)', 'legumineuse', null],
            ['brocoli', 'Brocoli', null, 34, 0.4, 6.6, 2.8, 2.6, 1.7, 0.03, 120, '1 portion (120 g)', 'legume', null],
            ['carotte', 'Carotte', null, 41, 0.2, 9.6, 0.9, 2.8, 4.7, 0.07, 120, '1 portion (120 g)', 'legume', null],
            ['tomate', 'Tomate', null, 18, 0.2, 3.9, 0.9, 1.2, 2.6, 0.01, 120, '1 tomate (120 g)', 'legume', null],
            ['huile', 'Huile d’olive vierge extra', 'Puget', 884, 100.0, 0.0, 0.0, 0.0, 0.0, 0.0, 10, '1 c. à s. (10 g)', 'huile', '3168930000297'],
            ['fromage_blanc', 'Fromage blanc 3 %', 'Danone', 75, 3.0, 4.5, 7.5, 0.0, 4.5, 0.1, 100, '1 portion (100 g)', 'produit_laitier', null],
            ['amandes', 'Amandes', null, 579, 49.9, 21.6, 21.2, 12.5, 4.4, 0.0, 30, '1 poignée (30 g)', 'oleagineux', null],
            ['chocolat', 'Chocolat noir 70 %', 'Lindt', 546, 31.0, 45.9, 7.8, 10.9, 24.0, 0.02, 20, '2 carrés (20 g)', 'chocolat', '3046920028004'],
            ['beurre', 'Beurre doux', 'Président', 717, 81.0, 0.6, 0.9, 0.0, 0.6, 0.02, 10, '1 noisette (10 g)', 'produit_laitier', null],
            ['emmental', 'Emmental râpé', null, 380, 29.0, 1.5, 28.0, 0.0, 1.5, 0.7, 30, '1 portion (30 g)', 'fromage', null],
            ['jambon', 'Jambon blanc', 'Herta', 107, 3.0, 1.0, 18.8, 0.0, 0.8, 2.0, 40, '1 tranche (40 g)', 'jambon', '3154230100010'],
            ['pdt', 'Pomme de terre cuite', null, 77, 0.1, 17.5, 2.0, 2.1, 0.8, 0.01, 150, '1 portion (150 g)', 'legume', null],
            ['quinoa', 'Quinoa cuit', null, 120, 1.9, 21.3, 4.4, 2.8, 0.9, 0.01, 150, '1 portion (150 g)', 'feculent', null],
            ['epinards', 'Épinards cuits', null, 23, 0.4, 3.6, 2.9, 2.2, 0.4, 0.08, 150, '1 portion (150 g)', 'legume', null],
            ['haricots', 'Haricots verts', null, 31, 0.1, 7.0, 1.8, 3.4, 3.3, 0.01, 150, '1 portion (150 g)', 'legume', null],
        ];

        $foods = [];

        foreach ($rows as $row) {
            [$key, $name, $brand, $kcal, $fat, $carbs, $proteins, $fiber, $sugar, $salt, $serving, $label, $category, $barcode] = $row;

            $attributes = [
                'barcode' => $barcode,
                'brand' => $brand,
                'calories' => (float) $kcal,
                'fat' => (float) $fat,
                'carbs' => (float) $carbs,
                'proteins' => (float) $proteins,
                'fiber' => (float) $fiber,
                'sugar' => (float) $sugar,
                'salt' => (float) $salt,
                'serving_size_g' => (float) $serving,
                'serving_label' => $label,
                'category' => $category,
                'allergens' => [],
                'per_unit' => '100g',
                'source_type' => $barcode !== null ? 'open_food_facts' : 'manual',
                'source_fetched_at' => $barcode !== null ? now() : null,
                'off_last_checked_at' => $barcode !== null ? now() : null,
                'is_verified' => $barcode !== null,
                'created_by_user_id' => null,
            ];

            if ($key === 'lait') {
                $attributes['density_g_per_ml'] = 1.03;
            }
            if ($key === 'huile') {
                $attributes['density_g_per_ml'] = 0.91;
            }

            $foods[$key] = Food::query()->firstOrCreate(['name' => $name], $attributes);
        }

        return $foods;
    }

    // ------------------------------------------------------------------------------------
    // Recettes publiques
    // ------------------------------------------------------------------------------------

    /**
     * @return array<string, Recipe>
     */
    private function recipes(User $user): array
    {
        $rows = [
            [
                'key' => 'poulet_riz',
                'title' => 'Poulet rôti, riz complet et brocolis',
                'description' => 'Un grand classique équilibré : protéines maigres, féculent complet et légumes vapeur.',
                'prep' => 35, 'servings' => 4.0,
                'calories' => 2120, 'proteins' => 168.0, 'carbs' => 210.0, 'fat' => 58.0,
                'tags' => ['riche_en_proteines', 'economique'],
                'meal_types' => ['dejeuner', 'diner'],
                'ingredients' => [
                    ['name' => 'Blanc de poulet', 'ean' => null, 'amount' => 600, 'unit' => 'g'],
                    ['name' => 'Riz blanc cuit', 'ean' => null, 'amount' => 600, 'unit' => 'g'],
                    ['name' => 'Brocoli', 'ean' => null, 'amount' => 400, 'unit' => 'g'],
                    ['name' => 'Huile d’olive vierge extra', 'ean' => '3168930000297', 'amount' => 3, 'unit' => 'cas'],
                ],
            ],
            [
                'key' => 'omelette',
                'title' => 'Omelette aux épinards et emmental',
                'description' => 'Prête en dix minutes, parfaite le soir quand il reste des œufs au frigo.',
                'prep' => 12, 'servings' => 2.0,
                'calories' => 760, 'proteins' => 54.0, 'carbs' => 8.0, 'fat' => 56.0,
                'tags' => ['vegetarien', 'rapide', 'riche_en_proteines', 'low_carb'],
                'meal_types' => ['diner'],
                'ingredients' => [
                    ['name' => 'Œuf entier', 'ean' => null, 'amount' => 6, 'unit' => 'piece'],
                    ['name' => 'Épinards cuits', 'ean' => null, 'amount' => 200, 'unit' => 'g'],
                    ['name' => 'Emmental râpé', 'ean' => null, 'amount' => 40, 'unit' => 'g'],
                    ['name' => 'Beurre doux', 'ean' => null, 'amount' => 10, 'unit' => 'g'],
                ],
            ],
            [
                'key' => 'porridge',
                'title' => 'Porridge avoine, banane et amandes',
                'description' => 'Petit-déjeuner rassasiant, à préparer la veille en version froide.',
                'prep' => 8, 'servings' => 1.0,
                'calories' => 480, 'proteins' => 18.0, 'carbs' => 62.0, 'fat' => 16.0,
                'tags' => ['vegetarien', 'rapide'],
                'meal_types' => ['petit_dejeuner'],
                'ingredients' => [
                    ['name' => 'Flocons d’avoine', 'ean' => '3175680011480', 'amount' => 60, 'unit' => 'g'],
                    ['name' => 'Lait demi-écrémé', 'ean' => '3033491301537', 'amount' => 200, 'unit' => 'ml'],
                    ['name' => 'Banane', 'ean' => null, 'amount' => 1, 'unit' => 'piece'],
                    ['name' => 'Amandes', 'ean' => null, 'amount' => 15, 'unit' => 'g'],
                ],
            ],
            [
                'key' => 'salade_lentilles',
                'title' => 'Salade de lentilles, tomates et féta végétale',
                'description' => 'Salade complète qui se transporte bien au bureau.',
                'prep' => 15, 'servings' => 2.0,
                'calories' => 700, 'proteins' => 36.0, 'carbs' => 78.0, 'fat' => 24.0,
                'tags' => ['vegetarien', 'mediterraneen', 'economique'],
                'meal_types' => ['dejeuner'],
                'ingredients' => [
                    ['name' => 'Lentilles cuites', 'ean' => null, 'amount' => 300, 'unit' => 'g'],
                    ['name' => 'Tomate', 'ean' => null, 'amount' => 200, 'unit' => 'g'],
                    ['name' => 'Carotte', 'ean' => null, 'amount' => 100, 'unit' => 'g'],
                    ['name' => 'Huile d’olive vierge extra', 'ean' => '3168930000297', 'amount' => 2, 'unit' => 'cas'],
                ],
            ],
            [
                'key' => 'saumon',
                'title' => 'Saumon vapeur et haricots verts',
                'description' => 'Cuisson douce pour préserver les oméga-3 ; un filet de citron suffit.',
                'prep' => 20, 'servings' => 2.0,
                'calories' => 760, 'proteins' => 58.0, 'carbs' => 22.0, 'fat' => 46.0,
                'tags' => ['mediterraneen', 'riche_en_proteines', 'sans_gluten'],
                'meal_types' => ['dejeuner', 'diner'],
                'ingredients' => [
                    ['name' => 'Saumon frais', 'ean' => null, 'amount' => 260, 'unit' => 'g'],
                    ['name' => 'Haricots verts', 'ean' => null, 'amount' => 300, 'unit' => 'g'],
                    ['name' => 'Huile d’olive vierge extra', 'ean' => '3168930000297', 'amount' => 2, 'unit' => 'cas'],
                ],
            ],
            [
                'key' => 'pates_thon',
                'title' => 'Pâtes complètes au thon et tomates',
                'description' => 'Le dépannage du mercredi soir, avec ce qu’il reste dans le placard.',
                'prep' => 18, 'servings' => 2.0,
                'calories' => 880, 'proteins' => 58.0, 'carbs' => 108.0, 'fat' => 20.0,
                'tags' => ['rapide', 'economique', 'riche_en_proteines'],
                'meal_types' => ['dejeuner', 'diner'],
                'ingredients' => [
                    ['name' => 'Pâtes complètes cuites', 'ean' => '3038350203014', 'amount' => 400, 'unit' => 'g'],
                    ['name' => 'Thon au naturel', 'ean' => '3165950571233', 'amount' => 160, 'unit' => 'g'],
                    ['name' => 'Tomate', 'ean' => null, 'amount' => 200, 'unit' => 'g'],
                    ['name' => 'Huile d’olive vierge extra', 'ean' => '3168930000297', 'amount' => 1, 'unit' => 'cas'],
                ],
            ],
            [
                'key' => 'quinoa',
                'title' => 'Bowl quinoa, carottes rôties et amandes',
                'description' => 'Bowl végétarien coloré, très bon froid le lendemain.',
                'prep' => 30, 'servings' => 2.0,
                'calories' => 820, 'proteins' => 26.0, 'carbs' => 92.0, 'fat' => 34.0,
                'tags' => ['vegetarien', 'vegan', 'sans_lactose', 'mediterraneen'],
                'meal_types' => ['dejeuner', 'diner'],
                'ingredients' => [
                    ['name' => 'Quinoa cuit', 'ean' => null, 'amount' => 300, 'unit' => 'g'],
                    ['name' => 'Carotte', 'ean' => null, 'amount' => 250, 'unit' => 'g'],
                    ['name' => 'Amandes', 'ean' => null, 'amount' => 40, 'unit' => 'g'],
                    ['name' => 'Huile d’olive vierge extra', 'ean' => '3168930000297', 'amount' => 2, 'unit' => 'cas'],
                ],
            ],
            [
                'key' => 'collation',
                'title' => 'Fromage blanc, pomme et amandes',
                'description' => 'Collation protéinée de l’après-midi, idéale avant une séance.',
                'prep' => 3, 'servings' => 1.0,
                'calories' => 300, 'proteins' => 20.0, 'carbs' => 24.0, 'fat' => 14.0,
                'tags' => ['vegetarien', 'rapide', 'riche_en_proteines'],
                'meal_types' => ['collation'],
                'ingredients' => [
                    ['name' => 'Fromage blanc 3 %', 'ean' => null, 'amount' => 200, 'unit' => 'g'],
                    ['name' => 'Pomme', 'ean' => null, 'amount' => 1, 'unit' => 'piece'],
                    ['name' => 'Amandes', 'ean' => null, 'amount' => 20, 'unit' => 'g'],
                ],
            ],
        ];

        $recipes = [];

        foreach ($rows as $row) {
            $recipes[$row['key']] = Recipe::query()->firstOrCreate(
                ['title' => $row['title'], 'created_by_user_id' => $user->id],
                [
                    'description' => $row['description'],
                    'prep_time_minutes' => $row['prep'],
                    'calories' => (float) $row['calories'],
                    'proteins' => $row['proteins'],
                    'carbs' => $row['carbs'],
                    'fat' => $row['fat'],
                    'servings' => $row['servings'],
                    'image_url' => null,
                    'ingredients' => $row['ingredients'],
                    'tags' => $row['tags'],
                    'meal_types' => $row['meal_types'],
                    'is_public' => true,
                    'is_estimate' => false,
                ],
            );
        }

        return $recipes;
    }

    // ------------------------------------------------------------------------------------
    // Stock
    // ------------------------------------------------------------------------------------

    /**
     * @return array<string, Stock>
     */
    private function stockLocations(User $user): array
    {
        $locations = [];

        foreach (['frigo' => 'Frigo', 'congelateur' => 'Congélateur', 'placard' => 'Placard'] as $key => $name) {
            $locations[$key] = Stock::query()->firstOrCreate([
                'user_id' => $user->id,
                'household_id' => null,
                'name' => $name,
            ]);
        }

        return $locations;
    }

    /**
     * @param  array<string, Stock>  $stocks
     * @param  array<string, Food>  $foods
     */
    private function stockItems(array $stocks, array $foods, CarbonImmutable $today): void
    {
        $rows = [
            // lieu, aliment, libellé, quantité, unité, péremption (jours relatifs), type, seuil bas, épuisé
            ['frigo', 'yaourt', 'Yaourt nature', 8, 'unite', 2, 'dlc', 2.0, false],
            ['frigo', 'lait', 'Lait demi-écrémé', 1000, 'ml', 3, 'dlc', 250.0, false],
            ['frigo', 'jambon', 'Jambon blanc', 4, 'unite', -2, 'dlc', null, false],
            ['frigo', 'fromage_blanc', 'Fromage blanc 3 %', 500, 'g', 12, 'dlc', null, false],
            ['frigo', 'brocoli', 'Brocoli', 400, 'g', 5, 'dlc', null, false],
            ['frigo', 'oeuf', 'Œufs', 6, 'unite', 14, 'dlc', 2.0, false],
            ['congelateur', 'saumon', 'Pavés de saumon', 2, 'unite', 60, 'ddm', null, false],
            ['congelateur', 'haricots', 'Haricots verts surgelés', 500, 'g', 180, 'ddm', null, false],
            ['placard', 'riz', 'Riz blanc', 1000, 'g', 240, 'ddm', 200.0, false],
            ['placard', 'pates', 'Pâtes complètes', 0, 'g', 200, 'ddm', 200.0, true],
            ['placard', 'huile', 'Huile d’olive vierge extra', 750, 'ml', 300, 'ddm', null, false],
            ['placard', 'avoine', 'Flocons d’avoine', 500, 'g', 200, 'ddm', 100.0, false],
            ['placard', 'chocolat', 'Chocolat noir 70 %', 40, 'g', 150, 'ddm', 50.0, false],
        ];

        foreach ($rows as [$location, $foodKey, $label, $quantity, $unit, $expiresIn, $kind, $min, $depleted]) {
            $stock = $stocks[$location];
            $food = $foods[$foodKey] ?? null;

            StockItem::query()->firstOrCreate(
                ['stock_id' => $stock->id, 'food_name' => $label],
                [
                    'food_id' => $food?->id,
                    'food_barcode' => $food?->barcode,
                    'food_brand' => $food?->brand,
                    'quantity' => (float) $quantity,
                    'unit' => $unit,
                    'expires_at' => $today->addDays($expiresIn)->toDateString(),
                    'expiry_kind' => $kind,
                    'min_quantity' => $min,
                    'opened_at' => $location === 'frigo' ? $today->subDays(1)->toDateString() : null,
                    'depleted_at' => $depleted ? now() : null,
                ],
            );
        }
    }

    // ------------------------------------------------------------------------------------
    // Repas du jour (via MealService pour rester cohérent avec /meals et daily_targets)
    // ------------------------------------------------------------------------------------

    /**
     * @param  array<string, Food>  $foods
     */
    private function meals(User $user, Profile $profile, array $foods, CarbonImmutable $today): void
    {
        $service = app(MealService::class);
        $date = $today->toDateString();

        $plan = [
            'petit_dejeuner' => [
                'name' => 'Porridge du matin',
                'hour' => '08:10',
                'items' => [
                    ['food_id' => $foods['avoine']->id, 'quantity' => 60, 'unit' => 'g'],
                    ['food_id' => $foods['lait']->id, 'quantity' => 200, 'unit' => 'ml'],
                    ['food_id' => $foods['banane']->id, 'quantity' => 1, 'unit' => 'piece'],
                    ['food_id' => $foods['amandes']->id, 'quantity' => 15, 'unit' => 'g'],
                ],
            ],
            'dejeuner' => [
                'name' => 'Poulet, riz et brocolis',
                'hour' => '12:40',
                'items' => [
                    ['food_id' => $foods['poulet']->id, 'quantity' => 150, 'unit' => 'g'],
                    ['food_id' => $foods['riz']->id, 'quantity' => 180, 'unit' => 'g'],
                    ['food_id' => $foods['brocoli']->id, 'quantity' => 150, 'unit' => 'g'],
                    ['food_id' => $foods['huile']->id, 'quantity' => 1, 'unit' => 'cas'],
                ],
            ],
        ];

        foreach ($plan as $type => $definition) {
            $meal = $service->findOrCreate($user, $date, $type, $definition['name']);
            $meal->setRelation('user', $user);

            // Rejouable : on n'ajoute les éléments qu'une seule fois.
            if ($meal->items()->exists()) {
                continue;
            }

            $service->addItems($meal, $definition['items']);

            $meal->forceFill([
                'consumed_at' => $today->setTimeFromTimeString($definition['hour'])->toDateTimeString(),
            ])->save();
        }

        // Les cibles du jour sont figées au premier élément : filet de sécurité si le profil
        // a été complété après coup.
        $service->ensureDailyTargets($user, $date);
        unset($profile);
    }

    // ------------------------------------------------------------------------------------
    // Pesées (5 sur les 6 dernières semaines)
    // ------------------------------------------------------------------------------------

    private function weights(User $user, CarbonImmutable $today): void
    {
        $rows = [42 => 84.2, 35 => 83.8, 21 => 83.1, 10 => 82.6, 3 => 82.0];

        foreach ($rows as $daysAgo => $weight) {
            WeightLog::query()->updateOrCreate(
                ['user_id' => $user->id, 'date' => $today->subDays($daysAgo)->toDateString()],
                ['weight_kg' => $weight],
            );
        }
    }

    // ------------------------------------------------------------------------------------
    // Sport : 2 séances + 2 entrées de calendrier
    // ------------------------------------------------------------------------------------

    private function sport(User $user, CarbonImmutable $today): void
    {
        $musculation = Sport::query()->where('slug', 'musculation')->first();
        $course = Sport::query()->where('slug', 'course-a-pied')->first();

        $yesterday = $today->subDay();

        // 1) Séance terminée hier, calories estimées automatiquement.
        $done = WorkoutSession::query()->firstOrCreate(
            ['user_id' => $user->id, 'date' => $yesterday->toDateString(), 'title' => 'Séance haut du corps'],
            [
                'kind' => 'seance',
                'goal' => 'perte_de_gras',
                'level' => 'intermediaire',
                'equipment' => ['halteres', 'elastiques', 'tapis'],
                'focus' => ['haut_du_corps', 'gainage'],
                'duration_min' => 45,
                'calories_burned' => 288.0,
                'calories_source' => 'auto',
                'status' => 'terminee',
                'rpe' => 7,
                'notes' => 'Bonne séance, sensations correctes sur les tirages.',
                'source' => 'generee',
                'generated_by' => 'regles',
                'intensity' => 'moderee',
                'lieu' => 'maison',
                'sport_id' => $musculation?->id,
                'sport_name' => $musculation?->name ?? 'Musculation',
                'zones_a_eviter' => ['genoux'],
                'planned_at' => '18:30',
                'started_at' => $yesterday->setTimeFromTimeString('18:30')->toDateTimeString(),
                'completed_at' => $yesterday->setTimeFromTimeString('19:15')->toDateTimeString(),
            ],
        );

        $this->sessionExercises($done);

        // 2) Séance prévue aujourd'hui.
        $planned = WorkoutSession::query()->firstOrCreate(
            ['user_id' => $user->id, 'date' => $today->toDateString(), 'title' => 'Séance bas du corps sans impact'],
            [
                'kind' => 'seance',
                'goal' => 'perte_de_gras',
                'level' => 'intermediaire',
                'equipment' => ['halteres', 'elastiques', 'tapis'],
                'focus' => ['bas_du_corps', 'fessiers'],
                'duration_min' => 40,
                'status' => 'prevue',
                'source' => 'generee',
                'generated_by' => 'regles',
                'intensity' => 'moderee',
                'lieu' => 'maison',
                'sport_id' => $musculation?->id,
                'sport_name' => $musculation?->name ?? 'Musculation',
                'zones_a_eviter' => ['genoux'],
                'planned_at' => '18:30',
            ],
        );

        $this->sessionExercises($planned);

        // Calendrier : l'entrée d'hier est réalisée et pointe vers la séance terminée.
        SportPlan::query()->firstOrCreate(
            ['user_id' => $user->id, 'date' => $yesterday->toDateString(), 'sport_name' => $musculation?->name ?? 'Musculation'],
            [
                'sport_id' => $musculation?->id,
                'planned_duration_min' => 45,
                'planned_at' => '18:30',
                'lieu' => 'maison',
                'notes' => 'Haut du corps + gainage',
                'status' => 'realise',
                'session_id' => $done->id,
            ],
        );

        // Entrée prévue plus tard dans la semaine (jeudi de la semaine en cours, sinon +2 jours).
        $later = $today->startOfWeek(CarbonInterface::MONDAY)->addDays(3);
        if ($later->lessThanOrEqualTo($today)) {
            $later = $today->addDays(2);
        }

        SportPlan::query()->firstOrCreate(
            ['user_id' => $user->id, 'date' => $later->toDateString(), 'sport_name' => $course?->name ?? 'Course à pied'],
            [
                'sport_id' => $course?->id,
                'planned_duration_min' => 30,
                'planned_at' => '07:30',
                'lieu' => 'exterieur',
                'notes' => 'Sortie facile en endurance fondamentale',
                'status' => 'prevu',
                'session_id' => null,
            ],
        );
    }

    /**
     * Quelques exercices du catalogue, compatibles avec le matériel maison du profil démo.
     */
    private function sessionExercises(WorkoutSession $session): void
    {
        if (WorkoutExercise::query()->where('session_id', $session->id)->exists()) {
            return;
        }

        $catalog = Exercise::query()
            ->where('is_public', true)
            ->whereIn('equipment', ['aucun', 'halteres', 'elastiques', 'tapis'])
            ->orderBy('id')
            ->limit(5)
            ->get();

        $position = 0;

        foreach ($catalog as $exercise) {
            $block = $position === 0 ? 'echauffement' : ($position >= 4 ? 'retour_au_calme' : 'principal');

            WorkoutExercise::query()->create([
                'session_id' => $session->id,
                'exercise_id' => $exercise->id,
                'block' => $block,
                'position' => $position,
                'name' => $exercise->name,
                'sets' => $block === 'principal' ? ($exercise->default_sets ?? 3) : null,
                'reps' => $block === 'principal' ? ($exercise->default_reps ?? 10) : null,
                'duration_sec' => $block === 'principal' ? null : ($exercise->default_duration_sec ?? 60),
                'rest_sec' => $block === 'principal' ? 60 : null,
                'met' => $exercise->met,
                'completed' => $session->status === 'terminee',
            ]);

            $position++;
        }
    }

    // ------------------------------------------------------------------------------------
    // Liste de courses
    // ------------------------------------------------------------------------------------

    /**
     * @param  array<string, Food>  $foods
     */
    private function shopping(User $user, array $foods): void
    {
        $rows = [
            ['Pâtes complètes', 1, 'paquet', false, 'auto_stock', $foods['pates']->id],
            ['Tomates', 6, 'piece', true, 'manuel', $foods['tomate']->id],
            ['Yaourts nature', 1, 'pack', true, 'manuel', $foods['yaourt']->id],
            ['Huile d’olive', 1, 'bouteille', false, 'manuel', $foods['huile']->id],
        ];

        foreach ($rows as [$label, $quantity, $unit, $checked, $source, $foodId]) {
            ShoppingItem::query()->firstOrCreate(
                ['user_id' => $user->id, 'household_id' => null, 'label' => $label],
                [
                    'food_id' => $foodId,
                    'quantity' => (float) $quantity,
                    'unit' => $unit,
                    'checked' => $checked,
                    'source' => $source,
                ],
            );
        }
    }

    // ------------------------------------------------------------------------------------
    // Planificateur de la semaine
    // ------------------------------------------------------------------------------------

    /**
     * @param  array<string, Recipe>  $recipes
     */
    private function mealPlans(User $user, array $recipes, CarbonImmutable $today): void
    {
        $monday = $today->startOfWeek(CarbonInterface::MONDAY);

        $rows = [
            [$today->toDateString(), 'diner', $recipes['omelette'], 1.0, 'Reste des œufs à finir'],
            [$monday->addDays(3)->toDateString(), 'dejeuner', $recipes['salade_lentilles'], 1.0, 'À emporter au bureau'],
            [$monday->addDays(4)->toDateString(), 'diner', $recipes['saumon'], 2.0, 'Repas du vendredi soir'],
        ];

        foreach ($rows as [$date, $type, $recipe, $servings, $notes]) {
            MealPlan::query()->firstOrCreate(
                ['user_id' => $user->id, 'date' => $date, 'meal_type' => $type, 'title' => $recipe->title],
                [
                    'household_id' => null,
                    'recipe_id' => $recipe->id,
                    'food_id' => null,
                    'servings' => $servings,
                    'notes' => $notes,
                    'status' => 'prevu',
                    'meal_id' => null,
                ],
            );
        }
    }

    // ------------------------------------------------------------------------------------
    // Coach : recommandations du jour (best effort, le seeding ne doit jamais échouer)
    // ------------------------------------------------------------------------------------

    private function coach(User $user, CarbonImmutable $today): void
    {
        if (! class_exists(\App\Services\Coach\CoachEngine::class)) {
            return;
        }

        try {
            $user->loadMissing(['profile', 'settings']);
            app(\App\Services\Coach\CoachEngine::class)->forDay($user, $today->toDateString());
        } catch (Throwable $e) {
            $this->command?->warn('DemoSeeder : recommandations du coach ignorées ('.$e->getMessage().').');
        }
    }
}
