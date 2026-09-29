<?php

namespace App\Services\Planner;

use App\Contracts\LlmPlannerClient;
use App\Enums\MealType;
use App\Models\Recipe;
use App\Models\User;
use App\Support\LlmProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Semaine organisée par le modèle selon une contrainte dite en français (« le matin je n'ai pas
 * le temps de cuisiner »).
 *
 * Ce service ne planifie rien : il propose à PlannerGenerator une recette par créneau, et le
 * générateur reste seul à écrire. Trois garde-fous le tiennent :
 *  - le modèle ne choisit que dans l'ensemble que les règles retiendraient déjà, et ne renvoie
 *    que des identifiants — il n'invente aucun plat et aucun texte qu'on planifierait tel quel ;
 *  - tout identifiant hors de cet ensemble est rejeté ici, puis re-vérifié par le générateur ;
 *  - dès que quoi que ce soit manque ou casse, les règles Mavi'oh reprennent la semaine entière,
 *    et `generated_by` le dit.
 */
class PlannerAiComposer
{
    public const WARNING_FALLBACK = 'Organisation par l’IA indisponible : semaine composée par les règles Mavi’oh.';

    public const WARNING_SANS_IA = 'Ta demande n’a pas été interprétée : la semaine a été composée par les règles Mavi’oh, sans IA.';

    /** Au-delà, le contexte devient trop long pour un petit modèle local. */
    private const MAX_RECETTES = 60;

    private const JOURS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    public function __construct(
        private readonly LlmPlannerClient $client,
        private readonly PlannerGenerator $generator,
        private readonly WeekProposalSchema $schema,
    ) {
    }

    /** Un modèle est branché ET l'offre du compte donne droit à l'IA. */
    public function iaDisponible(User $user): bool
    {
        return LlmProvider::isConfigured() && $user->peut('ia');
    }

    /**
     * @param  list<string>  $mealTypes
     * @return array{choix: array<string, int>|null, generated_by: string, warnings: list<string>, explication: list<string>}
     */
    public function compose(User $user, string $weekStart, array $mealTypes, bool $replace, string $demande): array
    {
        $demande = trim($demande);

        // Sans demande, rien ne change : c'est la garantie de non-régression, et l'application
        // mobile en dépend. Le modèle n'est même pas sollicité.
        if ($demande === '') {
            return $this->parRegles();
        }

        if (! $this->iaDisponible($user)) {
            return $this->parRegles([self::WARNING_SANS_IA]);
        }

        $slots = $this->generator->freeSlots($user, $weekStart, $mealTypes, $replace);

        if ($slots === []) {
            return $this->parRegles();
        }

        $parType = $this->recettesParType($user, $mealTypes);
        $autorise = $this->autorise($slots, $parType);

        // Aucune recette éligible : les règles basculeront sur les idées de repas, que le modèle
        // n'a pas le droit de choisir. Inutile de le déranger.
        if ($autorise === []) {
            return $this->parRegles();
        }

        try {
            $brut = $this->client->composeWeek(
                $demande,
                $this->contexte($user, $slots, $parType),
                WeekProposalSchema::json(),
            );
            $propre = $this->schema->normalize($brut, $autorise);
        } catch (Throwable $e) {
            Log::warning('Organisation IA de la semaine indisponible : repli sur les règles Mavi’oh.', [
                'exception' => get_class($e),
                'message' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return $this->parRegles([self::WARNING_FALLBACK]);
        }

        // Le modèle a pu proposer des plats qu'on a écartés — recette d'un autre compte, allergène,
        // type de repas qui ne correspond pas. Ces créneaux-là sont composés par les règles :
        // annoncer « composée par l'IA » sans le dire laisserait croire que la demande a été
        // suivie partout, alors qu'elle ne l'a été qu'en partie.
        $warnings = [];

        if ($propre['rejets'] > 0) {
            $warnings[] = $propre['rejets'] === 1
                ? 'Une proposition du modèle a été écartée : ce repas a été composé par les règles Mavi’oh.'
                : $propre['rejets'].' propositions du modèle ont été écartées : ces repas ont été composés par les règles Mavi’oh.';
        }

        return [
            'choix' => $propre['choix'],
            'generated_by' => 'ia',
            'warnings' => $warnings,
            'explication' => $propre['explication'],
        ];
    }

    // ------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $warnings
     * @return array{choix: null, generated_by: string, warnings: list<string>, explication: list<string>}
     */
    private function parRegles(array $warnings = []): array
    {
        return ['choix' => null, 'generated_by' => 'regles', 'warnings' => $warnings, 'explication' => []];
    }

    /**
     * Recettes soumises au modèle, par type de repas : les mieux classées par les règles, et un
     * quota égal par type pour qu'un dîner ne mange pas toute la place d'un petit-déjeuner.
     *
     * @param  list<string>  $mealTypes
     * @return array<string, list<Recipe>>
     */
    private function recettesParType(User $user, array $mealTypes): array
    {
        $parType = $this->generator->candidatesByType($user, $mealTypes);
        $quota = max(1, intdiv(self::MAX_RECETTES, max(1, count($parType))));

        return array_map(fn (array $liste) => array_slice($liste, 0, $quota), $parType);
    }

    /**
     * Identifiants permis sur chaque créneau libre. Les créneaux sans aucune recette éligible
     * n'y figurent pas : le modèle n'a rien à y proposer, les règles s'en occuperont.
     *
     * @param  list<array{date: string, meal_type: string}>  $slots
     * @param  array<string, list<Recipe>>  $parType
     * @return array<string, list<int>>
     */
    private function autorise(array $slots, array $parType): array
    {
        $ids = array_map(
            fn (array $liste) => array_map(fn (Recipe $recipe) => (int) $recipe->id, $liste),
            $parType,
        );

        $autorise = [];
        foreach ($slots as $slot) {
            $permis = $ids[$slot['meal_type']] ?? [];

            if ($permis !== []) {
                $autorise[$slot['date'].'|'.$slot['meal_type']] = $permis;
            }
        }

        return $autorise;
    }

    /**
     * Contexte anonymisé : ni nom, ni adresse, ni identifiant de compte.
     *
     * @param  list<array{date: string, meal_type: string}>  $slots
     * @param  array<string, list<Recipe>>  $parType
     * @return array<string, mixed>
     */
    private function contexte(User $user, array $slots, array $parType): array
    {
        $profile = $user->profile()->first();
        $libelles = MealType::labels();

        $creneaux = [];
        foreach ($slots as $slot) {
            if (($parType[$slot['meal_type']] ?? []) === []) {
                continue;
            }

            $creneaux[] = [
                'date' => $slot['date'],
                'jour' => self::JOURS[CarbonImmutable::parse($slot['date'])->dayOfWeekIso] ?? '',
                'repas' => $slot['meal_type'],
                'libelle_repas' => $libelles[$slot['meal_type']] ?? $slot['meal_type'],
            ];
        }

        // Une recette peut servir plusieurs types de repas : on la décrit une fois, en disant sur
        // quels créneaux elle est permise, plutôt que de la répéter.
        $repasPermis = [];
        $recettes = [];
        foreach ($parType as $type => $liste) {
            foreach ($liste as $recipe) {
                $id = (int) $recipe->id;
                $repasPermis[$id][] = $type;

                $recettes[$id] ??= [
                    'id' => $id,
                    'titre' => (string) $recipe->title,
                    'calories_par_portion' => (float) $recipe->perServing()['calories'],
                    'temps_preparation_min' => $recipe->prep_time_minutes,
                    'tags' => is_array($recipe->tags) ? array_values($recipe->tags) : [],
                ];
            }
        }

        foreach ($recettes as $id => $recette) {
            $recettes[$id]['repas'] = $repasPermis[$id];
        }

        return [
            'creneaux' => $creneaux,
            'recettes_disponibles' => array_values($recettes),
            'regime_alimentaire' => $profile?->regime_alimentaire,
            'allergenes' => array_values((array) ($profile?->allergenes ?? [])),
            'aliments_exclus' => array_values((array) ($profile?->aliments_exclus ?? [])),
            'objectif' => $profile?->objectif_type,
        ];
    }
}
