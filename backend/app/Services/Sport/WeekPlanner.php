<?php

namespace App\Services\Sport;

use App\Contracts\LlmWorkoutClient;
use App\Models\Profile;
use App\Models\Sport;
use App\Models\SportPlan;
use App\Models\User;
use App\Models\WorkoutSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Planification hebdomadaire (addendum §C.2 plan-week) : propose sport + durée + focus par jour choisi
 * et crée les `sport_plans` (status prevu, notes = focus). Mode « ia » (un appel, schéma semaine)
 * avec repli sur les règles : alternance selon l'objectif sportif, durées du profil, sports de l'historique.
 */
class WeekPlanner
{
    public const WARNING_FALLBACK = 'Planification IA indisponible : semaine proposée par les règles Mavi’oh.';

    /** Répartition par défaut des jours d'entraînement selon `sport_jours_semaine`. */
    private const SPREAD = [
        1 => [3],
        2 => [2, 5],
        3 => [1, 3, 5],
        4 => [1, 2, 4, 6],
        5 => [1, 2, 3, 5, 6],
        6 => [1, 2, 3, 4, 5, 6],
        7 => [1, 2, 3, 4, 5, 6, 7],
    ];

    public function __construct(
        private LlmWorkoutClient $client,
        private WorkoutContextBuilder $context,
        private WorkoutProposalSchema $schema,
        private WorkoutAiGenerator $ai,
    ) {
    }

    /**
     * @param  list<int>|null  $days  1 = lundi … 7 = dimanche ; null = jours déjà planifiés, sinon répartition du profil
     * @return array{plans: Collection<int, SportPlan>, generated_by: string, warnings: list<string>, explication: list<string>, days: list<int>}
     */
    public function plan(User $user, string $weekStart, ?array $days, string $mode, bool $replace): array
    {
        $profile = $user->relationLoaded('profile')
            ? $user->getRelation('profile')
            : Profile::query()->where('user_id', $user->id)->first();

        $start = CarbonImmutable::parse($weekStart);
        $end = $start->addDays(6);

        $existing = SportPlan::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', 'annule')
            ->get();

        $derived = $days === null;
        if ($days === null) {
            $days = $existing->map(fn (SportPlan $p) => $p->date->dayOfWeekIso)->unique()->sort()->values()->all();
        }
        if ($days === []) {
            $n = max(1, min(7, (int) ($profile?->sport_jours_semaine ?? 3)));
            $days = self::SPREAD[$n];
        }
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);

        // Sans `days`, les plans existants sont les créneaux à remplir : on les remplace.
        $replace = $replace || $derived;

        $warnings = [];
        $explication = [];
        $generatedBy = 'regles';
        $entries = null;

        if ($mode === 'ia' && $this->ai->iaDisponible($user)) {
            try {
                $request = [
                    'week_start' => $start->toDateString(),
                    'jours' => $days,
                    'objectif' => $profile?->sport_objectif,
                    'niveau' => $profile?->sport_niveau,
                    'temps_dispo_min' => $profile?->sport_temps_dispo_min,
                    'lieu' => $profile?->sport_lieu,
                    'sports_disponibles' => Sport::query()->visibleTo($user)->orderBy('name')->pluck('name')->take(60)->values()->all(),
                ];
                $raw = $this->client->generateWeekPlan($this->context->build($user, $request, $profile), $request);
                $week = $this->schema->normalizeWeek($raw, $days);
                $entries = $this->entriesFromIa($user, $week['days'], $profile);
                $explication = $week['explication'];
                $generatedBy = 'ia';
            } catch (Throwable $e) {
                Log::warning('Planification IA indisponible : repli sur les règles Mavi’oh.', [
                    'exception' => get_class($e),
                    'message' => mb_substr($e->getMessage(), 0, 200),
                ]);
                $warnings[] = self::WARNING_FALLBACK;
                $entries = null;
            }
        }

        if ($entries === null) {
            [$entries, $explication] = $this->entriesFromRules($user, $days, $profile);
        }

        $plans = DB::transaction(function () use ($user, $start, $end, $entries, $existing, $replace) {
            $skipDays = [];
            if ($replace) {
                SportPlan::query()
                    ->where('user_id', $user->id)
                    ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->where('status', 'prevu')
                    ->whereNull('session_id')
                    ->delete();
            } else {
                $skipDays = $existing->where('status', 'prevu')->map(fn (SportPlan $p) => $p->date->dayOfWeekIso)->unique()->all();
            }

            $created = collect();
            foreach ($entries as $entry) {
                if (in_array($entry['weekday'], $skipDays, true)) {
                    continue;
                }
                $created->push(SportPlan::query()->create([
                    'user_id' => $user->id,
                    'date' => $start->addDays($entry['weekday'] - 1)->toDateString(),
                    'sport_id' => $entry['sport_id'],
                    'sport_name' => $entry['sport_name'],
                    'planned_duration_min' => $entry['duration_min'],
                    'planned_at' => null,
                    'lieu' => $entry['lieu'],
                    'notes' => $entry['note'],
                    'status' => 'prevu',
                ]));
            }

            return $created;
        });

        return [
            'plans' => $plans,
            'generated_by' => $generatedBy,
            'warnings' => $warnings,
            'explication' => $explication,
            'days' => $days,
        ];
    }

    // ------------------------------------------------------------------------------------

    /**
     * @param  list<int>  $days
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function entriesFromRules(User $user, array $days, ?Profile $profile): array
    {
        $objective = $profile?->sport_objectif ?? 'forme';
        $duration = max(10, min(180, (int) ($profile?->sport_temps_dispo_min ?? 30)));
        $lieu = $profile?->sport_lieu ?? 'maison';

        [$forceSport, $cardioSport, $softSport] = $this->preferredSports($user, $lieu);

        $force = fn (string $focus, string $label) => ['sport' => $forceSport, 'duration' => $duration, 'focus' => $focus, 'note' => 'Focus : '.$label];
        $cardio = fn (string $focus, string $label) => ['sport' => $cardioSport, 'duration' => $duration, 'focus' => $focus, 'note' => 'Focus : '.$label];
        $soft = ['sport' => $softSport, 'duration' => min($duration, 30), 'focus' => 'mobilite', 'note' => 'Focus : mobilité et récupération'];

        $pattern = match ($objective) {
            'perte_de_gras' => [$cardio('cardio', 'cardio'), $force('perte_de_gras', 'corps entier')],
            'prise_de_muscle' => [$force('haut_du_corps', 'haut du corps'), $force('bas_du_corps', 'bas du corps'), $cardio('cardio', 'cardio léger'), $force('haut_du_corps', 'haut du corps'), $force('bas_du_corps', 'bas du corps'), $force('gainage', 'gainage'), $force('jambes', 'jambes')],
            'endurance' => [$cardio('endurance', 'endurance'), $cardio('endurance', 'endurance'), $force('gainage', 'renforcement et gainage')],
            'force' => [$force('haut_du_corps', 'haut du corps'), $force('bas_du_corps', 'bas du corps'), $force('force', 'corps entier'), $cardio('cardio', 'cardio de récupération')],
            default => [$force('forme', 'corps entier'), $cardio('cardio', 'cardio'), $soft],
        };

        $entries = [];
        foreach (array_values($days) as $i => $weekday) {
            $step = $pattern[$i % count($pattern)];
            $entries[] = [
                'weekday' => $weekday,
                'sport_id' => $step['sport']['id'],
                'sport_name' => $step['sport']['name'],
                'duration_min' => $step['duration'],
                'lieu' => $lieu,
                'note' => $step['note'],
            ];
        }

        $explication = [
            sprintf('Objectif « %s » : %s.', SportVocab::OBJECTIFS[$objective] ?? 'forme', match ($objective) {
                'perte_de_gras' => 'alternance cardio / renforcement pour dépenser sans t’épuiser',
                'prise_de_muscle' => 'renforcement haut / bas du corps en alternance et une séance cardio légère',
                'endurance' => 'deux séances cardio pour une de renforcement et gainage',
                'force' => 'renforcement haut, bas puis corps entier, et un cardio de récupération',
                default => 'un mélange renforcement, cardio et mobilité pour rester en forme',
            }),
            sprintf('Durées basées sur ton temps disponible (%d min) ; les sports viennent de ton historique ou de ton lieu habituel (%s).', $duration, $lieu),
            'Semaine indicative : adapte-la à ta fatigue et à ton emploi du temps.',
        ];

        return [$entries, $explication];
    }

    /**
     * @param  list<array{weekday: int, sport: string, duration_min: int, focus: string|null, lieu: string|null, note: string|null}>  $days
     * @return list<array<string, mixed>>
     */
    private function entriesFromIa(User $user, array $days, ?Profile $profile): array
    {
        $sports = Sport::query()->visibleTo($user)->get(['id', 'name', 'slug']);
        $lieuDefault = $profile?->sport_lieu ?? 'maison';

        $entries = [];
        foreach ($days as $day) {
            $slug = Str::slug($day['sport']);
            $match = $sports->first(fn (Sport $s) => preg_replace('/-u\d+$/', '', (string) $s->slug) === $slug)
                ?? $sports->first(fn (Sport $s) => str_contains(Str::slug($s->name), $slug) || str_contains($slug, Str::slug($s->name)));

            $entries[] = [
                'weekday' => $day['weekday'],
                'sport_id' => $match?->id,
                'sport_name' => $match?->name ?? $day['sport'],
                'duration_min' => $day['duration_min'],
                'lieu' => $day['lieu'] ?? $lieuDefault,
                'note' => $day['note'] ?? ($day['focus'] !== null ? 'Focus : '.$day['focus'] : null),
            ];
        }

        return $entries;
    }

    /**
     * Sports préférés [force, cardio, doux] : les plus pratiqués dans l'historique, sinon défauts selon le lieu.
     *
     * @return array{0: array{id: int|null, name: string}, 1: array{id: int|null, name: string}, 2: array{id: int|null, name: string}}
     */
    private function preferredSports(User $user, string $lieu): array
    {
        $usage = WorkoutSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('sport_id')
            ->where('status', 'terminee')
            ->selectRaw('sport_id, COUNT(*) as uses')
            ->groupBy('sport_id')
            ->orderByDesc('uses')
            ->pluck('uses', 'sport_id');

        $used = $usage->isEmpty() ? collect() : Sport::query()->whereIn('id', $usage->keys()->all())->get()->keyBy('id');

        $force = null;
        $cardio = null;
        foreach ($usage->keys() as $sportId) {
            $sport = $used->get($sportId);
            if ($sport === null) {
                continue;
            }
            if ($sport->category === 'force' && $force === null) {
                $force = ['id' => $sport->id, 'name' => $sport->name];
            } elseif (! in_array($sport->category, ['force', 'bien_etre'], true) && $cardio === null) {
                $cardio = ['id' => $sport->id, 'name' => $sport->name];
            }
        }

        $bySlug = fn (string $slug, string $fallback): array => (function () use ($slug, $fallback) {
            $sport = Sport::query()->where('slug', $slug)->where('is_public', true)->first(['id', 'name']);

            return ['id' => $sport?->id, 'name' => $sport?->name ?? $fallback];
        })();

        $force ??= $bySlug('musculation', 'Musculation');
        $cardio ??= match ($lieu) {
            'exterieur' => $bySlug('course-a-pied', 'Course à pied'),
            'maison' => $bySlug('marche', 'Marche'),
            default => $bySlug('course-a-pied', 'Course à pied'),
        };
        $soft = $bySlug('yoga', 'Yoga');

        return [$force, $cardio, $soft];
    }
}
