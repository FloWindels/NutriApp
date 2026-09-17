<?php

namespace App\Services\Household;

use App\Enums\MealType;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use App\Services\MealBudget;
use App\Services\MealCalculator;
use App\Services\MealService;
use App\Services\NutritionCalculator;
use App\Support\Clock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Repas commun du foyer (brief §10) : une recette, un type de repas, une portion adaptée
 * à chaque membre.
 *
 * - `share_profile` vrai (ou soi-même) → `target_kcal = MealBudget::forMeal(membre)` ;
 * - `share_profile` faux → `cibles × part(type)` avec le facteur « profil non partagé —
 *   estimation sur les cibles » ;
 * - portions par pas de 0,5 bornées à [0,5 ; 2,0] ; mineurs : label « portion indicative ».
 */
class CommonMealService
{
    public const PORTION_MIN = 0.5;
    public const PORTION_MAX = 2.0;
    public const PORTION_STEP = 0.5;

    public const LABEL_MINEUR = 'portion indicative';
    public const LABEL_ESTIMATION = 'estimation';
    public const LABEL_ADAPTEE = 'portion adaptée';
    public const LABEL_PROFIL_INCOMPLET = 'profil incomplet';

    public const FACTEUR_NON_PARTAGE = 'profil non partagé — estimation sur les cibles';
    public const FACTEUR_MINEUR = 'moins de 18 ans — portion indicative';
    public const FACTEUR_PROFIL_INCOMPLET = 'profil incomplet — portion par défaut';
    public const FACTEUR_BUDGET = 'budget restant du repas';
    public const FACTEUR_RECETTE_ESTIMEE = 'recette estimée';

    public const MSG_NON_MEMBRE = 'Cette personne ne fait pas partie du foyer.';
    public const MSG_PROFIL_NON_PARTAGE = ':name ne partage pas son profil : tu ne peux pas enregistrer un repas pour cette personne.';
    public const MSG_RECETTE_PRIVEE = 'Rends la recette publique pour l’enregistrer chez les autres membres du foyer.';

    public function __construct(
        private readonly MealBudget $budget,
        private readonly MealCalculator $calculator,
        private readonly NutritionCalculator $nutrition,
        private readonly MealService $meals,
    ) {
    }

    /**
     * Budget du repas pour un membre (même règle que MealBudget::forMeal). `daySummary()`
     * renvoie `logged_types` sous forme d'enums MealType : on les ramène à leur valeur chaîne
     * avant `budgetFromSummary()` (comparaison stricte), sinon un repas déjà enregistré
     * n'est jamais reconnu comme tel.
     */
    public function budgetFor(User $user, string $date, string $mealType): float
    {
        $summary = $this->calculator->daySummary($user, $date);

        $logged = array_map(
            fn ($t) => $t instanceof MealType ? $t->value : (string) $t,
            $summary['logged_types'] ?? []
        );

        return $this->budget->budgetFromSummary(
            (float) ($summary['remaining']['calories'] ?? 0.0),
            $mealType,
            $logged,
            $summary['regime'] ?? null,
        );
    }

    /**
     * Aperçu des portions pour chaque membre du foyer.
     *
     * @return array{recipe: array{id: int, title: string, per_serving: array<string, float|null>}, members: array<int, array<string, mixed>>, meal_type: string, date: string}
     */
    public function preview(User $viewer, Household $household, Recipe $recipe, string $mealType, string $date): array
    {
        $perServing = $recipe->perServing();
        $kcalPerServing = (float) ($perServing['calories'] ?? 0);

        $members = $this->members($household)
            ->map(fn (HouseholdMember $m) => $this->memberPreview($viewer, $m, $kcalPerServing, $recipe, $mealType, $date))
            ->values()
            ->all();

        return [
            'recipe' => [
                'id' => (int) $recipe->id,
                'title' => (string) $recipe->title,
                'per_serving' => $perServing,
            ],
            'meal_type' => $mealType,
            'date' => $date,
            'members' => $members,
        ];
    }

    /**
     * Enregistre un élément de repas (la recette, `portions` portions) chez chaque membre listé.
     *
     * @param  array<int|string, float|int|string>  $portions  user_id → facteur
     * @return array<int, array{user_id: int, meal_id: int, meal_item_id: int, portions: float, calories: float}>
     */
    public function create(User $viewer, Household $household, Recipe $recipe, string $mealType, string $date, array $portions): array
    {
        $members = $this->members($household)->keyBy('user_id');

        // Validation des destinataires avant toute écriture.
        $errors = [];
        foreach ($portions as $userId => $factor) {
            $userId = (int) $userId;
            $member = $members->get($userId);

            if ($member === null) {
                $errors["portions.$userId"] = [self::MSG_NON_MEMBRE];

                continue;
            }

            if ($userId !== (int) $viewer->id && ! $member->share_profile) {
                $name = $member->getRelation('user')->name ?? 'Ce membre';
                $errors["portions.$userId"] = [str_replace(':name', $name, self::MSG_PROFIL_NON_PARTAGE)];
            }
        }

        $others = collect($portions)->keys()->map(fn ($id) => (int) $id)->reject(fn (int $id) => $id === (int) $viewer->id);
        if ($others->isNotEmpty() && ! $recipe->is_public) {
            $errors['recipe_id'] = [self::MSG_RECETTE_PRIVEE];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($portions, $members, $recipe, $mealType, $date) {
            $created = [];

            foreach ($portions as $userId => $factor) {
                /** @var HouseholdMember $member */
                $member = $members->get((int) $userId);
                /** @var User $memberUser */
                $memberUser = $member->getRelation('user');

                $meal = $this->meals->findOrCreate($memberUser, $date, $mealType);
                $meal->setRelation('user', $memberUser);

                [$items] = $this->meals->addItems($meal, [[
                    'recipe_id' => $recipe->id,
                    'quantity' => (float) $factor,
                    'unit' => 'portion',
                ]]);

                $item = $items[0];

                $created[] = [
                    'user_id' => (int) $userId,
                    'meal_id' => (int) $meal->id,
                    'meal_item_id' => (int) $item->id,
                    'portions' => (float) $item->quantity,
                    'calories' => (float) $item->calories,
                ];
            }

            return $created;
        });
    }

    /**
     * Arrondit au pas de 0,5 et borne à [0,5 ; 2,0].
     */
    public static function clampPortions(float $raw): float
    {
        $stepped = round($raw / self::PORTION_STEP) * self::PORTION_STEP;

        return max(self::PORTION_MIN, min(self::PORTION_MAX, $stepped));
    }

    // ------------------------------------------------------------------------------------
    // Internes
    // ------------------------------------------------------------------------------------

    /**
     * Membres du foyer avec utilisateur + profil chargés (pas de lazy loading).
     *
     * @return Collection<int, HouseholdMember>
     */
    private function members(Household $household): Collection
    {
        return HouseholdMember::query()
            ->with(['user.profile', 'user.settings'])
            ->where('household_id', $household->id)
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function memberPreview(User $viewer, HouseholdMember $member, float $kcalPerServing, Recipe $recipe, string $mealType, string $date): array
    {
        /** @var User $user */
        $user = $member->getRelation('user');
        /** @var Profile|null $profile */
        $profile = $user->getRelation('profile');

        $isSelf = (int) $user->id === (int) $viewer->id;
        $shared = $isSelf || (bool) $member->share_profile;
        $isMinor = $profile !== null && $profile->isMinor();
        $complete = $profile !== null && $this->nutrition->isComplete($profile);

        $factors = [];
        $isEstimate = false;
        $target = null;

        if (! $complete) {
            $factors[] = self::FACTEUR_PROFIL_INCOMPLET;
            $isEstimate = true;
            $portions = 1.0;
        } elseif ($shared) {
            $memberDate = $date !== '' ? $date : Clock::today($user);
            $target = $this->budgetFor($user, $memberDate, $mealType);
            $factors[] = self::FACTEUR_BUDGET;
            $portions = $this->portionsFor($target, $kcalPerServing);
        } else {
            $cibles = $this->nutrition->ciblesEffectives($profile, $date);
            $calories = (float) ($cibles['calories'] ?? 0);
            $target = round($calories * $this->budget->share($mealType, $profile->regime_alimentaire));
            $factors[] = self::FACTEUR_NON_PARTAGE;
            $isEstimate = true;
            $portions = $this->portionsFor($target, $kcalPerServing);
        }

        if ($isMinor) {
            $factors[] = self::FACTEUR_MINEUR;
            $isEstimate = true;
        }

        if ((bool) ($recipe->is_estimate ?? false) || ! $recipe->hasMacros()) {
            $factors[] = self::FACTEUR_RECETTE_ESTIMEE;
            $isEstimate = true;
        }

        $label = match (true) {
            $isMinor => self::LABEL_MINEUR,
            ! $complete => self::LABEL_PROFIL_INCOMPLET,
            ! $shared => self::LABEL_ESTIMATION,
            default => self::LABEL_ADAPTEE,
        };

        return [
            'user_id' => (int) $user->id,
            'name' => (string) $user->name,
            'is_me' => $isSelf,
            'share_profile' => (bool) $member->share_profile,
            'is_minor' => $isMinor,
            'target_kcal' => $target === null ? null : (float) round($target),
            'portions' => $portions,
            'calories' => (float) round($portions * $kcalPerServing, 1),
            'is_estimate' => $isEstimate,
            'label' => $label,
            'factors' => $factors,
        ];
    }

    private function portionsFor(float $target, float $kcalPerServing): float
    {
        if ($kcalPerServing <= 0 || $target <= 0) {
            return self::PORTION_MIN;
        }

        return self::clampPortions($target / $kcalPerServing);
    }
}
