<?php

namespace App\Contracts;

use App\Exceptions\LlmUnavailableException;

/**
 * Client LLM pour la génération de séances et la planification hebdomadaire (addendum §C.4).
 *
 * Implémentations : App\Services\Llm\AnthropicWorkoutClient (production),
 * App\Services\Llm\NullWorkoutClient (clé absente), Tests\Support\FakeLlmWorkoutClient (tests).
 */
interface LlmWorkoutClient
{
    /**
     * @param  array<string, mixed>  $context  Contexte utilisateur anonymisé (WorkoutContextBuilder::build)
     * @param  array<string, mixed>  $request  Demande (objectif, niveau, durée, lieu, matériel, focus, zones à éviter, notes…)
     * @param  array<int, array<string, mixed>>  $catalog  Exercices candidats (≤ 60) {exercise_id, name, category, muscle_group, equipment, level, met}
     * @return array<string, mixed>  Proposition brute décodée du JSON (validée ensuite par WorkoutProposalSchema::normalize)
     *
     * @throws LlmUnavailableException quand la génération est impossible (clé absente, quota, refus, réseau)
     */
    public function generate(array $context, array $request, array $catalog): array;

    /**
     * @param  array<string, mixed>  $context  Contexte utilisateur anonymisé
     * @param  array<string, mixed>  $request  {week_start, jours[], objectif, niveau, temps_dispo_min, lieu, sports_disponibles[]}
     * @return array<string, mixed>  {days:[{weekday, sport, duration_min, focus, lieu, note}], explication[]} brut
     *
     * @throws LlmUnavailableException
     */
    public function generateWeekPlan(array $context, array $request): array;
}
