<?php

namespace App\Http\Requests\Sport;

use App\Enums\Equipment;
use App\Services\Sport\SportVocab;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Base des requêtes du module M8 (Sport) : autorisation, règles partagées (sport visible,
 * vocabulaires matériel / focus / zones) et libellés français communs.
 */
abstract class SportFormRequest extends FormRequest
{
    /** Bornes de durée (addendum §C.2/§C.3 pour le calendrier, §C.4 pour la génération). */
    public const DUREE_MIN = 5;

    public const DUREE_MAX = 600;

    public const GENERATION_MIN = 10;

    public const GENERATION_MAX = 180;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Le sport doit exister ET être visible : catalogue public ou sport personnalisé de l'utilisateur.
     */
    protected function visibleSportRule(): Exists
    {
        $userId = (int) ($this->user()?->id ?? 0);

        return Rule::exists('sports', 'id')->where(function (Builder $query) use ($userId) {
            $query->where(function (Builder $inner) use ($userId) {
                $inner->where('is_public', true)->orWhere('created_by_user_id', $userId);
            });
        });
    }

    /**
     * @return array<int, mixed>
     */
    protected function sportIdRules(): array
    {
        return ['nullable', 'integer', $this->visibleSportRule()];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function vocabularyRules(): array
    {
        return [
            'equipment' => ['nullable', 'array', 'max:12'],
            'equipment.*' => ['string', Rule::in(Equipment::values())],
            'focus' => ['nullable', 'array', 'max:8'],
            'focus.*' => ['string', Rule::in(SportVocab::focusValues())],
            'zones_a_eviter' => ['nullable', 'array', 'max:8'],
            'zones_a_eviter.*' => ['string', Rule::in(array_keys(SportVocab::ZONES))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'date',
            'start_date' => 'date de début',
            'week_start' => 'début de semaine',
            'from' => 'date de début',
            'to' => 'date de fin',
            'name' => 'nom',
            'category' => 'catégorie',
            'met_moderee' => 'MET modéré',
            'met_faible' => 'MET faible',
            'met_elevee' => 'MET élevé',
            'icon' => 'icône',
            'sport_id' => 'sport',
            'sport_name' => 'nom du sport',
            'sport_plan_id' => 'séance planifiée',
            'planned_duration_min' => 'durée prévue',
            'planned_at' => 'heure prévue',
            'duration_min' => 'durée',
            'lieu' => 'lieu',
            'notes' => 'notes',
            'status' => 'statut',
            'weekday' => 'jour de la semaine',
            'weeks' => 'nombre de semaines',
            'days' => 'jours',
            'days.*' => 'jour',
            'mode' => 'mode',
            'replace' => 'remplacement',
            'intensity' => 'intensité',
            'distance_km' => 'distance',
            'calories_burned' => 'calories brûlées',
            'rpe' => 'ressenti d’effort',
            'met' => 'MET',
            'goal' => 'objectif',
            'level' => 'niveau',
            'sport_type' => 'type de sport',
            'equipment' => 'matériel',
            'equipment.*' => 'matériel',
            'focus' => 'focus',
            'focus.*' => 'focus',
            'zones_a_eviter' => 'zones à éviter',
            'zones_a_eviter.*' => 'zone à éviter',
            'seed' => 'graine aléatoire',
            'title' => 'titre',
            'kind' => 'type de séance',
            'source' => 'origine',
            'exercises' => 'exercices',
            'blocks' => 'blocs',
            'q' => 'recherche',
            'muscle' => 'groupe musculaire',
            'per_page' => 'éléments par page',
            'page' => 'page',
        ];
    }
}
