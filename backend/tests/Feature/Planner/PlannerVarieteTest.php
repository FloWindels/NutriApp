<?php

namespace Tests\Feature\Planner;

use App\Models\MealPlan;
use App\Models\User;
use App\Services\Planner\MealVariety;
use App\Services\Planner\PlanCalories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « La génération des repas de la semaine est très répétitive. »
 *
 * Ce que le planificateur doit désormais garantir : pas deux fois le même plat dans la semaine
 * tant qu'il reste de quoi choisir, pas deux jours de suite le même ingrédient principal, deux
 * semaines d'affilée qui ne se ressemblent pas — et malgré tout un résultat reproductible à
 * l'identique, sans aucun tirage au sort.
 */
class PlannerVarieteTest extends TestCase
{
    use ModuleM7Helpers;
    use RefreshDatabase;

    /**
     * Titres des plans de la semaine commençant $offset jours après le lundi courant.
     *
     * @return list<string>
     */
    private function titres(User $user, int $offset = 0): array
    {
        return MealPlan::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$this->weekDay($user, $offset), $this->weekDay($user, $offset + 6)])
            ->orderBy('date')
            ->orderBy('meal_type')
            ->pluck('title')
            ->all();
    }

    /**
     * Famille du plat telle que le planificateur la calcule : titre + ingrédients de l'idée.
     */
    private function famille(string $titre): ?string
    {
        $idee = PlanCalories::ideaForTitle($titre);

        return MealVariety::famille($titre, array_merge(
            (array) ($idee['ingredients'] ?? []),
            (array) ($idee['allergenes'] ?? []),
        ));
    }

    public function test_une_semaine_complete_ne_sert_jamais_deux_fois_le_meme_plat(): void
    {
        $user = $this->login($this->userWithProfile());

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner', 'diner']])
            ->assertOk()
            ->assertJsonPath('generated_count', 14);

        $titres = $this->titres($user);
        $this->assertCount(14, $titres);
        $this->assertCount(14, array_unique($titres), 'Quatorze créneaux, quatorze plats différents.');
    }

    public function test_deux_jours_de_suite_n_ont_pas_le_meme_ingredient_principal(): void
    {
        $user = $this->login($this->userWithProfile());

        $this->postJson('/api/planner/generate', ['meal_types' => ['diner']])->assertOk();

        $familles = array_map(fn (string $titre) => $this->famille($titre), $this->titres($user));
        $this->assertCount(7, $familles);

        for ($i = 1; $i < count($familles); $i++) {
            if ($familles[$i] === null) {
                continue;
            }

            $this->assertNotSame(
                $familles[$i - 1],
                $familles[$i],
                'Deux dîners de suite autour de « '.$familles[$i].' ».'
            );
        }
    }

    public function test_deux_semaines_consecutives_ne_donnent_pas_la_meme_suite(): void
    {
        $user = $this->login($this->userWithProfile());

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner']])->assertOk();
        $semaine1 = $this->titres($user, 0);

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner'], 'week_start' => $this->weekDay($user, 7)])
            ->assertOk()
            ->assertJsonPath('generated_count', 7);
        $semaine2 = $this->titres($user, 7);

        $this->assertCount(7, $semaine2);
        $this->assertNotSame($semaine1, $semaine2);
        $this->assertSame([], array_values(array_intersect($semaine1, $semaine2)), 'Le vivier est assez large pour ne rien reprendre de la semaine passée.');
    }

    public function test_la_generation_reste_reproductible_pour_une_meme_personne_et_une_meme_semaine(): void
    {
        $user = $this->login($this->userWithProfile());
        $semaine = $this->weekDay($user, 7);

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner', 'diner'], 'week_start' => $semaine])->assertOk();
        $premier = $this->titres($user, 7);

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner', 'diner'], 'week_start' => $semaine, 'replace' => true])->assertOk();

        $this->assertSame($premier, $this->titres($user, 7));
    }

    public function test_le_vivier_epuise_la_generation_repete_plutot_que_de_laisser_des_trous(): void
    {
        // Aucune idée de repli : il ne reste que deux recettes pour sept dîners.
        config()->set('meal_ideas', []);

        $user = $this->login($this->userWithProfile());
        $a = $this->publicRecipe(['title' => 'Plat A', 'calories' => 600, 'meal_types' => ['diner']]);
        $b = $this->publicRecipe(['title' => 'Plat B', 'calories' => 640, 'meal_types' => ['diner']]);

        $this->postJson('/api/planner/generate', ['meal_types' => ['diner']])
            ->assertOk()
            ->assertJsonPath('generated_count', 7);

        $plans = MealPlan::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$this->weekDay($user, 0), $this->weekDay($user, 6)])
            ->orderBy('date')
            ->get();

        $this->assertCount(7, $plans, 'Mieux vaut une semaine qui se répète qu’une semaine trouée.');
        $this->assertSame(
            [$a->id, $b->id, $a->id, $b->id, $a->id, $b->id, $a->id],
            $plans->pluck('recipe_id')->all(),
            'La répétition reprend le plat le plus ancien.'
        );
    }

    public function test_une_recette_et_une_idee_du_meme_nom_comptent_pour_un_seul_plat(): void
    {
        $user = $this->login($this->userWithProfile());

        $titre = 'Chili végétarien aux haricots rouges et riz'; // existe aussi dans meal_ideas
        $this->publicRecipe(['title' => $titre, 'calories' => 700, 'meal_types' => ['dejeuner']]);

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner']])->assertOk();

        $titres = $this->titres($user);
        $this->assertSame(1, count(array_keys($titres, $titre, true)), 'Le même plat, qu’il vienne d’une recette ou d’une idée, ne passe qu’une fois.');
    }

    public function test_une_recette_servie_la_semaine_passee_change_de_jour(): void
    {
        $user = $this->login($this->userWithProfile());
        $titre = 'Ma grande recette du dimanche';
        $this->publicRecipe(['title' => $titre, 'calories' => 700, 'meal_types' => ['dejeuner']]);

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner']])->assertOk();
        $semaine1 = $this->titres($user, 0);
        $this->assertSame($titre, $semaine1[0], 'La seule recette du vivier ouvre la semaine.');

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner'], 'week_start' => $this->weekDay($user, 7)])->assertOk();
        $semaine2 = $this->titres($user, 7);

        $this->assertNotSame($titre, $semaine2[0], 'Sept jours plus tard, le même lundi reviendrait au même plat.');
        $this->assertContains($titre, $semaine2, 'Elle reste au menu de la semaine : elle change seulement de jour.');
    }

    public function test_les_allergenes_des_idees_sont_filtres_meme_absents_du_titre(): void
    {
        $user = $this->login($this->userWithProfile(['allergenes' => ['sésame']]));

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner', 'diner']])->assertOk();

        foreach ($this->titres($user) as $titre) {
            $idee = PlanCalories::ideaForTitle($titre);
            $this->assertNotNull($idee);
            $this->assertNotContains('sésame', $idee['allergenes'], 'Plat au sésame proposé à qui y est allergique : '.$titre);
        }
    }
}
