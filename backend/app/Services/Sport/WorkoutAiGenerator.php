<?php

namespace App\Services\Sport;

use App\Contracts\LlmWorkoutClient;
use App\Enums\Equipment;
use App\Enums\ExerciseLevel;
use App\Enums\Lieu;
use App\Models\Profile;
use App\Models\Sport;
use App\Models\SportPlan;
use App\Models\User;
use App\Models\UserSetting;
use App\Support\Clock;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestration de la génération de séance (addendum §C.4) :
 * mode « ia » quand une clé Anthropic est configurée ET que l'utilisateur a activé « Séances proposées
 * par l'IA » ET que la demande ne force pas « regles » ; toute exception (LlmUnavailableException,
 * refus, JSON invalide…) bascule sur le générateur par règles avec un avertissement.
 */
class WorkoutAiGenerator
{
    public const WARNING_FALLBACK = 'Génération IA indisponible : séance proposée par les règles Mavi’oh.';

    /** Matériel présumé en salle quand le profil n'en précise pas. */
    public const GYM_EQUIPMENT = ['machine', 'barre', 'halteres', 'banc', 'tapis', 'velo', 'barre_traction', 'kettlebell', 'elastiques', 'aucun'];

    public function __construct(
        private LlmWorkoutClient $client,
        private WorkoutGenerator $rules,
        private WorkoutContextBuilder $context,
        private WorkoutProposalSchema $schema,
        private CaloriesEstimator $calories,
    ) {
    }

    public function iaConfigured(): bool
    {
        return filled(config('services.anthropic.api_key'));
    }

    /**
     * Clé configurée ET opt-in utilisateur (user_settings.ia_seances, défaut vrai).
     */
    public function iaDisponible(User $user): bool
    {
        if (! $this->iaConfigured()) {
            return false;
        }

        $optIn = $user->relationLoaded('settings')
            ? $user->getRelation('settings')?->ia_seances
            : UserSetting::query()->where('user_id', $user->id)->value('ia_seances');

        return $optIn === null ? true : (bool) $optIn;
    }

    public function llmModel(): ?string
    {
        return $this->iaConfigured() ? (string) config('services.anthropic.model') : null;
    }

    /**
     * Complète la demande avec les défauts du plan puis du profil (addendum §C.4).
     *
     * @param  array<string, mixed>  $input  Corps validé
     * @return array<string, mixed>
     */
    public function resolveRequest(array $input, ?Profile $profile, ?SportPlan $plan = null): array
    {
        $sport = null;
        $sportId = $input['sport_id'] ?? $plan?->sport_id;
        if ($sportId !== null) {
            $sport = $plan !== null && $plan->relationLoaded('sport') && (int) $plan->sport_id === (int) $sportId
                ? $plan->getRelation('sport')
                : Sport::query()->find($sportId);
        }

        $sportType = $input['sport_type'] ?? null;
        if ($sportType === null) {
            $sportType = $sport !== null
                ? SportVocab::sportTypeFor($sport)
                : ($plan?->sport_name ? SportVocab::sportTypeFor($plan->sport_name) : 'musculation');
        }

        $lieu = $input['lieu'] ?? $plan?->lieu ?? $profile?->sport_lieu ?? 'maison';
        $lieu = in_array($lieu, Lieu::values(), true) ? $lieu : 'maison';

        $equipment = $input['equipment'] ?? $profile?->sport_materiel;
        if ($equipment === null || $equipment === []) {
            $equipment = in_array($lieu, ['salle_publique', 'salle_privee'], true) ? self::GYM_EQUIPMENT : ['aucun'];
        }
        $equipment = array_values(array_unique(array_intersect((array) $equipment, Equipment::values())));
        if (! in_array('aucun', $equipment, true)) {
            $equipment[] = 'aucun';
        }
        sort($equipment);

        $level = $input['level'] ?? $profile?->sport_niveau ?? 'debutant';
        $goal = $input['goal'] ?? $profile?->sport_objectif ?? 'forme';

        return [
            'mode' => $input['mode'] ?? 'ia',
            'goal' => in_array($goal, array_keys(SportVocab::OBJECTIFS), true) ? $goal : 'forme',
            'level' => in_array($level, ExerciseLevel::values(), true) ? $level : 'debutant',
            'duration_min' => max(10, min(180, (int) ($input['duration_min'] ?? $plan?->planned_duration_min ?? $profile?->sport_temps_dispo_min ?? 30))),
            'sport_type' => $sportType,
            'sport_id' => $sport?->id,
            'sport_name' => $sport?->name ?? $plan?->sport_name,
            'lieu' => $lieu,
            'equipment' => $equipment,
            'focus' => array_values(array_intersect((array) ($input['focus'] ?? $profile?->sport_focus ?? []), SportVocab::focusValues())),
            'zones_a_eviter' => array_values(array_intersect((array) ($input['zones_a_eviter'] ?? $profile?->sport_zones_a_eviter ?? []), array_keys(SportVocab::ZONES))),
            'notes' => isset($input['notes']) ? (trim((string) $input['notes']) ?: null) : ($profile?->sport_notes ?: null),
            'seed' => (int) ($input['seed'] ?? 0),
            'planned_at' => $plan?->planned_at ? substr((string) $plan->planned_at, 0, 5) : null,
            'sport_plan_id' => $plan?->id,
            'date' => $plan?->date?->format('Y-m-d'),
        ];
    }

    /**
     * Génère une proposition (non persistée) au format addendum §C.4.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function generate(User $user, array $input, ?SportPlan $plan = null): array
    {
        $profile = $user->relationLoaded('profile')
            ? $user->getRelation('profile')
            : Profile::query()->where('user_id', $user->id)->first();

        $request = $this->resolveRequest($input, $profile, $plan);
        $date = $request['date'] ?? Clock::today($user);
        $weight = $this->calories->weightFor($user, $date);
        $context = $this->context->build($user, $request, $profile, $plan);

        $warning = null;
        if (($request['mode'] ?? 'ia') === 'ia' && $this->iaDisponible($user)) {
            try {
                $candidates = $this->context->candidates($request);
                $catalog = $this->context->catalog($request, $candidates);
                $raw = $this->client->generate($context, $this->promptRequest($request), $catalog);

                return $this->decorate($this->schema->normalize($raw, $request, $catalog, $weight, $this->llmModel()), $request);
            } catch (Throwable $e) {
                Log::warning('Coach IA indisponible : repli sur les règles Mavi’oh.', [
                    'exception' => get_class($e),
                    'message' => mb_substr($e->getMessage(), 0, 200),
                ]);
                $warning = self::WARNING_FALLBACK;
            }
        }

        $proposal = $this->rules->generate($request, $weight, $context);
        if ($warning !== null) {
            array_unshift($proposal['warnings'], $warning);
        }

        return $this->decorate($proposal, $request);
    }

    /**
     * Sous-ensemble de la demande transmis au LLM (sans identifiants internes).
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function promptRequest(array $request): array
    {
        return [
            'objectif' => $request['goal'],
            'niveau' => $request['level'],
            'duree_min' => $request['duration_min'],
            'type_sport' => $request['sport_type'],
            'sport' => $request['sport_name'],
            'lieu' => $request['lieu'],
            'materiel' => $request['equipment'],
            'focus' => $request['focus'],
            'zones_a_eviter' => $request['zones_a_eviter'],
            'notes' => $request['notes'],
            'heure_prevue' => $request['planned_at'],
        ];
    }

    /**
     * Ajoute les clés de liaison (plan, sport) utiles à `POST /sport/sessions`.
     *
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function decorate(array $proposal, array $request): array
    {
        $proposal['sport_id'] = $request['sport_id'];
        $proposal['sport_name'] = $request['sport_name'] ?? SportVocab::SPORT_TYPES[$proposal['sport_type']] ?? null;
        $proposal['sport_plan_id'] = $request['sport_plan_id'];
        $proposal['date'] = $request['date'];
        $proposal['planned_at'] = $request['planned_at'];

        return $proposal;
    }
}
