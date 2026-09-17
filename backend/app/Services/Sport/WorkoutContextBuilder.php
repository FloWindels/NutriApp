<?php

namespace App\Services\Sport;

use App\Enums\ExerciseLevel;
use App\Models\Exercise;
use App\Models\Profile;
use App\Models\SportPlan;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\NutritionCalculator;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Contexte JSON anonymisé transmis au coach IA (addendum §C.4) : profil, préférences sportives,
 * historique 14 jours, plan du jour, et catalogue d'exercices filtré (≤ 60). Jamais de nom ni d'e-mail.
 */
class WorkoutContextBuilder
{
    public const CATALOG_MAX = 60;

    public const HISTORY_DAYS = 14;

    /** Quotas indicatifs par catégorie dans le catalogue envoyé (complétés jusqu'à CATALOG_MAX). */
    private const QUOTAS = ['force' => 30, 'cardio' => 12, 'gainage' => 8, 'mobilite' => 10];

    public function __construct(
        private NutritionCalculator $nutrition,
        private CaloriesEstimator $calories,
    ) {
    }

    /**
     * @param  array<string, mixed>  $request  Demande résolue
     * @return array<string, mixed>  {profil, sport, historique_14j, plan_du_jour}
     */
    public function build(User $user, array $request, ?Profile $profile, ?SportPlan $plan = null): array
    {
        $today = Clock::today($user);
        $date = $plan?->date?->format('Y-m-d') ?? $today;
        $weight = $this->calories->weightFor($user, $date);

        $imc = null;
        if ($profile !== null && $profile->taille && (float) $profile->taille > 0) {
            $imc = round($weight / (((float) $profile->taille / 100) ** 2), 1);
        }

        $cibles = $profile !== null ? $this->nutrition->ciblesEffectives($profile, $today) : null;

        $profil = [
            'age' => $profile?->age,
            'sexe' => $profile?->sexe,
            'poids_kg' => $weight,
            'taille_cm' => $profile?->taille,
            'imc' => $imc,
            'niveau_activite' => $profile?->niveau_activite,
            'objectif_type' => $profile?->objectif_type,
            'calories_cibles' => $cibles['calories'] ?? null,
            'regime' => $profile?->regime_alimentaire,
            'profil_mineur' => $profile?->isMinor() ?? false,
            'situation_particuliere' => $profile?->situation_particuliere ?? 'aucune',
        ];

        $sport = [
            'niveau' => $profile?->sport_niveau,
            'objectif' => $profile?->sport_objectif,
            'materiel' => $profile?->sport_materiel ?? [],
            'lieu' => $profile?->sport_lieu,
            'zones_a_eviter' => $profile?->sport_zones_a_eviter ?? [],
            'focus' => $profile?->sport_focus ?? [],
            'notes' => $profile?->sport_notes,
            'jours_semaine' => $profile?->sport_jours_semaine,
            'temps_dispo_min' => $profile?->sport_temps_dispo_min,
        ];

        $history = WorkoutSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'terminee')
            ->whereBetween('date', [CarbonImmutable::parse($today)->subDays(self::HISTORY_DAYS)->toDateString(), $today])
            ->orderByDesc('date')
            ->limit(30)
            ->get(['id', 'date', 'title', 'sport_name', 'duration_min', 'rpe', 'calories_burned'])
            ->map(fn (WorkoutSession $s) => [
                'date' => $s->date?->format('Y-m-d'),
                'sport' => $s->sport_name ?? $s->title,
                'duree_min' => $s->duration_min,
                'rpe' => $s->rpe,
                'calories' => $s->calories_burned,
            ])
            ->values()
            ->all();

        $plan ??= SportPlan::query()
            ->where('user_id', $user->id)
            ->where('date', $today)
            ->where('status', 'prevu')
            ->orderByRaw('planned_at IS NULL')
            ->orderBy('planned_at')
            ->first();

        return [
            'profil' => $profil,
            'sport' => $sport,
            'historique_14j' => $history,
            'plan_du_jour' => $plan === null ? null : [
                'sport' => $plan->sport_name,
                'duree' => $plan->planned_duration_min,
                'heure' => $plan->planned_at ? substr((string) $plan->planned_at, 0, 5) : null,
                'lieu' => $plan->lieu,
            ],
        ];
    }

    /**
     * Exercices candidats (modèles) : matériel strict, niveau ≤ demandé, zones à éviter exclues.
     *
     * @param  array<string, mixed>  $request
     * @return Collection<int, Exercise>
     */
    public function candidates(array $request): Collection
    {
        $equipment = array_values(array_unique(array_merge((array) ($request['equipment'] ?? []), ['aucun'])));
        $rank = ExerciseLevel::tryFrom((string) ($request['level'] ?? ''))?->rank() ?? ExerciseLevel::Debutant->rank();
        $zones = array_values((array) ($request['zones_a_eviter'] ?? []));
        $beginner = ($request['level'] ?? 'debutant') === 'debutant';

        return Exercise::query()
            ->public()
            ->whereIn('equipment', $equipment)
            ->orderBy('id')
            ->get()
            ->filter(fn (Exercise $e) => (ExerciseLevel::tryFrom((string) $e->level)?->rank() ?? 1) <= $rank
                && ! $e->hitsZones($zones)
                && ! ($beginner && in_array($e->slug, WorkoutGenerator::FORBIDDEN_FOR_BEGINNERS, true)))
            ->values();
    }

    /**
     * Catalogue envoyé au LLM (≤ 60 lignes), équilibré par catégorie et priorisant le focus.
     *
     * @param  array<string, mixed>  $request
     * @return list<array{exercise_id: int, name: string, category: string, muscle_group: string, equipment: string, level: string, met: float}>
     */
    public function catalog(array $request, ?Collection $candidates = null): array
    {
        $candidates ??= $this->candidates($request);
        $muscles = SportVocab::musclesForFocus((array) ($request['focus'] ?? []));
        $cardioType = SportVocab::isCardioType($request['sport_type'] ?? null);

        $byCategory = $candidates->groupBy('category');
        $picked = collect();

        foreach (self::QUOTAS as $category => $quota) {
            /** @var Collection<int, Exercise> $group */
            $group = $byCategory->get($category, collect());
            if ($category === 'force' && $muscles !== []) {
                $group = $group->sortBy(fn (Exercise $e) => in_array($e->muscle_group, $muscles, true) ? 0 : 1)->values();
            }
            if ($cardioType && $category === 'force') {
                $quota = 10;
            }
            $picked = $picked->merge($group->take($quota));
        }

        if ($picked->count() < self::CATALOG_MAX) {
            $ids = $picked->pluck('id')->all();
            $picked = $picked->merge($candidates->reject(fn (Exercise $e) => in_array($e->id, $ids, true))->take(self::CATALOG_MAX - $picked->count()));
        }

        return $picked
            ->take(self::CATALOG_MAX)
            ->sortBy('id')
            ->values()
            ->map(fn (Exercise $e) => [
                'exercise_id' => (int) $e->id,
                'name' => $e->name,
                'category' => $e->category,
                'muscle_group' => $e->muscle_group,
                'equipment' => $e->equipment,
                'level' => $e->level,
                'met' => (float) $e->met,
            ])
            ->all();
    }
}
